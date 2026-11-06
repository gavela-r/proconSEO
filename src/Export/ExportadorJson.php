<?php

declare(strict_types=1);

namespace ProconSEO\Export;

/**
 * Exportación en JSON (RF-50): todos los datos, legibles y sin perder nada.
 * JSON_HEX_TAG convierte < y > en < y >, para que el contenido de la web analizada
 * no se pueda interpretar como HTML si alguien abre el fichero en un navegador.
 */
final class ExportadorJson
{
    public static function generar(array $datos): string
    {
        return json_encode(
            $datos,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }
}
