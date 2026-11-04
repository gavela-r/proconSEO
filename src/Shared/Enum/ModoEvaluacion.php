<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Enum;

/**
 * Modo de evaluación de una subcomprobación de accesibilidad.
 */
enum ModoEvaluacion: string
{
    use ConValores;

    case Automatico = 'automatico';
    case Hibrido = 'hibrido';
    case Humano = 'humano';

    /** Texto para mostrar en la interfaz. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Automatico => 'Automático',
            self::Hibrido => 'Híbrido',
            self::Humano => 'Humano',
        };
    }
}
