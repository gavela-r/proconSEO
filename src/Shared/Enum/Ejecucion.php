<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Enum;

/**
 * Si la comprobación llegó a ejecutarse.
 */
enum Ejecucion: string
{
    use ConValores;

    case Ejecutada = 'ejecutada';
    case Parcial = 'parcial';
    case NoEjecutada = 'no_ejecutada';

    /** Texto para mostrar en la interfaz. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Ejecutada => 'Ejecutada',
            self::Parcial => 'Parcial',
            self::NoEjecutada => 'No ejecutada',
        };
    }
}
