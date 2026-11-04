<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Enum;

/**
 * Fases del análisis, en orden. Son la base del progreso real.
 */
enum Fase: string
{
    use ConValores;

    case Validacion = 'validacion';
    case Recuperacion = 'recuperacion';
    case Descubrimiento = 'descubrimiento';
    case Renderizado = 'renderizado';
    case Extraccion = 'extraccion';
    case Comprobaciones = 'comprobaciones';
    case Consolidacion = 'consolidacion';
    case Cierre = 'cierre';

    /** Texto para mostrar en la interfaz. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Validacion => 'Validación de URL y ámbito',
            self::Recuperacion => 'Recuperación',
            self::Descubrimiento => 'Descubrimiento de URLs',
            self::Renderizado => 'Renderizado',
            self::Extraccion => 'Extracción de datos',
            self::Comprobaciones => 'Comprobaciones',
            self::Consolidacion => 'Agrupación por causa',
            self::Cierre => 'Cierre',
        };
    }
}
