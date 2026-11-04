<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Enum;

/**
 * Qué pasó al intentar descargar un recurso.
 */
enum ResultadoDescarga: string
{
    use ConValores;

    case Correcto = 'correcto';
    case ErrorHttp = 'error_http';
    case BloqueadoSeguridad = 'bloqueado_seguridad';
    case FueraDeAmbito = 'fuera_de_ambito';
    case DemasiadoGrande = 'demasiado_grande';
    case TiempoAgotado = 'tiempo_agotado';
    case ErrorTls = 'error_tls';
    case ErrorRed = 'error_red';
    case Limitado429 = 'limitado_429';

    /** Texto para mostrar en la interfaz. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Correcto => 'Descargado',
            self::ErrorHttp => 'Error HTTP',
            self::BloqueadoSeguridad => 'Bloqueado por seguridad',
            self::FueraDeAmbito => 'Fuera del ámbito',
            self::DemasiadoGrande => 'Demasiado grande',
            self::TiempoAgotado => 'Tiempo agotado',
            self::ErrorTls => 'Error de certificado',
            self::ErrorRed => 'Error de red',
            self::Limitado429 => 'Limitado por el servidor (429)',
        };
    }
}
