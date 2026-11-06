<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Contract;

/**
 * Lo poco que hace falta de cada página para las comprobaciones de todo el sitio (title o
 * description repetidos, páginas sin enlaces internos, variantes con parámetros, texto casi
 * igual entre páginas y orden del menú).
 *
 * Lo crea el motor al analizar cada página (SalidaMotor::$resumen) y la Parte 1 lo guarda en
 * memoria hasta el final del sitio completo para pasárselo a ConsolidadorSitio. No lleva el
 * HTML: unos pocos KB por página. Los textos vienen de la web analizada: son datos.
 */
final class ResumenPagina
{
    /** Como mucho, enlaces internos que se guardan por página. */
    public const MAXIMO_ENLACES = 500;

    /** Como mucho, enlaces del menú que se guardan por página. */
    public const MAXIMO_MENU = 50;

    /** Como mucho, números de la huella del texto. */
    public const MAXIMO_HUELLA = 128;

    /**
     * @param string       $url             URL final de la página (tras redirecciones)
     * @param bool         $indexable       Sin noindex en meta robots ni en X-Robots-Tag
     * @param list<string> $titulos         Texto de cada <title>
     * @param list<string> $descripciones   Contenido de cada meta description
     * @param string|null  $canonical       Primer canonical, como URL absoluta
     * @param list<string> $enlacesInternos Páginas del ámbito enlazadas desde esta, absolutas y sin fragmento
     * @param list<int>    $huellaTexto     Huella del texto principal para compararlo con otras páginas sin
     *                                      guardarlo (MinHash: unos números por página); vacía si no se calculó
     * @param int          $palabrasTexto   Palabras del texto principal (las páginas casi vacías no se comparan)
     * @param list<string> $menu            Destinos de los enlaces del menú principal, en el orden en que aparecen
     */
    public function __construct(
        public readonly string $url,
        public readonly ?int $estadoHttp,
        public readonly bool $indexable,
        public readonly array $titulos = [],
        public readonly array $descripciones = [],
        public readonly ?string $canonical = null,
        public readonly array $enlacesInternos = [],
        public readonly array $huellaTexto = [],
        public readonly int $palabrasTexto = 0,
        public readonly array $menu = [],
    ) {
    }
}
