<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Enum;

/**
 * Tipo de proyecto. Activa las comprobaciones condicionales (especificación 6.7 y 6.8).
 */
enum TipoProyecto: string
{
    use ConValores;

    case WebKitDigital = 'web_kit_digital';
    case TiendaKitDigital = 'tienda_kit_digital';
    case WebCliente = 'web_cliente';
    case WebProconsi = 'web_proconsi';
    case Otra = 'otra';

    /** Texto para mostrar en la interfaz. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::WebKitDigital => 'Web Kit Digital',
            self::TiendaKitDigital => 'Tienda Kit Digital',
            self::WebCliente => 'Web de cliente',
            self::WebProconsi => 'Web propia de Proconsi',
            self::Otra => 'Otra',
        };
    }
}
