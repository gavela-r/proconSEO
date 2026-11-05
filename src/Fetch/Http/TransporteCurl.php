<?php

declare(strict_types=1);

namespace ProconSEO\Fetch\Http;

use CurlHandle;
use stdClass;

/**
 * Transporte real con cURL, configurado para no salirse de lo validado:
 *
 * - Conecta a la IP ya validada (CURLOPT_RESOLVE), contra el DNS rebinding.
 * - Nunca sigue redirecciones por su cuenta.
 * - Solo http/https, sin proxy, sin cookies y sin reutilizar conexiones.
 * - Verifica siempre el certificado TLS y el nombre del servidor (SEG-03).
 * - Corta la descarga al superar $maxBytes, medidos ya descomprimidos (SEG-05).
 *
 * Una petición o varias a la vez (curl_multi) usan exactamente la misma configuración.
 */
final class TransporteCurl implements TransporteHttp
{
    /** Máximo de cabeceras y de tamaño total de cabeceras que se aceptan. */
    private const MAX_CABECERAS = 100;
    private const MAX_BYTES_CABECERAS = 65536;

    /** Códigos de error de cURL relacionados con TLS y certificados (libcurl-errors). */
    private const ERRORES_TLS = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];

    public function enviar(PeticionHttp $peticion): RespuestaHttp
    {
        return $this->enviarVarias([$peticion])[0];
    }

    public function enviarVarias(array $peticiones): array
    {
        $multi = curl_multi_init();
        /** @var array<int, array{curl: CurlHandle, estado: stdClass}> $enCurso */
        $enCurso = [];
        /** @var array<int, RespuestaHttp> $respuestas */
        $respuestas = [];

        foreach ($peticiones as $i => $peticion) {
            $estado = (object) ['cuerpo' => '', 'truncado' => false, 'cabeceras' => [], 'bytesCabeceras' => 0, 'errno' => 0];
            $curl = curl_init();
            if ($curl === false) {
                $respuestas[$i] = new RespuestaHttp(0, error: RespuestaHttp::ERROR_RED, detalleError: 'No se pudo iniciar cURL.');
                continue;
            }
            curl_setopt_array($curl, $this->opciones($peticion, $estado));
            curl_multi_add_handle($multi, $curl);
            $enCurso[$i] = ['curl' => $curl, 'estado' => $estado];
        }

        // Todas a la vez; cada una con su propio tiempo máximo y su límite de bytes.
        do {
            $codigo = curl_multi_exec($multi, $activas);
            while (($info = curl_multi_info_read($multi)) !== false) {
                foreach ($enCurso as $e) {
                    if ($e['curl'] === $info['handle']) {
                        $e['estado']->errno = (int) $info['result'];
                    }
                }
            }
            if ($activas > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($activas > 0 && $codigo === CURLM_OK);

        foreach ($enCurso as $i => $e) {
            $respuestas[$i] = $this->respuesta($e['curl'], $e['estado']);
            curl_multi_remove_handle($multi, $e['curl']);
            curl_close($e['curl']);
        }
        curl_multi_close($multi);
        ksort($respuestas);

        return array_values($respuestas);
    }

    /** @return array<int, mixed> */
    private function opciones(PeticionHttp $peticion, stdClass $estado): array
    {
        return [
            CURLOPT_URL => $peticion->destino->url->url(),
            CURLOPT_RESOLVE => $peticion->destino->opcionCurlResolve(),
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => $peticion->segundosConexion,
            CURLOPT_TIMEOUT => $peticion->segundosTotal,
            CURLOPT_ENCODING => '', // acepta gzip/deflate/br; el límite se aplica a los datos ya descomprimidos
            CURLOPT_USERAGENT => $peticion->userAgent,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,text/plain;q=0.8,*/*;q=0.5',
                'Accept-Language: ' . $peticion->idioma,
            ],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADERFUNCTION => static function ($c, string $linea) use ($estado): int {
                $estado->bytesCabeceras += strlen($linea);
                if ($estado->bytesCabeceras > self::MAX_BYTES_CABECERAS || count($estado->cabeceras) > self::MAX_CABECERAS) {
                    return -1;
                }
                if (str_starts_with($linea, 'HTTP/')) {
                    $estado->cabeceras = []; // respuestas intermedias como 100 Continue
                } elseif (str_contains($linea, ':')) {
                    [$nombre, $valor] = explode(':', $linea, 2);
                    $nombre = strtolower(trim($nombre));
                    $valor = trim($valor);
                    $estado->cabeceras[$nombre] = isset($estado->cabeceras[$nombre]) ? $estado->cabeceras[$nombre] . ', ' . $valor : $valor;
                }

                return strlen($linea);
            },
            CURLOPT_WRITEFUNCTION => static function ($c, string $datos) use ($estado, $peticion): int {
                $disponible = $peticion->maxBytes - strlen($estado->cuerpo);
                if (strlen($datos) > $disponible) {
                    $estado->cuerpo .= substr($datos, 0, max(0, $disponible));
                    $estado->truncado = true;

                    return -1; // cURL aborta la descarga
                }
                $estado->cuerpo .= $datos;

                return strlen($datos);
            },
        ];
    }

    private function respuesta(CurlHandle $curl, stdClass $estado): RespuestaHttp
    {
        $errno = (int) $estado->errno;
        $codigo = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        // Abortado por superar el tamaño: no es un error, es un recurso truncado.
        if ($estado->truncado) {
            return new RespuestaHttp($codigo, $estado->cabeceras, $estado->cuerpo, true);
        }

        if ($errno !== 0) {
            $tipo = match (true) {
                in_array($errno, self::ERRORES_TLS, true) => RespuestaHttp::ERROR_TLS,
                $errno === 28 => RespuestaHttp::ERROR_TIEMPO,
                default => RespuestaHttp::ERROR_RED,
            };

            return new RespuestaHttp($codigo, $estado->cabeceras, '', false, $tipo, (string) curl_strerror($errno));
        }

        return new RespuestaHttp($codigo, $estado->cabeceras, $estado->cuerpo);
    }
}
