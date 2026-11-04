<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Enum;

/**
 * Los tres bloques que analiza la herramienta.
 */
enum Bloque: string
{
    use ConValores;

    case Ia = 'ia';
    case Seo = 'seo';
    case Acc = 'acc';

    /** Texto para mostrar en la interfaz. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Ia => 'Búsqueda con IA',
            self::Seo => 'SEO',
            self::Acc => 'Accesibilidad',
        };
    }
}
