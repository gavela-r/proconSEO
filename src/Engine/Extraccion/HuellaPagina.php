<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Extraccion;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Lo que hace falta de cada página para compararla con las demás del sitio sin guardar su
 * contenido (ResumenPagina):
 *
 * - el texto principal, sin cabecera, menú, pie ni barras laterales, que se repiten en todas;
 * - su huella MinHash: unos números que permiten estimar cuánto texto comparten dos páginas
 *   (similitud de Jaccard de sus trozos de 5 palabras) sin tener el texto;
 * - los destinos del menú principal, en orden.
 */
final class HuellaPagina
{
    /** Números de la huella: con 64, el error típico de la similitud estimada es de ±0,06. */
    public const TAMANO = 64;

    /** Palabras de cada trozo que se compara. */
    public const PALABRAS_POR_TROZO = 5;

    /** Primo para las funciones hash (2^31 − 1): los productos caben en un entero de 64 bits. */
    private const PRIMO = 2147483647;

    /** Partes de la página que se repiten en todas o que no son texto. */
    private const FUERA = ['header', 'nav', 'footer', 'aside', 'script', 'style', 'noscript', 'template', 'svg', 'form', 'select', 'button'];

    /** Texto principal: <main> o role="main" si los hay; si no, el body sin cabecera, menú ni pie. */
    public static function textoPrincipal(DOMDocument $dom): string
    {
        $xp = new DOMXPath($dom);
        $raiz = null;
        foreach ($xp->query('//main | //*[@role="main"]') ?: [] as $n) {
            if ($n instanceof DOMElement) {
                $raiz = $n;
                break;
            }
        }
        $raiz ??= $dom->getElementsByTagName('body')->item(0);
        if ($raiz === null) {
            return '';
        }
        $partes = [];
        self::recoger($raiz, $partes);
        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', $partes)));
    }

    /** @return list<string> palabras en minúsculas */
    public static function palabras(string $texto): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($texto), $m);
        return $m[0];
    }

    /**
     * Huella MinHash de los trozos de 5 palabras seguidas. Vacía si el texto tiene menos de
     * 5 palabras.
     *
     * @param list<string> $palabras
     * @return list<int>
     */
    public static function huella(array $palabras): array
    {
        $n = count($palabras);
        if ($n < self::PALABRAS_POR_TROZO) {
            return [];
        }
        $bases = [];
        for ($i = 0; $i + self::PALABRAS_POR_TROZO <= $n; ++$i) {
            $bases[crc32(implode(' ', array_slice($palabras, $i, self::PALABRAS_POR_TROZO))) % self::PRIMO] = true;
        }
        $huella = [];
        for ($k = 0; $k < self::TAMANO; ++$k) {
            // Funciones hash fijas (a·x + b mod p): la misma página da siempre la misma huella.
            $a = crc32('a' . $k) % (self::PRIMO - 1) + 1;
            $b = crc32('b' . $k) % self::PRIMO;
            $minimo = self::PRIMO;
            foreach (array_keys($bases) as $x) {
                $h = ($a * $x + $b) % self::PRIMO;
                if ($h < $minimo) {
                    $minimo = $h;
                }
            }
            $huella[] = $minimo;
        }
        return $huella;
    }

    /**
     * Parte del texto que comparten dos páginas (0 a 1), estimada con sus huellas. 0 si
     * alguna no tiene huella o son de distinto tamaño.
     *
     * @param list<int> $a
     * @param list<int> $b
     */
    public static function similitud(array $a, array $b): float
    {
        if ($a === [] || count($a) !== count($b)) {
            return 0.0;
        }
        $iguales = 0;
        foreach ($a as $i => $valor) {
            if ($valor === $b[$i]) {
                ++$iguales;
            }
        }
        return $iguales / count($a);
    }

    /**
     * Destinos del menú principal en el orden en que aparecen: el primer <nav> (o
     * role="navigation") con al menos dos enlaces, preferiblemente dentro de <header>. URLs
     * absolutas sin fragmento; solo las que $esInterna acepta.
     *
     * @param callable(string): bool $esInterna
     * @return list<string>
     */
    public static function menu(DOMDocument $dom, string $base, callable $esInterna, int $maximo): array
    {
        $xp = new DOMXPath($dom);
        $consultas = ['//header//nav | //header//*[@role="navigation"]', '//nav | //*[@role="navigation"]'];
        foreach ($consultas as $consulta) {
            foreach ($xp->query($consulta) ?: [] as $nav) {
                if (!$nav instanceof DOMElement) {
                    continue;
                }
                $destinos = [];
                foreach ($xp->query('.//a[@href]', $nav) ?: [] as $a) {
                    if (!$a instanceof DOMElement) {
                        continue;
                    }
                    $url = Url::resolver($base, trim($a->getAttribute('href')));
                    if ($url === null || !in_array(Url::esquema($url), ['http', 'https'], true)) {
                        continue;
                    }
                    $url = Url::sinFragmento($url);
                    if ($esInterna($url) && !in_array($url, $destinos, true)) {
                        $destinos[] = $url;
                    }
                }
                if (count($destinos) >= 2) {
                    return array_slice($destinos, 0, $maximo);
                }
            }
        }
        return [];
    }

    /** @param list<string> $partes */
    private static function recoger(DOMNode $nodo, array &$partes): void
    {
        foreach ($nodo->childNodes as $hijo) {
            if ($hijo->nodeType === XML_TEXT_NODE) {
                $partes[] = (string) $hijo->nodeValue;
            } elseif ($hijo instanceof DOMElement) {
                if (in_array($hijo->nodeName, self::FUERA, true) || $hijo->hasAttribute('hidden')
                    || in_array(strtolower($hijo->getAttribute('role')), ['navigation', 'banner', 'contentinfo'], true)
                    || preg_match('/display\s*:\s*none/i', $hijo->getAttribute('style')) === 1) {
                    continue;
                }
                self::recoger($hijo, $partes);
            }
        }
    }
}
