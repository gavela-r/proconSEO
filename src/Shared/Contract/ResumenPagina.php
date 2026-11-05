<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Contract;

/**
 * Lo poco que hace falta de cada página para las comprobaciones de todo el sitio (title o
 * description repetidos, páginas sin enlaces internos, variantes con parámetros).
 *
 * Lo crea el motor al analizar cada página (SalidaMotor::$resumen) y la Parte 1 lo guarda en
 * memoria hasta el final del sitio completo para pasárselo a ConsolidadorSitio. No lleva el
 * HTML: unos pocos KB por página. Los textos vienen de la web analizada: son datos.
 */
final class ResumenPagina
{
    /** Como mucho, enlaces internos que se guardan por página. */
    public const MAXIMO_ENLACES = 500;

    /**
     * @param string       $url             URL final de la página (tras redirecciones)
     * @param bool         $indexable       Sin noindex en meta robots ni en X-Robots-Tag
     * @param list<string> $titulos         Texto de cada <title>
     * @param list<string> $descripciones   Contenido de cada meta description
     * @param string|null  $canonical       Primer canonical, como URL absoluta
     * @param list<string> $enlacesInternos Páginas del ámbito enlazadas desde esta, absolutas y sin fragmento
     */
    public function __construct(
        public readonly string $url,
        public readonly ?int $estadoHttp,
        public readonly bool $indexable,
        public readonly array $titulos = [],
        public readonly array $descripciones = [],
        public readonly ?string $canonical = null,
        public readonly array $enlacesInternos = [],
    ) {
    }
}
