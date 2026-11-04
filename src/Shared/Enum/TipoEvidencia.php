<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Enum;

/**
 * Tipo de evidencia.
 */
enum TipoEvidencia: string
{
    use ConValores;

    case Texto = 'texto';
    case FragmentoHtml = 'fragmento_html';
    case IntercambioHttp = 'intercambio_http';
    case Medicion = 'medicion';
    case Captura = 'captura';
    case MetadatosFichero = 'metadatos_fichero';

    /** Texto para mostrar en la interfaz. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Texto => 'Texto',
            self::FragmentoHtml => 'Fragmento HTML',
            self::IntercambioHttp => 'Respuesta HTTP',
            self::Medicion => 'Medición',
            self::Captura => 'Captura',
            self::MetadatosFichero => 'Metadatos de fichero',
        };
    }
}
