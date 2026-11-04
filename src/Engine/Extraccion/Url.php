<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Extraccion;

/**
 * Resolución de referencias relativas (RFC 3986, sección 5) sin hacer red.
 *
 * Solo trabaja con texto: no valida que la URL sea segura ni que esté en el ámbito.
 * Eso lo decide la Parte 1 (SEG-01/02) cuando se pide el recurso.
 */
final class Url
{
    /** Esquemas que nunca son una página rastreable. */
    private const ESQUEMAS_NO_HTTP = ['javascript', 'mailto', 'tel', 'sms', 'data', 'blob', 'file', 'ftp', 'about'];

    /**
     * Resuelve $referencia contra $base. Devuelve null si la referencia está vacía, si la
     * base no es absoluta o si la referencia usa un esquema no http(s).
     */
    public static function resolver(string $base, string $referencia): ?string
    {
        $referencia = trim($referencia);
        if ($referencia === '') {
            return null;
        }

        $esquema = self::esquema($referencia);
        if ($esquema !== null) {
            if (!in_array($esquema, ['http', 'https'], true)) {
                return null;
            }
            $r = self::partes($referencia);
            $r['ruta'] = self::quitarPuntos($r['ruta']);
            return self::componer($r);
        }

        $b = self::partes($base);
        if (!in_array($b['esquema'], ['http', 'https'], true) || $b['autoridad'] === null) {
            return null;
        }

        if (str_starts_with($referencia, '//')) {
            $r = self::partes($b['esquema'] . ':' . $referencia);
            $r['ruta'] = self::quitarPuntos($r['ruta']);
            return self::componer($r);
        }

        $r = self::partes('x:' . $referencia);
        $t = ['esquema' => $b['esquema'], 'autoridad' => $b['autoridad'], 'fragmento' => $r['fragmento']];

        if ($r['ruta'] === '') {
            $t['ruta'] = $b['ruta'];
            $t['consulta'] = $r['consulta'] ?? $b['consulta'];
        } else {
            if (str_starts_with($r['ruta'], '/')) {
                $t['ruta'] = self::quitarPuntos($r['ruta']);
            } else {
                $t['ruta'] = self::quitarPuntos(self::fusionar($b, $r['ruta']));
            }
            $t['consulta'] = $r['consulta'];
        }

        return self::componer($t);
    }

    /** Esquema en minúsculas, o null si la cadena es relativa. */
    public static function esquema(string $url): ?string
    {
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', trim($url), $m) !== 1) {
            return null;
        }
        return strtolower($m[1]);
    }

    /** true si la referencia es un enlace que no lleva a ninguna página (javascript:, mailto:…). */
    public static function esNoNavegable(string $referencia): bool
    {
        $esquema = self::esquema($referencia);
        return $esquema !== null && in_array($esquema, self::ESQUEMAS_NO_HTTP, true);
    }

    /** Host en minúsculas, o null. */
    public static function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /** URL sin fragmento, para comparar páginas. */
    public static function sinFragmento(string $url): string
    {
        $pos = strpos($url, '#');
        return $pos === false ? $url : substr($url, 0, $pos);
    }

    /**
     * @return array{esquema: ?string, autoridad: ?string, ruta: string, consulta: ?string, fragmento: ?string}
     */
    private static function partes(string $url): array
    {
        // Expresión regular del apéndice B de la RFC 3986.
        preg_match('~^(?:([^:/?#]+):)?(?://([^/?#]*))?([^?#]*)(?:\?([^#]*))?(?:#(.*))?~s', $url, $m, PREG_UNMATCHED_AS_NULL);

        $esquema = $m[1] !== null ? strtolower($m[1]) : null;
        $autoridad = $m[2] !== null ? self::normalizarAutoridad($m[2], $esquema) : null;

        return [
            'esquema' => $esquema,
            'autoridad' => $autoridad,
            'ruta' => $m[3] ?? '',
            'consulta' => $m[4],
            'fragmento' => $m[5],
        ];
    }

    private static function normalizarAutoridad(string $autoridad, ?string $esquema): string
    {
        $autoridad = strtolower($autoridad);
        if ($esquema === 'http' && str_ends_with($autoridad, ':80')) {
            return substr($autoridad, 0, -3);
        }
        if ($esquema === 'https' && str_ends_with($autoridad, ':443')) {
            return substr($autoridad, 0, -4);
        }
        return $autoridad;
    }

    /** @param array{autoridad: ?string, ruta: string} $base */
    private static function fusionar(array $base, string $ruta): string
    {
        if ($base['autoridad'] !== null && $base['ruta'] === '') {
            return '/' . $ruta;
        }
        $pos = strrpos($base['ruta'], '/');
        return $pos === false ? $ruta : substr($base['ruta'], 0, $pos + 1) . $ruta;
    }

    private static function quitarPuntos(string $ruta): string
    {
        $salida = [];
        $entrada = $ruta;
        while ($entrada !== '') {
            if (str_starts_with($entrada, '../')) {
                $entrada = substr($entrada, 3);
            } elseif (str_starts_with($entrada, './')) {
                $entrada = substr($entrada, 2);
            } elseif (str_starts_with($entrada, '/./')) {
                $entrada = substr($entrada, 2);
            } elseif ($entrada === '/.') {
                $entrada = '/';
            } elseif (str_starts_with($entrada, '/../')) {
                $entrada = substr($entrada, 3);
                array_pop($salida);
            } elseif ($entrada === '/..') {
                $entrada = '/';
                array_pop($salida);
            } elseif ($entrada === '.' || $entrada === '..') {
                $entrada = '';
            } else {
                $inicio = str_starts_with($entrada, '/') ? 1 : 0;
                $pos = strpos($entrada, '/', $inicio);
                $segmento = $pos === false ? $entrada : substr($entrada, 0, $pos);
                $salida[] = $segmento;
                $entrada = $pos === false ? '' : substr($entrada, $pos);
            }
        }
        return implode('', $salida);
    }

    /** @param array{esquema: ?string, autoridad: ?string, ruta: string, consulta: ?string, fragmento: ?string} $p */
    private static function componer(array $p): string
    {
        $url = '';
        if ($p['esquema'] !== null) {
            $url .= $p['esquema'] . ':';
        }
        if ($p['autoridad'] !== null) {
            $url .= '//' . $p['autoridad'];
        }
        $ruta = $p['ruta'];
        if ($p['autoridad'] !== null && $ruta === '') {
            $ruta = '/';
        }
        $url .= $ruta;
        if ($p['consulta'] !== null) {
            $url .= '?' . $p['consulta'];
        }
        if ($p['fragmento'] !== null) {
            $url .= '#' . $p['fragmento'];
        }
        return $url;
    }
}
