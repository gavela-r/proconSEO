<?php

declare(strict_types=1);

namespace ProconSEO\Fetch\Http;

/**
 * Transporte real con cURL, configurado para no salirse de lo validado:
 *
 * - Conecta a la IP ya validada (CURLOPT_RESOLVE), contra el DNS rebinding.
 * - Nunca sigue redirecciones por su cuenta.
 * - Solo http/https, sin proxy, sin cookies y sin reutilizar conexiones.
 * - Verifica siempre el certificado TLS y el nombre del servidor (SEG-03).
 * - Corta la descarga al superar $maxBytes, medidos ya descomprimidos (SEG-05).
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
        $cuerpo = '';
        $truncado = false;
        $cabeceras = [];
        $bytesCabeceras = 0;

        $curl = curl_init();
        if ($curl === false) {
            return new RespuestaHttp(0, error: RespuestaHttp::ERROR_RED, detalleError: 'No se pudo iniciar cURL.');
        }

        curl_setopt_array($curl, [
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
            CURLOPT_HEADERFUNCTION => static function ($c, string $linea) use (&$cabeceras, &$bytesCabeceras): int {
                $bytesCabeceras += strlen($linea);
                if ($bytesCabeceras > self::MAX_BYTES_CABECERAS || count($cabeceras) > self::MAX_CABECERAS) {
                    return -1;
                }
                if (str_starts_with($linea, 'HTTP/')) {
                    $cabeceras = []; // respuestas intermedias como 100 Continue
                } elseif (str_contains($linea, ':')) {
                    [$nombre, $valor] = explode(':', $linea, 2);
                    $nombre = strtolower(trim($nombre));
                    $valor = trim($valor);
                    $cabeceras[$nombre] = isset($cabeceras[$nombre]) ? $cabeceras[$nombre] . ', ' . $valor : $valor;
                }

                return strlen($linea);
            },
            CURLOPT_WRITEFUNCTION => static function ($c, string $datos) use (&$cuerpo, &$truncado, $peticion): int {
                $disponible = $peticion->maxBytes - strlen($cuerpo);
                if (strlen($datos) > $disponible) {
                    $cuerpo .= substr($datos, 0, max(0, $disponible));
                    $truncado = true;

                    return -1; // cURL aborta la descarga
                }
                $cuerpo .= $datos;

                return strlen($datos);
            },
        ]);

        curl_exec($curl);
        $errno = curl_errno($curl);
        $mensaje = curl_error($curl);
        $estado = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        // Abortado por superar el tamaño: no es un error, es un recurso truncado.
        if ($truncado) {
            return new RespuestaHttp($estado, $cabeceras, $cuerpo, true);
        }

        if ($errno !== 0) {
            $tipo = match (true) {
                in_array($errno, self::ERRORES_TLS, true) => RespuestaHttp::ERROR_TLS,
                $errno === 28 => RespuestaHttp::ERROR_TIEMPO,
                default => RespuestaHttp::ERROR_RED,
            };

            return new RespuestaHttp($estado, $cabeceras, '', false, $tipo, $mensaje);
        }

        return new RespuestaHttp($estado, $cabeceras, $cuerpo);
    }
}
