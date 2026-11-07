<?php

declare(strict_types=1);

namespace ProconSEO\Results;

/**
 * Convierte la evidencia de contraste de PageSpeed (una línea por elemento, con la explicación de
 * Lighthouse en inglés) en filas legibles: qué colores, qué contraste tiene y cuál se exige.
 *
 * El texto viene de la web analizada y de Google, así que es un dato: aquí solo se extraen números
 * y colores con expresiones estrictas; lo demás se devuelve tal cual para imprimirlo escapado.
 * Los elementos repetidos (el mismo selector con los mismos colores) se juntan en una fila.
 */
final class LecturaContraste
{
    private const PATRON = '/contrast of ([\d.]+) \(foreground color: (#[0-9a-f]{3,8}), background color: (#[0-9a-f]{3,8}), font size: [\d.]+pt \(([\d.]+)px\), font weight: (\w+)\)/i';

    /**
     * @return list<array{selector: string, fragmento: string, contraste: float, texto: string, fondo: string, tamano: float, negrita: bool, exigido: float, veces: int}>
     *         lista vacía si la evidencia no tiene ese formato
     */
    public static function filas(string $evidencia): array
    {
        $filas = [];
        foreach (preg_split('/\R/', $evidencia) ?: [] as $linea) {
            if (preg_match(self::PATRON, $linea, $m) !== 1) {
                continue;
            }
            $partes = explode(' — ', $linea);
            $tamano = (float) $m[4];
            $negrita = in_array(strtolower($m[5]), ['bold', 'bolder', '600', '700', '800', '900'], true);
            // WCAG 1.4.3: texto grande (24 px, o 18,66 px en negrita) necesita 3:1; el resto, 4,5:1.
            $grande = $tamano >= 24 || ($negrita && $tamano >= 18.66);
            $fila = [
                'selector' => trim($partes[0] ?? ''),
                'fragmento' => trim($partes[1] ?? ''),
                'contraste' => (float) $m[1],
                'texto' => strtolower($m[2]),
                'fondo' => strtolower($m[3]),
                'tamano' => $tamano,
                'negrita' => $negrita,
                'exigido' => $grande ? 3.0 : 4.5,
                'veces' => 1,
            ];
            $clave = $fila['selector'] . '|' . $fila['texto'] . '|' . $fila['fondo'] . '|' . $m[1];
            if (isset($filas[$clave])) {
                $filas[$clave]['veces']++;
            } else {
                $filas[$clave] = $fila;
            }
        }

        return array_values($filas);
    }

    /** Un color de la evidencia solo se usa si es un hexadecimal válido (para la muestra en SVG). */
    public static function colorSeguro(string $color): ?string
    {
        return preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $color) === 1 ? $color : null;
    }

    public static function numero(float $n): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.'));
    }
}
