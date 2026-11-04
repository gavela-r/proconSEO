<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Enum;

/**
 * Estado de la ejecución de un análisis. Distinto del estado de cada resultado.
 */
enum EstadoAnalisis: string
{
    use ConValores;

    case EnCola = 'en_cola';
    case EnCurso = 'en_curso';
    case Cancelando = 'cancelando';
    case Completado = 'completado';
    case Parcial = 'parcial';
    case Cancelado = 'cancelado';
    case DetenidoError = 'detenido_error';
    case DetenidoSeguridad = 'detenido_seguridad';

    /** Texto para mostrar en la interfaz. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::EnCola => 'En cola',
            self::EnCurso => 'En curso',
            self::Cancelando => 'Cancelando',
            self::Completado => 'Completado',
            self::Parcial => 'Parcial',
            self::Cancelado => 'Cancelado',
            self::DetenidoError => 'Detenido por error',
            self::DetenidoSeguridad => 'Detenido por seguridad',
        };
    }
}
