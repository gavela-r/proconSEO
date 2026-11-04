<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Enum;

/**
 * Decisión ante un riesgo (Guía 4, decisión de cierre).
 */
enum DecisionCierre: string
{
    use ConValores;

    case Continuar = 'continuar';
    case Limitar = 'limitar';
    case Detener = 'detener';

    /** Texto para mostrar en la interfaz. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Continuar => 'Continuar',
            self::Limitar => 'Limitar',
            self::Detener => 'Detener',
        };
    }
}
