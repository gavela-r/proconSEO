<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Enum;

/**
 * Sector del cliente, para el tipo Web de cliente.
 */
enum Sector: string
{
    use ConValores;

    case Hidrocarburos = 'hidrocarburos';
    case Transporte = 'transporte';
    case Salud = 'salud';
    case Alimentacion = 'alimentacion';
    case Otro = 'otro';

    /** Texto para mostrar en la interfaz. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Hidrocarburos => 'Hidrocarburos',
            self::Transporte => 'Transporte',
            self::Salud => 'Salud',
            self::Alimentacion => 'Alimentación',
            self::Otro => 'Otro',
        };
    }
}
