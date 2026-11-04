<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Enum;

/**
 * Si la comprobación está implementada en la herramienta.
 */
enum Cobertura: string
{
    use ConValores;

    case Implementada = 'implementada';
    case ParcialmenteImplementada = 'parcialmente_implementada';
    case NoImplementada = 'no_implementada';

    /** Texto para mostrar en la interfaz. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Implementada => 'Implementada',
            self::ParcialmenteImplementada => 'Parcialmente implementada',
            self::NoImplementada => 'No implementada',
        };
    }
}
