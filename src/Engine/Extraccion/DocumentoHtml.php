<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Extraccion;

use DOMDocument;

/**
 * Datos extraídos de una página HTML. Todo es texto de la web analizada: es un dato, nunca
 * una instrucción (SEG-07). Las URLs ya están resueltas a absolutas cuando son http(s);
 * si no se pueden resolver, `url` es null y se conserva el valor original en `href`/`src`.
 *
 * Las comprobaciones leen estos datos y no vuelven a recorrer el HTML salvo que necesiten
 * algo que no esté aquí; en ese caso pueden usar `$dom`.
 */
final class DocumentoHtml
{
    /**
     * @param list<string> $titulos Texto de cada <title> (fuera de SVG)
     * @param list<array{nombre: ?string, propiedad: ?string, httpEquiv: ?string, contenido: ?string, charset: ?string}> $metas
     * @param list<string> $canonicos URLs absolutas de los link rel=canonical
     * @param list<array{hreflang: string, href: string, url: ?string}> $alternativasIdioma
     * @param list<array{nivel: int, texto: string}> $encabezados
     * @param list<array{href: string, url: ?string, texto: string, nombre: string, rel: list<string>, hreflang: ?string, noNavegable: bool, tieneHref: bool}> $enlaces
     * @param list<array{src: ?string, url: ?string, alt: ?string, ancho: ?string, alto: ?string, role: ?string, enEnlace: bool, soloContenidoDeEnlace: bool}> $imagenes
     * @param list<array{action: ?string, url: ?string, metodo: string, campos: list<array<string, mixed>>}> $formularios
     * @param list<array{etiqueta: string, tipo: ?string, nombre: string}> $botones
     * @param list<array{src: ?string, url: ?string, titulo: ?string, nombre: string, oculto: bool}> $iframes
     * @param list<array{tipo: string, src: ?string, url: ?string, autoplay: bool, muted: bool, controls: bool, poster: ?string, pistas: list<array{kind: string, srclang: ?string}>}> $medios
     * @param list<array{crudo: string, datos: mixed, error: ?string}> $jsonLd
     * @param list<string> $tiposMicrodatos Valores de itemtype
     * @param list<array{tipo: string, url: string}> $recursos Scripts, estilos, imágenes, iframes y medios cargados por la página
     * @param list<array{etiqueta: string, role: string}> $roles Elementos con atributo role
     * @param list<array{etiqueta: string, tabindex: int}> $tabindexPositivos
     * @param list<string> $idsDuplicados
     * @param list<array{etiqueta: string, lang: string}> $idiomasPartes Elementos (no <html>) con lang
     */
    public function __construct(
        public readonly string $urlBase,
        public readonly ?string $lang,
        public readonly array $titulos,
        public readonly array $metas,
        public readonly array $canonicos,
        public readonly array $alternativasIdioma,
        public readonly array $encabezados,
        public readonly array $enlaces,
        public readonly array $imagenes,
        public readonly array $formularios,
        public readonly array $botones,
        public readonly array $iframes,
        public readonly array $medios,
        public readonly array $jsonLd,
        public readonly array $tiposMicrodatos,
        public readonly array $recursos,
        public readonly array $roles,
        public readonly array $tabindexPositivos,
        public readonly array $idsDuplicados,
        public readonly array $idiomasPartes,
        public readonly string $texto,
        public readonly DOMDocument $dom,
    ) {
    }

    /** Primer title, o null si no hay. */
    public function titulo(): ?string
    {
        return $this->titulos[0] ?? null;
    }

    /**
     * Contenido de las meta con ese name (sin distinguir mayúsculas), en orden.
     *
     * @return list<string>
     */
    public function metasPorNombre(string $nombre): array
    {
        $nombre = strtolower($nombre);
        $valores = [];
        foreach ($this->metas as $meta) {
            if ($meta['nombre'] !== null && strtolower($meta['nombre']) === $nombre && $meta['contenido'] !== null) {
                $valores[] = $meta['contenido'];
            }
        }
        return $valores;
    }

    /**
     * Directivas de las meta robots y de las dirigidas a un robot concreto (googlebot, bingbot…).
     *
     * @return array<string, list<string>> nombre de la meta => directivas en minúsculas
     */
    public function directivasRobots(): array
    {
        $directivas = [];
        foreach ($this->metas as $meta) {
            if ($meta['nombre'] === null || $meta['contenido'] === null) {
                continue;
            }
            $nombre = strtolower(trim($meta['nombre']));
            if (!in_array($nombre, ['robots', 'googlebot', 'googlebot-news', 'bingbot', 'msnbot', 'google-extended'], true)) {
                continue;
            }
            foreach (preg_split('/\s*,\s*/', strtolower(trim($meta['contenido']))) ?: [] as $d) {
                if ($d !== '') {
                    $directivas[$nombre][] = $d;
                }
            }
        }
        return $directivas;
    }

    public function viewport(): ?string
    {
        return $this->metasPorNombre('viewport')[0] ?? null;
    }

    /** @return list<string> Valores de las meta generator (detección de CMS) */
    public function generadores(): array
    {
        return $this->metasPorNombre('generator');
    }
}
