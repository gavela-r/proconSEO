<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Extraccion\Sitemap;

use DOMDocument;
use DOMElement;
use ProconSEO\Engine\Extraccion\ErrorExtraccion;
use ProconSEO\Engine\Extraccion\Url;

/**
 * Lee un sitemap XML (urlset o sitemapindex, protocolo sitemaps.org) de forma segura (SEG-05).
 *
 * - Un sitemap nunca necesita DTD: cualquier DOCTYPE se rechaza, lo que impide XXE y la
 *   expansión exponencial de entidades.
 * - Sin red (LIBXML_NONET) y sin sustitución de entidades.
 * - Límite de 10 MB (perfil v1) y de 50.000 entradas (protocolo); lo que exceda se marca.
 * - Los sitemaps .xml.gz se descomprimen aquí, por partes y midiendo el tamaño ya
 *   descomprimido: un fichero pequeño que crece a gigas (bomba gzip) se corta al pasar el
 *   límite, sin llegar a ocupar la memoria.
 * - No sigue ninguna URL: las devuelve para que la Parte 1 decida si están en el ámbito.
 */
final class AnalizadorSitemap
{
    public const LIMITE_BYTES = 10 * 1024 * 1024;
    public const MAXIMO_ENTRADAS = 50000;

    public function __construct(private readonly int $limiteBytes = self::LIMITE_BYTES)
    {
    }

    /** @throws ErrorExtraccion si el XML es peligroso, demasiado grande o ilegible */
    public function analizar(string $xml): ContenidoSitemap
    {
        if (strlen($xml) > $this->limiteBytes) {
            throw new ErrorExtraccion('demasiado_grande', 'El sitemap supera el límite de ' . $this->limiteBytes . ' bytes.');
        }
        if (trim($xml) === '') {
            throw new ErrorExtraccion('xml_mal_formado', 'El sitemap está vacío.');
        }
        if (self::esGzip($xml)) {
            $xml = $this->descomprimir($xml);
            if (trim($xml) === '') {
                throw new ErrorExtraccion('xml_mal_formado', 'El sitemap comprimido está vacío.');
            }
        }
        if (preg_match('/<!DOCTYPE/i', $xml) === 1) {
            throw new ErrorExtraccion('dtd_no_permitido', 'El sitemap declara un DOCTYPE; se rechaza para evitar entidades externas.');
        }

        $dom = new DOMDocument();
        $previo = libxml_use_internal_errors(true);
        try {
            $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
            $errores = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previo);
        }
        if ($ok === false || $dom->documentElement === null) {
            $detalle = isset($errores[0]) ? ' (línea ' . $errores[0]->line . ': ' . trim($errores[0]->message) . ')' : '';
            throw new ErrorExtraccion('xml_mal_formado', 'El sitemap no es un XML válido' . $detalle . '.');
        }
        if ($dom->doctype !== null) {
            throw new ErrorExtraccion('dtd_no_permitido', 'El sitemap declara un DOCTYPE.');
        }

        $raiz = $dom->documentElement;
        $tipo = match ($raiz->localName) {
            'urlset' => ContenidoSitemap::URLSET,
            'sitemapindex' => ContenidoSitemap::INDICE,
            default => throw new ErrorExtraccion('formato_desconocido', 'La raíz del XML no es urlset ni sitemapindex.'),
        };
        $hijo = $tipo === ContenidoSitemap::URLSET ? 'url' : 'sitemap';

        $entradas = [];
        $avisos = [];
        $truncado = false;
        foreach ($raiz->childNodes as $nodo) {
            if (!$nodo instanceof DOMElement || $nodo->localName !== $hijo) {
                continue;
            }
            if (count($entradas) >= self::MAXIMO_ENTRADAS) {
                $truncado = true;
                break;
            }
            $loc = self::texto($nodo, 'loc');
            if ($loc === null || $loc === '') {
                $avisos[] = 'Entrada sin <loc>.';
                continue;
            }
            if (!in_array(Url::esquema($loc), ['http', 'https'], true)) {
                $avisos[] = 'URL no absoluta o no http(s): ' . mb_substr($loc, 0, 200);
                continue;
            }
            $entradas[] = ['loc' => $loc, 'lastmod' => self::texto($nodo, 'lastmod')];
        }

        if (!$this->conEspacioDeNombres($raiz)) {
            $avisos[] = 'La raíz no usa el espacio de nombres http://www.sitemaps.org/schemas/sitemap/0.9.';
        }

        return new ContenidoSitemap($tipo, $entradas, $avisos, $truncado);
    }

    public static function esGzip(string $datos): bool
    {
        return str_starts_with($datos, "\x1f\x8b");
    }

    /** @throws ErrorExtraccion si está dañado, anidado o al descomprimirlo pasa del límite */
    private function descomprimir(string $comprimido): string
    {
        $inflador = @inflate_init(ZLIB_ENCODING_GZIP);
        if ($inflador === false) {
            throw new ErrorExtraccion('comprimido_danado', 'No se pudo preparar la descompresión del sitemap.');
        }
        $salida = '';
        // Deflate multiplica como mucho por ~1.032: 4 KB comprimidos no pasan de ~4 MB de golpe.
        $bloque = 4096;
        $total = strlen($comprimido);
        for ($i = 0; $i < $total; $i += $bloque) {
            $ultimo = $i + $bloque >= $total;
            $parte = @inflate_add($inflador, substr($comprimido, $i, $bloque), $ultimo ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
            if ($parte === false) {
                throw new ErrorExtraccion('comprimido_danado', 'El sitemap comprimido está dañado.');
            }
            $salida .= $parte;
            if (strlen($salida) > $this->limiteBytes) {
                throw new ErrorExtraccion('demasiado_grande', 'El sitemap descomprimido supera el límite de ' . $this->limiteBytes . ' bytes.');
            }
            if (inflate_get_status($inflador) === ZLIB_STREAM_END) {
                break;
            }
        }
        if (inflate_get_status($inflador) !== ZLIB_STREAM_END) {
            throw new ErrorExtraccion('comprimido_danado', 'El sitemap comprimido está incompleto.');
        }
        if (self::esGzip($salida)) {
            // Un sitemap no se comprime dos veces: no se abre una segunda capa.
            throw new ErrorExtraccion('comprimido_danado', 'El sitemap está comprimido más de una vez.');
        }
        return $salida;
    }

    private function conEspacioDeNombres(DOMElement $raiz): bool
    {
        return $raiz->namespaceURI === 'http://www.sitemaps.org/schemas/sitemap/0.9';
    }

    private static function texto(DOMElement $padre, string $nombre): ?string
    {
        foreach ($padre->childNodes as $n) {
            if ($n instanceof DOMElement && $n->localName === $nombre) {
                return trim($n->textContent);
            }
        }
        return null;
    }
}
