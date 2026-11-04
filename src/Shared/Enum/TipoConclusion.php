<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Enum;

/**
 * Hecho, inferencia o hipótesis (Guía 0.2).
 */
enum TipoConclusion: string
{
    use ConValores;

    case Hecho = 'hecho';
    case Inferencia = 'inferencia';
    case Hipotesis = 'hipotesis';

    /** Texto para mostrar en la interfaz. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Hecho => 'Hecho',
            self::Inferencia => 'Inferencia',
            self::Hipotesis => 'Hipótesis',
        };
    }
}
