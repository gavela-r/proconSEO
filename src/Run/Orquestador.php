<?php

declare(strict_types=1);

namespace ProconSEO\Run;

use Closure;
use ProconSEO\Engine\Extraccion\Robots\AnalizadorRobots;
use ProconSEO\Fetch\Ambito;
use ProconSEO\Fetch\ClienteHttpSeguro;
use ProconSEO\Fetch\DestinoRechazado;
use ProconSEO\Fetch\LimitesDescarga;
use ProconSEO\Fetch\PageSpeed\ClientePageSpeed;
use ProconSEO\Fetch\ValidadorDestino;
use ProconSEO\Shared\Contract\ConsolidadorSitio;
use ProconSEO\Shared\Contract\ContextoAnalisis;
use ProconSEO\Shared\Contract\InformePageSpeed;
use ProconSEO\Shared\Contract\MotorAnalisis;
use ProconSEO\Shared\Contract\RecursoDescargado;
use ProconSEO\Shared\Contract\SalidaMotor;
use ProconSEO\Shared\Contract\SenalSeguridad;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\Cobertura;
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
    /** Token con el que nos identificamos ante robots.txt (el user-agent empieza por él). */
    private const AGENTE = 'proconSEO';

    /**
     * @param Closure(Ambito, LimitesDescarga): ClienteHttpSeguro $fabricaCliente
     */
    public function __construct(
        private readonly RepositorioAnalisis $repositorio,
        private readonly GuardadoResultados $guardado,
        private readonly MotorAnalisis $motor,
        private readonly ValidadorDestino $validador,
        private readonly Closure $fabricaCliente,
        private readonly ?ClientePageSpeed $pageSpeed = null,
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
        $inicio = microtime(true);

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
            'pagespeed' => $this->pageSpeed?->configurado() ? 'Google PageSpeed Insights' : 'sin clave configurada',
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
            $robots = $cliente->obtener($origen . '/robots.txt', 'robots.txt del origen', 'IA-01');
            $pagina = $cliente->obtener($url, 'página analizada');
            $this->registrarBloqueos($id, $cliente);
            $informesPageSpeed = $this->pedirPageSpeed($id, $pagina, $this->repositorio->parametrosPerfil((int) $analisis['perfil_id']), $progreso);
            $this->terminar($id, Fase::Recuperacion, $pagina->esCorrecto() ? EstadoFase::Terminada : EstadoFase::Parcial, $this->resumenRecuperacion($pagina));

            if ($progreso->estaCancelado()) {
                return $this->cerrarCancelado($id, $cliente);
            }

            $alcance = Alcance::from((string) $analisis['alcance']);
            $perfil = $this->repositorio->parametrosPerfil((int) $analisis['perfil_id']);
            $limitesRastreo = LimitesRastreo::desdePerfil($perfil);
            // Lo que pida el motor pasa por aquí: memoria compartida y tope de URLs extra del alcance.
            $proveedor = new ProveedorAcotado(
                $cliente,
                match ($alcance) {
                    Alcance::UrlIndividual => null, // el propio motor no pide otras URLs en este alcance
                    Alcance::UrlContexto => $limitesRastreo->maxUrlsContexto,
                    Alcance::SitioCompleto => $limitesRastreo->maxUrlsContexto + LimitesRastreo::MAX_SITEMAPS, // por página; los sitemaps se leen en la primera
                },
                $alcance->etiqueta(),
            );
            $proveedor->recordar($robots);
            $proveedor->recordar($pagina);
            // Lo que pidan las comprobaciones también respeta robots.txt (la página indicada se analiza igualmente).
            if ($robots->esCorrecto()) {
                $proveedor->respetarRobots((new AnalizadorRobots())->analizar($robots->cuerpo),
                    (string) preg_replace('#^([a-z]+://[^/?\#]+).*$#i', '$1', $robots->urlFinal), self::AGENTE);
            }

            $this->terminar($id, Fase::Renderizado, EstadoFase::Omitida, 'Sin navegador en esta versión: lo que depende del renderizado queda no verificable.');

            // F5–F7 — Extracción, comprobaciones y consolidación las hace el motor (Parte 2).
            $this->empezar($id, Fase::Extraccion);
            $proyecto = $this->repositorio->proyecto((int) $analisis['proyecto_id']);
            $contexto = fn (RecursoDescargado $p, array $pageSpeed = []): ContextoAnalisis => new ContextoAnalisis(
                idAnalisis: $id,
                alcance: $alcance,
                paginaPrincipal: $p,
                hostsAutorizados: $ambito->hosts(),
                objetivo: $analisis['objetivo'],
                tipoProyecto: TipoProyecto::tryFrom((string) ($proyecto['tipo'] ?? '')),
                sector: Sector::tryFrom((string) ($proyecto['sector'] ?? '')),
                perfil: $perfil,
                pageSpeed: $pageSpeed,
            );
            $salidas = [$this->motor->ejecutar($contexto($pagina, $informesPageSpeed), $proveedor, $progreso, $progreso)];
            $this->registrarBloqueos($id, $cliente);
            $cancelado = $progreso->estaCancelado();
            $limiteAlcanzado = null;
            $salidaSitio = null;

            // F3 — Descubrimiento. En el sitio completo se recorren las páginas y el motor analiza cada una.
            if ($alcance === Alcance::UrlIndividual) {
                $this->terminar($id, Fase::Descubrimiento, EstadoFase::Omitida, 'No aplica a una URL individual.');
            } elseif ($alcance === Alcance::UrlContexto) {
                $this->terminar($id, Fase::Descubrimiento, EstadoFase::Terminada, "Se consultaron {$proveedor->urlsExtra()} URLs relacionadas que pedían las comprobaciones (máximo {$limitesRastreo->maxUrlsContexto}).");
            } elseif ($cancelado) {
                $this->terminar($id, Fase::Descubrimiento, EstadoFase::Omitida, 'El análisis se canceló antes de recorrer el sitio.');
            } else {
                [$salidasSitio, $cancelado, $limiteAlcanzado] = $this->recorrerSitio($id, $cliente, $proveedor, $robots, $pagina, $limites, $limitesRastreo, $ambito, $progreso, $contexto, $inicio);
                array_push($salidas, ...$salidasSitio);
                if ($this->motor instanceof ConsolidadorSitio) {
                    [$salidas, $salidaSitio] = $this->consolidarSitio($this->motor, $contexto($pagina), $salidas, $progreso);
                }
            }

            $todas = $salidaSitio === null ? $salidas : [...$salidas, $salidaSitio];
            $resultados = array_sum(array_map(static fn (SalidaMotor $s): int => count($s->resultados), $todas));
            $causas = array_sum(array_map(static fn (SalidaMotor $s): int => count($s->gruposCausa), $todas));
            $estadoMotor = $cancelado ? EstadoFase::Parcial : EstadoFase::Terminada;
            $this->terminar($id, Fase::Extraccion, $estadoMotor);
            $this->terminar($id, Fase::Comprobaciones, $estadoMotor, $resultados . ' resultados' . (count($salidas) > 1 ? ' en ' . count($salidas) . ' páginas.' : '.'));
            $this->terminar($id, Fase::Consolidacion, $estadoMotor, $causas . ' causas agrupadas.');

            // F8 — Cierre: guardar todo (sanitizado) y decidir el estado final.
            $this->empezar($id, Fase::Cierre);
            $this->guardarTodo($id, $cliente, $salidas, $proveedor->bloqueadas(), $salidaSitio);
            $this->terminar($id, Fase::Cierre, EstadoFase::Terminada);

            if ($cancelado) {
                return $this->cerrarYaGuardado($id, EstadoAnalisis::Cancelado, 'Cancelado por el usuario. Se conservan los resultados obtenidos.', $cliente, $pagina);
            }
            if (!$pagina->esCorrecto()) {
                return $this->cerrarYaGuardado($id, EstadoAnalisis::Parcial, 'Análisis parcial: ' . $this->resumenRecuperacion($pagina), $cliente, $pagina);
            }
            if ($limiteAlcanzado !== null) {
                return $this->cerrarYaGuardado($id, EstadoAnalisis::Parcial, 'Análisis parcial: ' . $limiteAlcanzado, $cliente, $pagina);
            }

            return $this->cerrarYaGuardado($id, EstadoAnalisis::Completado, null, $cliente, $pagina);
        } catch (Throwable $e) {
            error_log("Análisis {$id}: " . $e);

            return $this->cerrar($id, EstadoAnalisis::DetenidoError, 'Error inesperado durante el análisis. Se conservan los resultados obtenidos.', $cliente);
        }
    }

    /**
     * Mediciones de Google PageSpeed de la página analizada (SEO-11 y ACC-05), móvil y ordenador
     * según el perfil. Solo de esa página: en el sitio completo serían cientos de llamadas.
     *
     * @param array<string, mixed> $perfil
     * @return list<InformePageSpeed>
     */
    private function pedirPageSpeed(int $id, RecursoDescargado $pagina, array $perfil, ProgresoBd $progreso): array
    {
        if ($this->pageSpeed === null || !$pagina->esCorrecto()) {
            return [];
        }
        $llamadas = max(0, min(2, (int) ($perfil['apis']['pagespeed_llamadas_por_url']['valor'] ?? 2)));
        $dispositivos = array_slice([InformePageSpeed::MOVIL, InformePageSpeed::ORDENADOR], 0, $llamadas);
        if ($dispositivos === [] || $progreso->estaCancelado()) {
            return [];
        }
        if ($this->pageSpeed->configurado()) {
            $this->repositorio->actualizarFase($id, Fase::Recuperacion, EstadoFase::EnCurso, 0, 1, 'Midiendo la velocidad con Google PageSpeed (puede tardar medio minuto)');
        }

        // Móvil y ordenador a la vez: es un servicio externo, no el sitio analizado.
        return $this->pageSpeed->informes($pagina->urlFinal, $dispositivos);
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

    /**
     * @param list<SalidaMotor> $salidas    La de la página analizada y, en el sitio completo, una por página
     * @param list<array{recurso: RecursoDescargado, motivo: string, comprobacion: ?string}> $bloqueadas
     *        Lo que se pidió y no se consultó (robots.txt o tope): también queda en la lista de recursos
     * @param SalidaMotor|null $sitio Las comprobaciones de todo el sitio (sus evidencias llevan «S-» delante)
     */
    private function guardarTodo(int $id, ClienteHttpSeguro $cliente, array $salidas, array $bloqueadas = [], ?SalidaMotor $sitio = null): void
    {
        $ids = $this->guardado->guardarRecursos($id, [...$cliente->registro(), ...$bloqueadas]);
        foreach ($salidas as $i => $salida) {
            $this->guardado->guardarSalida($id, $salida, $ids, $i === 0 ? '' : "P{$i}-");
        }
        if ($sitio !== null) {
            $this->guardado->guardarSalida($id, $sitio, $ids, 'S-');
        }
    }

    /**
     * Al terminar el recorrido, el motor compara las páginas entre sí (titles repetidos, páginas
     * sin enlaces…). Esas subcomprobaciones se guardan una sola vez para todo el sitio, así que se
     * quitan de cada página (allí solo dicen «se valora para todo el sitio»).
     *
     * @param list<SalidaMotor> $salidas
     * @return array{0: list<SalidaMotor>, 1: SalidaMotor} las páginas sin esas subcomprobaciones y la del sitio
     */
    private function consolidarSitio(ConsolidadorSitio $motor, ContextoAnalisis $contexto, array $salidas, ProgresoBd $progreso): array
    {
        $deSitio = array_flip($motor->subcomprobacionesDeSitio());
        $resumenes = [];
        $paginas = [];
        foreach ($salidas as $s) {
            if ($s->resumen !== null) {
                $resumenes[] = $s->resumen;
            }
            $paginas[] = new SalidaMotor(
                array_values(array_filter($s->resultados, static fn ($r): bool => !isset($deSitio[$r->idComprobacion]))),
                $s->evidencias,
                $s->gruposCausa,
                $s->senales,
            );
        }

        return [$paginas, $motor->consolidarSitio($contexto, $resumenes, $progreso, $progreso)];
    }

    /**
     * F3 en el sitio completo: recorre las páginas y el motor analiza cada una según aparece.
     * De las páginas siguientes a la primera solo se guardan las comprobaciones implementadas:
     * las no implementadas serían la misma fila repetida en cada página.
     *
     * @param Closure(RecursoDescargado): ContextoAnalisis $contexto
     * @return array{0: list<SalidaMotor>, 1: bool, 2: ?string} salidas, cancelado y límite alcanzado
     */
    private function recorrerSitio(
        int $id,
        ClienteHttpSeguro $cliente,
        ProveedorAcotado $proveedor,
        RecursoDescargado $robots,
        RecursoDescargado $pagina,
        LimitesDescarga $limites,
        LimitesRastreo $limitesRastreo,
        Ambito $ambito,
        ProgresoBd $progreso,
        Closure $contexto,
        float $inicio,
    ): array {
        $this->empezar($id, Fase::Descubrimiento);

        // RFC 9309: sin robots.txt (4xx) se puede rastrear todo; si no se pudo leer (5xx, red) no se rastrea nada.
        $reglas = null;
        if ($robots->esCorrecto()) {
            $reglas = (new AnalizadorRobots())->analizar($robots->cuerpo);
        } elseif ($robots->estadoHttp === null || $robots->estadoHttp === 429 || $robots->estadoHttp >= 500) {
            $this->terminar($id, Fase::Descubrimiento, EstadoFase::Parcial, 'No se pudo leer robots.txt, así que no se recorre el sitio: solo se analiza la URL indicada.');

            return [[], false, 'no se pudo leer robots.txt y no se recorrió el sitio.'];
        }

        $rastreador = new Rastreador($cliente, $proveedor, $ambito->hosts(), $limitesRastreo, $reglas, self::AGENTE,
            $limites->maxBytesHtml, $limites->maxBytesSitemap);
        $progresoPagina = new ProgresoPorPagina($progreso, $limitesRastreo->maxPaginas);
        $progresoPagina->siguiente(); // la primera página ya está analizada

        $salidas = [];
        foreach ($rastreador->paginas($pagina, $reglas?->sitemaps ?? [], $progreso, $inicio + (float) $limitesRastreo->segundosSitio) as $otra) {
            $progresoPagina->siguiente();
            $this->repositorio->actualizarFase($id, Fase::Descubrimiento, EstadoFase::EnCurso, count($salidas) + 2, $limitesRastreo->maxPaginas, mb_substr('Analizando ' . $otra->urlFinal, 0, 500));

            $proveedor->nuevaPagina($otra);
            $salida = $this->motor->ejecutar($contexto($otra), $proveedor, $progresoPagina, $progreso);
            $this->registrarBloqueos($id, $cliente);

            $salidas[] = new SalidaMotor(
                array_values(array_filter($salida->resultados, static fn ($r): bool => $r->cobertura !== Cobertura::NoImplementada)),
                $salida->evidencias,
                $salida->gruposCausa,
                $salida->senales,
                $salida->resumen,
            );
            if ($progreso->estaCancelado()) {
                break;
            }
        }

        $r = $rastreador->resumen();
        $cancelado = $progreso->estaCancelado();
        $limite = match (true) {
            $r['limite_paginas'] => "se llegó al máximo de {$limitesRastreo->maxPaginas} páginas ({$r['pendientes']} sin analizar).",
            $r['tiempo_agotado'] => "se llegó al tiempo máximo del análisis ({$r['pendientes']} páginas sin analizar).",
            default => null,
        };
        $mensaje = ($r['analizadas'] + 1) . ' páginas analizadas'
            . ($r['descartadas_robots'] > 0 ? ", {$r['descartadas_robots']} no permitidas por robots.txt" : '')
            . ($r['fallidas'] > 0 ? ", {$r['fallidas']} no se pudieron descargar o no eran HTML" : '')
            . ($limite === null ? '.' : '. Parcial: ' . $limite);
        $this->terminar($id, Fase::Descubrimiento, $cancelado || $limite !== null ? EstadoFase::Parcial : EstadoFase::Terminada, $mensaje);

        return [$salidas, $cancelado, $limite];
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
