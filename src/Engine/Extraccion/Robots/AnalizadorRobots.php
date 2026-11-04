<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Extraccion\Robots;

/**
 * Lee un robots.txt según la RFC 9309 (Robots Exclusion Protocol), que es la que aplican
 * Google y Bing. Solo interpreta el texto: qué hacer con un 4xx, 429 o 5xx lo decide la
 * comprobación (IA-01.robots-recuperado).
 *
 * - Un grupo empieza con una o varias líneas user-agent seguidas; una user-agent después de
 *   reglas abre un grupo nuevo.
 * - Las claves no distinguen mayúsculas; los comentarios empiezan por #.
 * - Las reglas anteriores a cualquier user-agent no pertenecen a ningún grupo y se ignoran.
 * - Se leen como máximo 500 KiB (límite de Google y del perfil v1); el resto se ignora y se marca.
 */
final class AnalizadorRobots
{
    public const LIMITE_BYTES = 500 * 1024;

    /** Claves reconocidas que no afectan al permiso (se registran como ignoradas, no inválidas). */
    private const CLAVES_CONOCIDAS = ['crawl-delay', 'host', 'clean-param', 'noindex', 'request-rate', 'visit-time'];

    public function analizar(string $texto): ReglasRobots
    {
        $truncado = strlen($texto) > self::LIMITE_BYTES;
        if ($truncado) {
            $texto = substr($texto, 0, self::LIMITE_BYTES);
        }
        if (str_starts_with($texto, "\xEF\xBB\xBF")) {
            $texto = substr($texto, 3);
        }

        $grupos = [];
        $sitemaps = [];
        $invalidas = [];
        $ignoradas = [];
        $actual = null;
        $enReglas = false;

        $lineas = preg_split('/\r\n|\r|\n/', $texto) ?: [];
        foreach ($lineas as $i => $linea) {
            $numero = $i + 1;
            $sinComentario = trim(explode('#', $linea, 2)[0]);
            if ($sinComentario === '') {
                continue;
            }
            if (preg_match('/^([A-Za-z\-]+)\s*:\s*(.*)$/', $sinComentario, $m) !== 1) {
                $invalidas[] = ['linea' => $numero, 'texto' => mb_substr($sinComentario, 0, 200)];
                continue;
            }
            $clave = strtolower($m[1]);
            $valor = trim($m[2]);

            switch ($clave) {
                case 'user-agent':
                    if ($actual === null || $enReglas) {
                        if ($actual !== null) {
                            $grupos[] = $actual;
                        }
                        $actual = ['agentes' => [], 'reglas' => [], 'linea' => $numero];
                        $enReglas = false;
                    }
                    $actual['agentes'][] = self::token($valor);
                    break;

                case 'allow':
                case 'disallow':
                    if ($actual === null) {
                        $invalidas[] = ['linea' => $numero, 'texto' => mb_substr($sinComentario, 0, 200)];
                        break;
                    }
                    $enReglas = true;
                    if ($valor !== '') {
                        $actual['reglas'][] = [
                            'permitir' => $clave === 'allow',
                            'patron' => self::normalizarRuta($valor),
                            'original' => $valor,
                            'linea' => $numero,
                        ];
                    }
                    break;

                case 'sitemap':
                    if ($valor !== '') {
                        $sitemaps[] = $valor;
                    }
                    break;

                default:
                    if (in_array($clave, self::CLAVES_CONOCIDAS, true)) {
                        $ignoradas[] = ['linea' => $numero, 'texto' => mb_substr($sinComentario, 0, 200)];
                        if ($actual !== null) {
                            $enReglas = true;
                        }
                    } else {
                        $invalidas[] = ['linea' => $numero, 'texto' => mb_substr($sinComentario, 0, 200)];
                    }
            }
        }
        if ($actual !== null) {
            $grupos[] = $actual;
        }

        return new ReglasRobots($grupos, $sitemaps, $invalidas, $ignoradas, $truncado);
    }

    /**
     * Token de producto en minúsculas: "Googlebot/2.1" → "googlebot". "*" se conserva.
     */
    public static function token(string $valor): string
    {
        $valor = trim($valor);
        if ($valor === '*') {
            return '*';
        }
        preg_match('/^[A-Za-z_\-]+/', $valor, $m);
        return strtolower($m[0] ?? $valor);
    }

    /**
     * Normaliza una ruta o patrón para comparar octeto a octeto: codifica lo no ASCII y pone en
     * mayúsculas los %XX (RFC 9309, 2.2.2).
     */
    public static function normalizarRuta(string $ruta): string
    {
        $ruta = (string) preg_replace_callback('/[^\x21-\x7E]/', static fn (array $m): string => rawurlencode($m[0]), $ruta);
        return (string) preg_replace_callback('/%[0-9a-fA-F]{2}/', static fn (array $m): string => strtoupper($m[0]), $ruta);
    }
}
