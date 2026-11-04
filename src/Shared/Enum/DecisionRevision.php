<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Enum;

/**
 * Decisión tomada en una revisión humana.
 */
enum DecisionRevision: string
{
    use ConValores;

    case Confirmar = 'confirmar';
    case CambiarEstado = 'cambiar_estado';
    case MantenerNoVerificable = 'mantener_no_verificable';
    case PedirEvidencia = 'pedir_evidencia';

    /** Texto para mostrar en la interfaz. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Confirmar => 'Confirmar resultado',
            self::CambiarEstado => 'Cambiar estado',
            self::MantenerNoVerificable => 'Mantener como no verificable',
            self::PedirEvidencia => 'Pedir más evidencia',
        };
    }
}
