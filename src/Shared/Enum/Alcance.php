<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Enum;

/**
 * Alcance del análisis (Guía 0.1).
 */
enum Alcance: string
{
    use ConValores;

    case UrlIndividual = 'url_individual';
    case UrlContexto = 'url_contexto';
    case SitioCompleto = 'sitio_completo';

    /** Texto para mostrar en la interfaz. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::UrlIndividual => 'URL individual',
            self::UrlContexto => 'URL con contexto',
            self::SitioCompleto => 'Sitio completo',
        };
    }
}
