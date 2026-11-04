<?php

declare(strict_types=1);

namespace ProconSEO\Fetch;

use DateTimeImmutable;
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
 */
final class ClienteHttpSeguro implements ProveedorRecursos
{
    /** Cabeceras de respuesta que no se guardan nunca (SEG-09). */
    private const CABECERAS_DESCARTADAS = ['set-cookie', 'set-cookie2'];

    private int $bytesConsumidos = 0;

    /** @var array<string, float> host => instante de la última petición */
    private array $ultimaPeticionPorHost = [];

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

    public function descargar(string $url, string $motivo, ?string $idComprobacion, int $maxBytes): RecursoDescargado
    {
        $recurso = $this->seguirRedirecciones($url, $maxBytes);
        $this->registro[] = ['recurso' => $recurso, 'motivo' => $motivo, 'comprobacion' => $idComprobacion];

        return $recurso;
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
            $respuesta = $this->transporte->enviar(new PeticionHttp(
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
                    $this->reloj->esperar($this->espera($intento));
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
                $this->reloj->esperar($espera);
                ++$intento;
                continue;
            }

            if (in_array($respuesta->estado, [500, 502, 503, 504], true) && $quedanReintentos) {
                $this->reloj->esperar($this->espera($intento));
                ++$intento;
                continue;
            }

            return [$destino, $respuesta];
        }
    }

    private function respetarRitmo(string $host): void
    {
        $intervalo = $this->limites->peticionesPorSegundoHost > 0 ? 1 / $this->limites->peticionesPorSegundoHost : 0;
        $ultima = $this->ultimaPeticionPorHost[$host] ?? null;
        if ($ultima !== null) {
            $this->reloj->esperar($ultima + $intervalo - $this->reloj->ahora());
        }
        $this->ultimaPeticionPorHost[$host] = $this->reloj->ahora();
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
