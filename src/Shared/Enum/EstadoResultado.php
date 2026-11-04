<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Enum;

/**
 * Estado de un resultado (Guía 0.2). No inventar otros.
 */
enum EstadoResultado: string
{
    use ConValores;

    case Correcto = 'correcto';
    case Mejorable = 'mejorable';
    case Problema = 'problema';
    case DecisionRevisar = 'decision_revisar';
    case NoVerificable = 'no_verificable';
    case NoAplicable = 'no_aplicable';

    /** Texto para mostrar en la interfaz. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Correcto => 'Correcto',
            self::Mejorable => 'Mejorable',
            self::Problema => 'Problema detectado',
            self::DecisionRevisar => 'Decisión a revisar',
            self::NoVerificable => 'No verificable',
            self::NoAplicable => 'No aplicable',
        };
    }
}
