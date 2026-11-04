<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Enum;

/**
 * Estado de una fase del análisis.
 */
enum EstadoFase: string
{
    use ConValores;

    case Pendiente = 'pendiente';
    case EnCurso = 'en_curso';
    case Terminada = 'terminada';
    case Parcial = 'parcial';
    case Omitida = 'omitida';
    case Fallida = 'fallida';

    /** Texto para mostrar en la interfaz. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::EnCurso => 'En curso',
            self::Terminada => 'Terminada',
            self::Parcial => 'Parcial',
            self::Omitida => 'Omitida',
            self::Fallida => 'Fallida',
        };
    }
}
