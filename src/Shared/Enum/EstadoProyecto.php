<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Enum;

/**
 * Estado de un proyecto.
 */
enum EstadoProyecto: string
{
    use ConValores;

    case Activo = 'activo';
    case EnPausa = 'en_pausa';
    case Cerrado = 'cerrado';
    case Archivado = 'archivado';

    /** Texto para mostrar en la interfaz. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Activo => 'Activo',
            self::EnPausa => 'En pausa',
            self::Cerrado => 'Cerrado',
            self::Archivado => 'Archivado',
        };
    }
}
