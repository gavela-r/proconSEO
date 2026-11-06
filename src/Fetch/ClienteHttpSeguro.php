<?php

declare(strict_types=1);

namespace ProconSEO\Fetch;

use DateTimeImmutable;
use Closure;
use Fiber;
use ProconSEO\Fetch\Http\PeticionHttp;
use ProconSEO\Fetch\Http\RespuestaHttp;
use ProconSEO\Fetch\Http\TransporteHttp;
use ProconSEO\Shared\Contract\ProveedorRecursos;
use ProconSEO\Shared\Contract\RecursoDescargado;
use ProconSEO\Shared\Enum\ResultadoDescarga;

/**
 * Única puerta de acceso a la red del analizador (implementa ProveedorRecursos).
 *
 * Para cada petición:
 * - valida la URL y la IP real ANTES de conectar (SEG-01/02), también en cada redirección
 *   y en cada reintento;
 * - no sale de los hosts autorizados del análisis (Guía 0.1);
 * - verifica TLS y aplica los límites de tamaño, tiempo, redirecciones y reintentos (SEG-03/05);
 * - respeta el ritmo por host y las respuestas 429 sin cambiar de identidad (Anexo A.2);
 * - nunca lanza excepciones por fallos de red o de seguridad: los devuelve en el resultado.
 *
 * obtenerVarias() descarga varias URLs a la vez con exactamente las mismas reglas: cada descarga
 * sigue el mismo código que obtener() y solo se agrupa el momento de enviar (y de esperar).
 */
final class ClienteHttpSeguro implements ProveedorRecursos
{
    /** Cabeceras de respuesta que no se guardan nunca (SEG-09). */
    private const CABECERAS_DESCARTADAS = ['set-cookie', 'set-cookie2'];

    private int $bytesConsumidos = 0;

    /** @var array<string, list<float>> host => instantes de inicio de las últimas peticiones */
    private array $iniciosPorHost = [];

    /** Si se está dentro de obtenerVarias(): enviar y esperar se ceden al planificador. */
    private bool $enParalelo = false;

    /** @var (Closure(string): ?RecursoDescargado)|null Lo ya descargado en este análisis */
    private ?Closure $memoria = null;

    /** @var list<array{recurso: RecursoDescargado, motivo: string, comprobacion: ?string}> */
    private array $registro = [];

    public function __construct(
        private readonly TransporteHttp $transporte,
        private readonly ValidadorDestino $validador,
        private readonly Ambito $ambito,
        private readonly LimitesDescarga $limites = new LimitesDescarga(),
        private readonly Reloj $reloj = new RelojSistema(),
    ) {
    }

    public function obtener(string $url, string $motivo, ?string $idComprobacion = null): RecursoDescargado
    {
        return $this->descargar($url, $motivo, $idComprobacion, $this->limitePara($url));
    }

    /**
     * Varias descargas a la vez. Cada una pasa por las mismas validaciones, redirecciones,
     * reintentos y límites que obtener(); el ritmo por host se respeta igual (como mucho N
     * peticiones empiezan en cada segundo). El orden del resultado es el de la petición.
     *
     * @param list<array{0: string, 1: string, 2?: ?string}> $peticiones URL, motivo y comprobación
     * @return list<RecursoDescargado>
     */
    public function obtenerVarias(array $peticiones): array
    {
        if ($this->enParalelo) {
            return array_map(fn (array $p): RecursoDescargado => $this->obtener($p[0], $p[1], $p[2] ?? null), $peticiones);
        }

        /** @var array<int, Fiber> $fibras */
        $fibras = [];
        foreach ($peticiones as $i => $p) {
            $fibras[$i] = new Fiber(fn (): RecursoDescargado => $this->obtener($p[0], $p[1], $p[2] ?? null));
        }
        /** @var array<int, RecursoDescargado> $resultados */
        $resultados = [];
        /** @var array<int, array{0: string, 1: PeticionHttp|float}> $pendientes */
        $pendientes = [];
        $avanzar = static function (int $i, mixed $valor, bool $empezar) use ($fibras, &$pendientes, &$resultados): void {
            $fibra = $fibras[$i];
            /** @var array{0: string, 1: PeticionHttp|float}|null $cedido */
            $cedido = $empezar ? $fibra->start() : $fibra->resume($valor);
            if ($fibra->isTerminated()) {
                /** @var RecursoDescargado $recurso */
                $recurso = $fibra->getReturn();
                $resultados[$i] = $recurso;
                unset($pendientes[$i]);
            } elseif ($cedido !== null) {
                $pendientes[$i] = $cedido;
            }
        };

        $this->enParalelo = true;
        try {
            foreach (array_keys($fibras) as $i) {
                $avanzar($i, null, true);
            }
            while ($pendientes !== []) {
                $envios = [];
                $esperas = [];
                foreach ($pendientes as $i => $p) {
                    if ($p[1] instanceof PeticionHttp) {
                        $envios[$i] = $p[1];
                    } else {
                        $esperas[$i] = $p[1];
                    }
                }
                if ($envios !== []) {
                    $respuestas = $this->transporte->enviarVarias(array_values($envios));
                    foreach (array_keys($envios) as $k => $i) {
                        $avanzar($i, $respuestas[$k], false);
                    }
                    continue;
                }
                // Nadie listo para enviar: se espera hasta el primero que lo esté.
                $hasta = min($esperas);
                $this->reloj->esperar($hasta - $this->reloj->ahora());
                foreach ($esperas as $i => $cuando) {
                    if ($cuando <= $hasta) {
                        $avanzar($i, null, false);
                    }
                }
            }
        } finally {
            $this->enParalelo = false;
        }
        ksort($resultados);

        return array_values($resultados);
    }

    /**
     * Si una redirección lleva a una URL ya descargada en este análisis, se usa esa descarga en
     * vez de volver a pedirla. La redirección se valida igual; solo se ahorra la última petición.
     *
     * @param Closure(string): ?RecursoDescargado $memoria
     */
    public function usarMemoria(Closure $memoria): void
    {
        $this->memoria = $memoria;
    }

    public function descargar(string $url, string $motivo, ?string $idComprobacion, int $maxBytes): RecursoDescargado
    {
        $recurso = $this->seguirRedirecciones($url, $maxBytes);
        // El registro guarda los datos de cada descarga, no su contenido: en un sitio completo
        // serían cientos de páginas en memoria.
        $this->registro[] = ['recurso' => self::sinCuerpo($recurso), 'motivo' => $motivo, 'comprobacion' => $idComprobacion];

        return $recurso;
    }

    private static function sinCuerpo(RecursoDescargado $r): RecursoDescargado
    {
        return $r->cuerpo === '' ? $r : new RecursoDescargado($r->urlSolicitada, $r->urlFinal, $r->resultado, $r->estadoHttp, $r->redirecciones,
            $r->cabeceras, $r->tipoContenido, '', $r->truncado, $r->bytes, $r->tlsVerificado, $r->motivo, $r->descargadoEn, $r->idRecurso);
    }

    /**
     * Todo lo consultado en este análisis, incluidos los bloqueos. La Parte 1 lo guarda en
     * la tabla recursos (Anexo A.2: registrar recursos bloqueados o truncados).
     *
     * @return list<array{recurso: RecursoDescargado, motivo: string, comprobacion: ?string}>
     */
    public function registro(): array
    {
        return $this->registro;
    }

    public function bytesConsumidos(): int
    {
        return $this->bytesConsumidos;
    }

    private function seguirRedirecciones(string $urlInicial, int $maxBytes): RecursoDescargado
    {
        $url = $urlInicial;
        $redirecciones = [];
        $visitadas = [];

        while (true) {
            $resultado = $this->pedirConReintentos($url, $maxBytes);
            if ($resultado instanceof RecursoDescargado) {
                return $this->conHistorial($resultado, $urlInicial, $redirecciones);
            }

            [$destino, $respuesta] = $resultado;
            $urlActual = $destino->url->url();
            $visitadas[] = $urlActual;

            if (!$respuesta->esRedireccion()) {
                return $this->recursoDeRespuesta($urlInicial, $destino, $respuesta, $redirecciones);
            }

            $siguiente = $this->resolverRelativa($destino->url, (string) $respuesta->cabecera('location'));
            $redirecciones[] = ['url' => $urlActual, 'estado' => $respuesta->estado, 'destino' => $siguiente];

            if (count($redirecciones) > $this->limites->maxRedirecciones) {
                return $this->fallo($urlInicial, $urlActual, ResultadoDescarga::ErrorHttp, $redirecciones,
                    "Más de {$this->limites->maxRedirecciones} redirecciones seguidas.", $respuesta->estado);
            }

            try {
                $normalizada = (new ValidadorUrl($this->limites->puertosPermitidos))->validar($siguiente)->url();
            } catch (DestinoRechazado $e) {
                return $this->fallo($urlInicial, $siguiente, ResultadoDescarga::BloqueadoSeguridad, $redirecciones,
                    'Redirección a una dirección no permitida: ' . $e->getMessage(), $respuesta->estado);
            }

            if (in_array($normalizada, $visitadas, true)) {
                return $this->fallo($urlInicial, $normalizada, ResultadoDescarga::ErrorHttp, $redirecciones,
                    'Bucle de redirecciones.', $respuesta->estado);
            }

            $conocida = $this->memoria === null ? null : $this->yaDescargada($normalizada);
            if ($conocida !== null && $conocida->estadoHttp !== null
                && count($redirecciones) + count($conocida->redirecciones) <= $this->limites->maxRedirecciones) {
                return new RecursoDescargado($urlInicial, $conocida->urlFinal, $conocida->resultado, $conocida->estadoHttp,
                    [...$redirecciones, ...$conocida->redirecciones], $conocida->cabeceras, $conocida->tipoContenido, $conocida->cuerpo,
                    $conocida->truncado, $conocida->bytes, $conocida->tlsVerificado, $conocida->motivo, $conocida->descargadoEn);
            }

            $url = $normalizada;
        }
    }

    /**
     * Valida, comprueba ámbito y presupuesto, y hace la petición con reintentos.
     *
     * @return RecursoDescargado|array{0: DestinoValidado, 1: RespuestaHttp} Un fallo definitivo, o la respuesta
     */
    private function pedirConReintentos(string $url, int $maxBytes): RecursoDescargado|array
    {
        $intento = 0;

        while (true) {
            // Se valida en cada intento: el DNS puede haber cambiado (rebinding).
            try {
                $destino = $this->validador->validar($url);
            } catch (DestinoRechazado $e) {
                return $this->fallo($url, $url, ResultadoDescarga::BloqueadoSeguridad, [], $e->getMessage());
            }

            if (!$this->ambito->permite($destino->url)) {
                return $this->fallo($url, $destino->url->url(), ResultadoDescarga::FueraDeAmbito, [],
                    "{$destino->url->host} no está entre los hosts autorizados del análisis.");
            }

            $disponible = $this->limites->maxBytesAnalisis - $this->bytesConsumidos;
            if ($disponible <= 0) {
                return $this->fallo($url, $destino->url->url(), ResultadoDescarga::DemasiadoGrande, [],
                    'Se ha agotado el presupuesto de descarga del análisis.');
            }

            $this->respetarRitmo($destino->url->host);
            $respuesta = $this->enviar(new PeticionHttp(
                $destino,
                min($maxBytes, $disponible),
                $this->limites->segundosConexion,
                $this->limites->segundosPeticion,
                $this->limites->userAgent,
                $this->limites->idioma,
            ));
            $this->bytesConsumidos += strlen($respuesta->cuerpo);

            $quedanReintentos = $intento < $this->limites->reintentos;

            if ($respuesta->error === RespuestaHttp::ERROR_TLS) {
                return $this->fallo($url, $destino->url->url(), ResultadoDescarga::ErrorTls, [],
                    'El certificado de seguridad (TLS) no es válido: ' . $respuesta->detalleError);
            }

            if ($respuesta->error !== null) {
                if ($quedanReintentos) {
                    $this->esperar($this->espera($intento));
                    ++$intento;
                    continue;
                }
                $tipo = $respuesta->error === RespuestaHttp::ERROR_TIEMPO ? ResultadoDescarga::TiempoAgotado : ResultadoDescarga::ErrorRed;

                return $this->fallo($url, $destino->url->url(), $tipo, [], (string) $respuesta->detalleError);
            }

            if ($respuesta->estado === 429) {
                $retryAfter = $this->segundosRetryAfter($respuesta->cabecera('retry-after'));
                $espera = $retryAfter ?? $this->espera($intento);
                if (!$quedanReintentos || $espera > $this->limites->maxEsperaRetryAfter) {
                    return $this->fallo($url, $destino->url->url(), ResultadoDescarga::Limitado429, [],
                        'El servidor ha limitado las peticiones (429).' . ($retryAfter !== null ? " Pide esperar {$retryAfter} s." : ''), 429);
                }
                $this->esperar($espera);
                ++$intento;
                continue;
            }

            if (in_array($respuesta->estado, [500, 502, 503, 504], true) && $quedanReintentos) {
                $this->esperar($this->espera($intento));
                ++$intento;
                continue;
            }

            return [$destino, $respuesta];
        }
    }

    /**
     * Con R peticiones por segundo, como mucho N = R (redondeado hacia abajo, mínimo 1) empiezan
     * en cada ventana de N/R segundos. Con R = 1 es una cada segundo; con R = 3, hasta 3 a la vez y
     * la cuarta un segundo después de la primera. El hueco se reserva antes de esperar, para que
     * las descargas en paralelo no lo compartan.
     */
    private function respetarRitmo(string $host): void
    {
        $ritmo = $this->limites->peticionesPorSegundoHost;
        if ($ritmo <= 0) {
            return;
        }
        $n = max(1, (int) floor($ritmo));
        $inicios = $this->iniciosPorHost[$host] ?? [];
        $inicio = $this->reloj->ahora();
        if (count($inicios) >= $n) {
            // Solo se guardan las $n últimas: la primera es la que abre la ventana.
            $inicio = max($inicio, ($inicios[0] ?? $inicio) + (float) $n / $ritmo);
        }
        $inicios[] = $inicio;
        $this->iniciosPorHost[$host] = array_slice($inicios, -$n);
        $this->esperar($inicio - $this->reloj->ahora());
    }

    /**
     * Lo ya descargado para esa URL, solo si hoy pasaría las mismas comprobaciones que una
     * petición nueva (IP real permitida y host autorizado). Si no, se pide como siempre y falla
     * por el motivo de siempre.
     */
    private function yaDescargada(string $url): ?RecursoDescargado
    {
        try {
            $destino = $this->validador->validar($url);
        } catch (DestinoRechazado) {
            return null;
        }
        if (!$this->ambito->permite($destino->url) || $this->memoria === null) {
            return null;
        }

        return ($this->memoria)($url);
    }

    private function enviar(PeticionHttp $peticion): RespuestaHttp
    {
        if ($this->enParalelo && Fiber::getCurrent() !== null) {
            /** @var RespuestaHttp $respuesta */
            $respuesta = Fiber::suspend(['enviar', $peticion]);

            return $respuesta;
        }

        return $this->transporte->enviar($peticion);
    }

    private function esperar(float $segundos): void
    {
        if ($segundos <= 0) {
            return;
        }
        if ($this->enParalelo && Fiber::getCurrent() !== null) {
            Fiber::suspend(['esperar', $this->reloj->ahora() + $segundos]);

            return;
        }
        $this->reloj->esperar($segundos);
    }

    private function espera(int $intento): float
    {
        $esperas = $this->limites->esperaReintentos;

        return $esperas[$intento] ?? (end($esperas) ?: 1.0);
    }

    /** Retry-After puede ser un número de segundos o una fecha HTTP. */
    private function segundosRetryAfter(?string $valor): ?int
    {
        if ($valor === null || trim($valor) === '') {
            return null;
        }
        if (ctype_digit(trim($valor))) {
            return (int) trim($valor);
        }
        $fecha = strtotime($valor);

        return $fecha === false ? null : max(0, $fecha - time());
    }

    private function resolverRelativa(DestinoUrl $base, string $location): string
    {
        $location = trim($location);
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*:#', $location)) {
            return $location;
        }
        if (str_starts_with($location, '//')) {
            return $base->esquema . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $base->origen() . $location;
        }
        if (str_starts_with($location, '?')) {
            return $base->origen() . strtok($base->rutaYConsulta, '?') . $location;
        }
        $ruta = (string) strtok($base->rutaYConsulta, '?');
        $directorio = substr($ruta, 0, (int) strrpos($ruta, '/') + 1);

        return $base->origen() . $directorio . $location;
    }

    private function limitePara(string $url): int
    {
        $ruta = strtolower((string) strtok((string) preg_replace('#^[a-z]+://[^/]*#i', '', $url), '?'));

        return match (true) {
            str_ends_with($ruta, '/robots.txt') => $this->limites->maxBytesRobots,
            str_contains($ruta, 'sitemap') && (str_ends_with($ruta, '.xml') || str_ends_with($ruta, '.xml.gz')) => $this->limites->maxBytesSitemap,
            default => $this->limites->maxBytesHtml,
        };
    }

    /**
     * @param list<array{url: string, estado: int, destino: ?string}> $redirecciones
     */
    private function recursoDeRespuesta(string $urlInicial, DestinoValidado $destino, RespuestaHttp $respuesta, array $redirecciones): RecursoDescargado
    {
        $resultado = match (true) {
            $respuesta->truncado => ResultadoDescarga::DemasiadoGrande,
            $respuesta->estado >= 200 && $respuesta->estado < 300 => ResultadoDescarga::Correcto,
            default => ResultadoDescarga::ErrorHttp,
        };

        return new RecursoDescargado(
            urlSolicitada: $urlInicial,
            urlFinal: $destino->url->url(),
            resultado: $resultado,
            estadoHttp: $respuesta->estado,
            redirecciones: $redirecciones,
            cabeceras: $this->sanitizarCabeceras($respuesta->cabeceras),
            tipoContenido: $respuesta->cabecera('content-type'),
            cuerpo: $respuesta->cuerpo,
            truncado: $respuesta->truncado,
            bytes: strlen($respuesta->cuerpo),
            tlsVerificado: $destino->url->esquema === 'https',
            motivo: $respuesta->truncado ? 'El recurso supera el tamaño máximo y se ha truncado.' : null,
            descargadoEn: new DateTimeImmutable(),
        );
    }

    /**
     * @param list<array{url: string, estado: int, destino: ?string}> $redirecciones
     */
    private function fallo(string $urlSolicitada, string $urlFinal, ResultadoDescarga $resultado, array $redirecciones, string $motivo, ?int $estado = null): RecursoDescargado
    {
        return new RecursoDescargado(
            urlSolicitada: $urlSolicitada,
            urlFinal: $urlFinal,
            resultado: $resultado,
            estadoHttp: $estado,
            redirecciones: $redirecciones,
            motivo: $motivo,
            descargadoEn: new DateTimeImmutable(),
        );
    }

    /**
     * @param list<array{url: string, estado: int, destino: ?string}> $redirecciones
     */
    private function conHistorial(RecursoDescargado $r, string $urlInicial, array $redirecciones): RecursoDescargado
    {
        if ($redirecciones === []) {
            return $r;
        }

        return new RecursoDescargado($urlInicial, $r->urlFinal, $r->resultado, $r->estadoHttp, $redirecciones,
            $r->cabeceras, $r->tipoContenido, $r->cuerpo, $r->truncado, $r->bytes, $r->tlsVerificado, $r->motivo, $r->descargadoEn);
    }

    /**
     * @param array<string, string> $cabeceras
     * @return array<string, string>
     */
    private function sanitizarCabeceras(array $cabeceras): array
    {
        return array_diff_key($cabeceras, array_flip(self::CABECERAS_DESCARTADAS));
    }
}
