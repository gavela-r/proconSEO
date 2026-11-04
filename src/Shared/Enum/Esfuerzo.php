<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Enum;

/**
 * Esfuerzo orientativo de corrección, fijado en el catálogo.
 */
enum Esfuerzo: string
{
    use ConValores;

    case Bajo = 'bajo';
    case Medio = 'medio';
    case Alto = 'alto';

    /** Texto para mostrar en la interfaz. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Bajo => 'Bajo',
            self::Medio => 'Medio',
            self::Alto => 'Alto',
        };
    }
}
