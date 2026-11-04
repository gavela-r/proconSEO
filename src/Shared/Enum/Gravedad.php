<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Enum;

/**
 * Gravedad. Solo para resultados en estado problema.
 */
enum Gravedad: string
{
    use ConValores;

    case Critico = 'critico';
    case Alto = 'alto';
    case Medio = 'medio';
    case Bajo = 'bajo';

    /** Texto para mostrar en la interfaz. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Critico => 'Crítico',
            self::Alto => 'Alto',
            self::Medio => 'Medio',
            self::Bajo => 'Bajo',
        };
    }
}
