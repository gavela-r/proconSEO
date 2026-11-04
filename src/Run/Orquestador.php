<?php

declare(strict_types=1);

namespace ProconSEO\Run;

use Closure;
use ProconSEO\Fetch\Ambito;
use ProconSEO\Fetch\ClienteHttpSeguro;
use ProconSEO\Fetch\DestinoRechazado;
use ProconSEO\Fetch\LimitesDescarga;
use ProconSEO\Fetch\ValidadorDestino;
use ProconSEO\Shared\Contract\ContextoAnalisis;
use ProconSEO\Shared\Contract\MotorAnalisis;
use ProconSEO\Shared\Contract\RecursoDescargado;
use ProconSEO\Shared\Contract\SalidaMotor;
use ProconSEO\Shared\Contract\SenalSeguridad;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\DecisionCierre;
use ProconSEO\Shared\Enum\EstadoAnalisis;
use ProconSEO\Shared\Enum\EstadoFase;
use ProconSEO\Shared\Enum\Fase;
use ProconSEO\Shared\Enum\ResultadoDescarga;
use ProconSEO\Shared\Enum\Sector;
use ProconSEO\Shared\Enum\TipoProyecto;
use Throwable;

/**
 * Ejecuta un análisis reservado por el worker, fase a fase (Anexo A.1):
 * validación → recuperación → descubrimiento → renderizado → extracción → comprobaciones
 * → consolidación → cierre.
 *
 * - Guarda el progreso real de cada fase.
 * - Si se cancela, deja de lanzar trabajo y conserva lo ya obtenido (estado cancelado).
 * - Si la página no se puede recuperar, sigue y el motor marca lo dependiente como
 *   no_verificable (estado parcial).
 * - Un destino prohibido detiene el análisis por seguridad; un error inesperado, por error.
 */
final class Orquestador
{
    /**
     * @param Closure(Ambito, LimitesDescarga): ClienteHttpSeguro $fabricaCliente
     */
    public function __construct(
        private readonly RepositorioAnalisis $repositorio,
        private readonly GuardadoResultados $guardado,
        private readonly MotorAnalisis $motor,
        private readonly ValidadorDestino $validador,
        private readonly Closure $fabricaCliente,
    ) {
    }

    public function ejecutar(int $id): EstadoAnalisis
    {
        $analisis = $this->repositorio->obtener($id) ?? throw new \RuntimeException("No existe el análisis {$id}");
        $limites = LimitesDescarga::desdePerfil($this->repositorio->parametrosPerfil((int) $analisis['perfil_id']));
        $hosts = json_decode((string) $analisis['hosts_autorizados'], true) ?: [];
        $ambito = new Ambito($hosts);
        $progreso = new ProgresoBd($this->repositorio, $id);
        $cliente = ($this->fabricaCliente)($ambito, $limites);
        $url = (string) $analisis['url_normalizada'];

        $this->repositorio->guardarConfiguracion($id, [
            'hosts_autorizados' => $ambito->hosts(),
            'alcance' => $analisis['alcance'],
            'user_agent' => $limites->userAgent,
            'idioma' => $limites->idioma,
            'puertos' => $limites->puertosPermitidos,
            'max_bytes_html' => $limites->maxBytesHtml,
            'max_bytes_analisis' => $limites->maxBytesAnalisis,
            'renderizado' => 'no disponible en esta versión',
            'motor' => $this->motor::class,
        ]);

        try {
            // F1 — Validación: se repite al empezar porque el DNS puede haber cambiado desde que se encoló.
            $this->empezar($id, Fase::Validacion);
            try {
                $this->validador->validar($url);
            } catch (DestinoRechazado $e) {
                $this->guardado->evento($id, Fase::Validacion, new SenalSeguridad($e->regla, $e->getMessage(), DecisionCierre::Detener, $url));
                $this->terminar($id, Fase::Validacion, EstadoFase::Fallida, $e->getMessage());

                return $this->cerrar($id, EstadoAnalisis::DetenidoSeguridad, $e->getMessage(), $cliente);
            }
            $this->terminar($id, Fase::Validacion, EstadoFase::Terminada, 'URL y destino permitidos.');

            if ($progreso->estaCancelado()) {
                return $this->cerrarCancelado($id, $cliente);
            }

            // F2 — Recuperación: robots.txt del origen y página analizada.
            $this->empezar($id, Fase::Recuperacion);
            $origen = (string) preg_replace('#^([a-z]+://[^/]+).*$#i', '$1', $url);
            $cliente->obtener($origen . '/robots.txt', 'robots.txt del origen', 'IA-01');
            $pagina = $cliente->obtener($url, 'página analizada');
            $this->registrarBloqueos($id, $cliente);
            $this->terminar($id, Fase::Recuperacion, $pagina->esCorrecto() ? EstadoFase::Terminada : EstadoFase::Parcial, $this->resumenRecuperacion($pagina));

            if ($progreso->estaCancelado()) {
                return $this->cerrarCancelado($id, $cliente);
            }

            // F3 y F4 — todavía no disponibles en esta versión.
            $this->terminar($id, Fase::Descubrimiento, EstadoFase::Omitida, $analisis['alcance'] === Alcance::UrlIndividual->value
                ? 'No aplica a una URL individual.'
                : 'El descubrimiento de URLs relacionadas aún no está disponible: se analiza solo la URL indicada.');
            $this->terminar($id, Fase::Renderizado, EstadoFase::Omitida, 'Sin navegador en esta versión: lo que depende del renderizado queda no verificable.');

            // F5–F7 — Extracción, comprobaciones y consolidación las hace el motor (Parte 2).
            $this->empezar($id, Fase::Extraccion);
            $proyecto = $this->repositorio->proyecto((int) $analisis['proyecto_id']);
            $contexto = new ContextoAnalisis(
                idAnalisis: $id,
                alcance: Alcance::from((string) $analisis['alcance']),
                paginaPrincipal: $pagina,
                hostsAutorizados: $ambito->hosts(),
                objetivo: $analisis['objetivo'],
                tipoProyecto: TipoProyecto::tryFrom((string) ($proyecto['tipo'] ?? '')),
                sector: Sector::tryFrom((string) ($proyecto['sector'] ?? '')),
                perfil: $this->repositorio->parametrosPerfil((int) $analisis['perfil_id']),
            );
            $salida = $this->motor->ejecutar($contexto, $cliente, $progreso, $progreso);
            $this->registrarBloqueos($id, $cliente);
            $cancelado = $progreso->estaCancelado();

            $estadoMotor = $cancelado ? EstadoFase::Parcial : EstadoFase::Terminada;
            $this->terminar($id, Fase::Extraccion, $estadoMotor);
            $this->terminar($id, Fase::Comprobaciones, $estadoMotor, count($salida->resultados) . ' resultados.');
            $this->terminar($id, Fase::Consolidacion, $estadoMotor, count($salida->gruposCausa) . ' causas agrupadas.');

            // F8 — Cierre: guardar todo (sanitizado) y decidir el estado final.
            $this->empezar($id, Fase::Cierre);
            $this->guardarTodo($id, $cliente, $salida);
            $this->terminar($id, Fase::Cierre, EstadoFase::Terminada);

            if ($cancelado) {
                return $this->cerrarYaGuardado($id, EstadoAnalisis::Cancelado, 'Cancelado por el usuario. Se conservan los resultados obtenidos.', $cliente, $pagina);
            }
            if (!$pagina->esCorrecto()) {
                return $this->cerrarYaGuardado($id, EstadoAnalisis::Parcial, 'Análisis parcial: ' . $this->resumenRecuperacion($pagina), $cliente, $pagina);
            }

            return $this->cerrarYaGuardado($id, EstadoAnalisis::Completado, null, $cliente, $pagina);
        } catch (Throwable $e) {
            error_log("Análisis {$id}: " . $e);

            return $this->cerrar($id, EstadoAnalisis::DetenidoError, 'Error inesperado durante el análisis. Se conservan los resultados obtenidos.', $cliente);
        }
    }

    private function empezar(int $id, Fase $fase): void
    {
        $this->repositorio->marcarFase($id, $fase);
        $this->repositorio->actualizarFase($id, $fase, EstadoFase::EnCurso);
    }

    private function terminar(int $id, Fase $fase, EstadoFase $estado, string $mensaje = ''): void
    {
        $this->repositorio->actualizarFase($id, $fase, $estado, 0, 0, $mensaje);
    }

    private function cerrarCancelado(int $id, ClienteHttpSeguro $cliente): EstadoAnalisis
    {
        return $this->cerrar($id, EstadoAnalisis::Cancelado, 'Cancelado por el usuario. Se conservan los resultados obtenidos.', $cliente);
    }

    /** Cierra guardando lo que se haya descargado hasta ahora (sin resultados del motor). */
    private function cerrar(int $id, EstadoAnalisis $estado, ?string $motivo, ClienteHttpSeguro $cliente): EstadoAnalisis
    {
        try {
            $this->guardado->guardarRecursos($id, $cliente->registro());
        } catch (Throwable $e) {
            error_log("Análisis {$id}: no se pudieron guardar los recursos: " . $e->getMessage());
        }
        $this->repositorio->cerrarFasesPendientes($id, 'No se ejecutó: el análisis terminó antes.');
        $this->repositorio->cerrar($id, $estado, $motivo, null, ['bytes' => $cliente->bytesConsumidos(), 'peticiones' => count($cliente->registro())]);

        return $estado;
    }

    private function cerrarYaGuardado(int $id, EstadoAnalisis $estado, ?string $motivo, ClienteHttpSeguro $cliente, RecursoDescargado $pagina): EstadoAnalisis
    {
        $this->repositorio->cerrarFasesPendientes($id, 'No se ejecutó: el análisis terminó antes.');
        $this->repositorio->cerrar($id, $estado, $motivo, $pagina->urlFinal, ['bytes' => $cliente->bytesConsumidos(), 'peticiones' => count($cliente->registro())]);

        return $estado;
    }

    private function guardarTodo(int $id, ClienteHttpSeguro $cliente, SalidaMotor $salida): void
    {
        $ids = $this->guardado->guardarRecursos($id, $cliente->registro());
        $this->guardado->guardarSalida($id, $salida, $ids);
    }

    /** @var array<int, true> índices del registro ya anotados como evento */
    private array $bloqueosAnotados = [];

    private function registrarBloqueos(int $id, ClienteHttpSeguro $cliente): void
    {
        foreach ($cliente->registro() as $i => $entrada) {
            $r = $entrada['recurso'];
            if (isset($this->bloqueosAnotados[$i]) || !in_array($r->resultado, [ResultadoDescarga::BloqueadoSeguridad, ResultadoDescarga::ErrorTls, ResultadoDescarga::DemasiadoGrande], true)) {
                continue;
            }
            $this->bloqueosAnotados[$i] = true;
            $regla = match ($r->resultado) {
                ResultadoDescarga::ErrorTls => 'SEG-03',
                ResultadoDescarga::DemasiadoGrande => 'SEG-05',
                default => 'SEG-02',
            };
            $this->guardado->evento($id, Fase::Recuperacion, new SenalSeguridad($regla, (string) $r->motivo, DecisionCierre::Limitar, $r->urlFinal));
        }
    }

    private function resumenRecuperacion(RecursoDescargado $pagina): string
    {
        return match ($pagina->resultado) {
            ResultadoDescarga::Correcto => "Página recuperada (HTTP {$pagina->estadoHttp}).",
            ResultadoDescarga::ErrorHttp => "La página respondió con HTTP {$pagina->estadoHttp}." . ($pagina->motivo ? ' ' . $pagina->motivo : ''),
            default => $pagina->resultado->etiqueta() . ($pagina->motivo ? ': ' . $pagina->motivo : '.'),
        };
    }
}
