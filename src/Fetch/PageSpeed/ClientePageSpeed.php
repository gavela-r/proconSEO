<?php

declare(strict_types=1);

namespace ProconSEO\Fetch\PageSpeed;

use DateTimeImmutable;
use ProconSEO\Fetch\DestinoRechazado;
use ProconSEO\Fetch\DestinoUrl;
use ProconSEO\Fetch\DestinoValidado;
use ProconSEO\Fetch\Http\PeticionHttp;
use ProconSEO\Fetch\Http\RespuestaHttp;
use ProconSEO\Fetch\Http\TransporteHttp;
use ProconSEO\Fetch\ValidadorDestino;
use ProconSEO\Shared\Contract\InformePageSpeed;
use Throwable;

/**
 * Pide a Google PageSpeed Insights (API v5) las mediciones de una URL pública.
 *
 * - Solo se conecta a www.googleapis.com, validado como cualquier destino (SEG-01/02) y con TLS
 *   verificado. A Google solo se le envía la URL analizada (especificación, sección 11).
 * - La clave (PAGESPEED_API_KEY) va solo en la petición: nunca en mensajes, registros ni
 *   resultados (SEG-17). Sin clave no se llama: Google ya no da cuota sin ella.
 * - Nunca lanza excepciones: si no hay datos, el informe lo dice en $error.
 * - Se queda con las mediciones y descarta la puntuación de Lighthouse (sin notas).
 */
final class ClientePageSpeed
{
    public const API = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    /** Google tarda entre 10 y 60 segundos en analizar una página. */
    private const SEGUNDOS_CONEXION = 10;
    private const SEGUNDOS_TOTAL = 90;
    /** La respuesta completa de Lighthouse ronda 1-3 MB; con capturas puede crecer. */
    private const MAX_BYTES = 20_971_520;
    private const MAX_FALLOS_CONTRASTE = 20;

    private const CAMPO = [
        'LARGEST_CONTENTFUL_PAINT_MS' => 'lcp_ms',
        'INTERACTION_TO_NEXT_PAINT' => 'inp_ms',
        'CUMULATIVE_LAYOUT_SHIFT_SCORE' => 'cls',
    ];
    private const LABORATORIO = [
        'largest-contentful-paint' => 'lcp_ms',
        'cumulative-layout-shift' => 'cls',
        'total-blocking-time' => 'tbt_ms',
        'first-contentful-paint' => 'fcp_ms',
        'speed-index' => 'si_ms',
    ];

    public function __construct(
        private readonly TransporteHttp $transporte,
        private readonly ValidadorDestino $validador,
        private readonly ?string $clave,
        private readonly string $userAgent,
    ) {
    }

    public function configurado(): bool
    {
        return $this->clave !== null && $this->clave !== '';
    }

    public function informe(string $url, string $dispositivo): InformePageSpeed
    {
        if (!$this->configurado()) {
            return InformePageSpeed::sinDatos($dispositivo, $url, 'No está configurada la clave de Google PageSpeed Insights (PAGESPEED_API_KEY), así que no se ha podido medir.');
        }

        try {
            $respuesta = $this->transporte->enviar(new PeticionHttp($this->destino($url, $dispositivo), self::MAX_BYTES, self::SEGUNDOS_CONEXION, self::SEGUNDOS_TOTAL, $this->userAgent));
        } catch (DestinoRechazado) {
            return InformePageSpeed::sinDatos($dispositivo, $url, 'No se pudo conectar de forma segura con Google PageSpeed Insights.');
        } catch (Throwable) {
            return InformePageSpeed::sinDatos($dispositivo, $url, 'Error inesperado al consultar Google PageSpeed Insights.');
        }

        return $this->interpretar($respuesta, $url, $dispositivo);
    }

    private function destino(string $url, string $dispositivo): DestinoValidado
    {
        $api = $this->validador->validar(self::API);
        $consulta = http_build_query([
            'url' => $url,
            'strategy' => $dispositivo === InformePageSpeed::ORDENADOR ? 'desktop' : 'mobile',
            'locale' => 'es',
            'key' => $this->clave,
        ], '', '&', PHP_QUERY_RFC3986)
            . '&category=performance&category=accessibility';

        return new DestinoValidado(
            new DestinoUrl($api->url->esquema, $api->url->host, $api->url->puerto, $api->url->rutaYConsulta . '?' . $consulta, false),
            $api->ips,
            $api->ipConexion,
        );
    }

    private function interpretar(RespuestaHttp $respuesta, string $url, string $dispositivo): InformePageSpeed
    {
        $sinDatos = static fn (string $motivo): InformePageSpeed => InformePageSpeed::sinDatos($dispositivo, $url, $motivo);

        if ($respuesta->error !== null) {
            return $sinDatos(match ($respuesta->error) {
                RespuestaHttp::ERROR_TIEMPO => 'Google PageSpeed Insights tardó demasiado en responder.',
                RespuestaHttp::ERROR_TLS => 'No se pudo verificar el certificado de Google PageSpeed Insights.',
                default => 'No se pudo conectar con Google PageSpeed Insights.',
            });
        }
        if ($respuesta->truncado) {
            return $sinDatos('La respuesta de Google PageSpeed Insights era demasiado grande.');
        }

        $datos = json_decode($respuesta->cuerpo, true, 64);
        if ($respuesta->estado !== 200 || !is_array($datos)) {
            return $sinDatos($this->motivoError($respuesta->estado, is_array($datos) ? $datos : []));
        }

        $lighthouse = is_array($datos['lighthouseResult'] ?? null) ? $datos['lighthouseResult'] : [];
        if (isset($lighthouse['runtimeError']['code'])) {
            return $sinDatos('Google no pudo cargar la página para medirla (' . self::texto($lighthouse['runtimeError']['code'], 80) . ').');
        }

        [$campo, $ambito] = $this->campo($datos);
        $auditorias = is_array($lighthouse['audits'] ?? null) ? $lighthouse['audits'] : [];
        [$contraste, $fallos] = $this->contraste($auditorias['color-contrast'] ?? null);

        return new InformePageSpeed(
            dispositivo: $dispositivo,
            url: $url,
            fecha: self::fecha($lighthouse['fetchTime'] ?? $datos['analysisUTCTimestamp'] ?? null),
            campo: $campo,
            ambitoCampo: $ambito,
            laboratorio: $this->laboratorio($auditorias),
            contrasteCorrecto: $contraste,
            fallosContraste: $fallos,
            versionLighthouse: isset($lighthouse['lighthouseVersion']) ? self::texto($lighthouse['lighthouseVersion'], 20) : null,
        );
    }

    /**
     * Datos de campo (CrUX). Si la URL no tiene suficientes visitas, Google da los del origen.
     *
     * @param array<string, mixed> $datos
     * @return array{0: array<string, float>, 1: ?string}
     */
    private function campo(array $datos): array
    {
        $deUrl = is_array($datos['loadingExperience'] ?? null) ? $datos['loadingExperience'] : [];
        $deOrigen = is_array($datos['originLoadingExperience'] ?? null) ? $datos['originLoadingExperience'] : [];

        if (is_array($deUrl['metrics'] ?? null) && $deUrl['metrics'] !== []) {
            [$fuente, $ambito] = [$deUrl, ($deUrl['origin_fallback'] ?? false) === true ? InformePageSpeed::CAMPO_ORIGEN : InformePageSpeed::CAMPO_URL];
        } elseif (is_array($deOrigen['metrics'] ?? null) && $deOrigen['metrics'] !== []) {
            [$fuente, $ambito] = [$deOrigen, InformePageSpeed::CAMPO_ORIGEN];
        } else {
            return [[], null];
        }

        $campo = [];
        foreach (self::CAMPO as $metrica => $clave) {
            $p75 = $fuente['metrics'][$metrica]['percentile'] ?? null;
            if (is_int($p75) || is_float($p75)) {
                // Google da el CLS multiplicado por 100 (p. ej., 5 = 0,05).
                $campo[$clave] = $clave === 'cls' ? (float) $p75 / 100.0 : (float) $p75;
            }
        }

        return $campo === [] ? [[], null] : [$campo, $ambito];
    }

    /**
     * @param array<string, mixed> $auditorias
     * @return array<string, float>
     */
    private function laboratorio(array $auditorias): array
    {
        $laboratorio = [];
        foreach (self::LABORATORIO as $auditoria => $clave) {
            $valor = $auditorias[$auditoria]['numericValue'] ?? null;
            if (is_int($valor) || is_float($valor)) {
                $laboratorio[$clave] = (float) $valor;
            }
        }

        return $laboratorio;
    }

    /** @return array{0: ?bool, 1: list<array{selector: string, fragmento: string, explicacion: string}>} */
    private function contraste(mixed $auditoria): array
    {
        if (!is_array($auditoria) || !isset($auditoria['score']) || !is_numeric($auditoria['score'])) {
            return [null, []]; // no aplicable o Google no pudo evaluarla
        }

        $fallos = [];
        foreach (array_slice((array) ($auditoria['details']['items'] ?? []), 0, self::MAX_FALLOS_CONTRASTE) as $item) {
            $nodo = is_array($item['node'] ?? null) ? $item['node'] : [];
            $fallos[] = [
                'selector' => self::texto($nodo['selector'] ?? '', 300),
                'fragmento' => self::texto($nodo['snippet'] ?? '', 500),
                'explicacion' => self::texto($nodo['explanation'] ?? '', 500),
            ];
        }

        return [(float) $auditoria['score'] >= 1.0, $fallos];
    }

    /** @param array<string, mixed> $datos */
    private function motivoError(int $estado, array $datos): string
    {
        // El mensaje de Google solo se usa para clasificar el error, nunca se muestra.
        $mensaje = is_scalar($datos['error']['message'] ?? null) ? (string) $datos['error']['message'] : '';

        return match (true) {
            $estado === 429 => 'Se ha agotado la cuota diaria de Google PageSpeed Insights. Se podrá medir más tarde.',
            $estado === 403 || ($estado === 400 && stripos($mensaje, 'API key') !== false) => 'Google no acepta la clave de PageSpeed Insights: revisa PAGESPEED_API_KEY y sus restricciones.',
            ($estado === 400 || $estado === 500) && stripos($mensaje, 'Lighthouse') !== false => 'Google no pudo cargar la página para medirla.',
            $estado >= 500 => 'Google PageSpeed Insights no está disponible ahora mismo (HTTP ' . $estado . ').',
            default => 'Google PageSpeed Insights no devolvió datos (HTTP ' . $estado . ').',
        };
    }

    private static function fecha(mixed $valor): ?DateTimeImmutable
    {
        if (!is_string($valor)) {
            return null;
        }
        try {
            return new DateTimeImmutable($valor);
        } catch (Throwable) {
            return null;
        }
    }

    private static function texto(mixed $valor, int $max): string
    {
        return is_scalar($valor) ? mb_substr((string) $valor, 0, $max) : '';
    }
}
