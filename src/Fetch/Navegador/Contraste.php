<?php

declare(strict_types=1);

namespace ProconSEO\Fetch\Navegador;

/**
 * Contraste de texto según WCAG 2.2 (1.4.3): luminancia relativa y relación de contraste.
 *
 * El navegador da el color del texto y las capas de fondo que hay debajo (de arriba abajo);
 * aquí se mezclan las capas semitransparentes sobre el blanco del lienzo y se calcula la
 * relación. Texto grande: 24 px o más, o 18,66 px (14 pt) o más en negrita.
 */
final class Contraste
{
    /** Mínimo para texto normal y para texto grande (1.4.3 AA). */
    public const MINIMO_NORMAL = 4.5;
    public const MINIMO_GRANDE = 3.0;

    /**
     * @param array{color: list<float|int>, opacidad?: float|int, capas: list<list<float|int>>, tamano: float|int, peso: int} $muestra
     * @return array{ratio: float, minimo: float, cumple: bool, texto: list<int>, fondo: list<int>}
     */
    public static function medir(array $muestra): array
    {
        $fondo = [255.0, 255.0, 255.0];
        foreach (array_reverse($muestra['capas']) as $capa) {
            $fondo = self::mezclar(self::rgb($capa), (float) ($capa[3] ?? 1), $fondo);
        }
        $alfaTexto = (float) ($muestra['color'][3] ?? 1) * (float) ($muestra['opacidad'] ?? 1);
        $texto = self::mezclar(self::rgb($muestra['color']), $alfaTexto, $fondo);

        $ratio = self::ratio($texto, $fondo);
        $minimo = self::esGrande((float) $muestra['tamano'], $muestra['peso']) ? self::MINIMO_GRANDE : self::MINIMO_NORMAL;

        return [
            'ratio' => round($ratio, 2),
            // Se compara sin redondear: 4,49 no cumple aunque se muestre como 4,5.
            'cumple' => $ratio >= $minimo,
            'minimo' => $minimo,
            'texto' => array_map(static fn (float $v): int => (int) round($v), $texto),
            'fondo' => array_map(static fn (float $v): int => (int) round($v), $fondo),
        ];
    }

    public static function esGrande(float $tamanoPx, int $peso): bool
    {
        return $tamanoPx >= 24.0 || ($tamanoPx >= 18.66 && $peso >= 700);
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    public static function ratio(array $a, array $b): float
    {
        $la = self::luminancia($a);
        $lb = self::luminancia($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** @param list<float> $rgb */
    public static function luminancia(array $rgb): float
    {
        $lineal = array_map(static function (float $v): float {
            $c = $v / 255.0;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $lineal[0] + 0.7152 * $lineal[1] + 0.0722 * $lineal[2];
    }

    /** "#rrggbb" para mostrar. */
    public static function hex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x', ...array_map(static fn ($v): int => max(0, min(255, (int) $v)), $rgb));
    }

    /**
     * @param list<float> $color
     * @param list<float> $fondo
     * @return list<float>
     */
    private static function mezclar(array $color, float $alfa, array $fondo): array
    {
        $alfa = max(0.0, min(1.0, $alfa));

        return [
            $color[0] * $alfa + $fondo[0] * (1.0 - $alfa),
            $color[1] * $alfa + $fondo[1] * (1.0 - $alfa),
            $color[2] * $alfa + $fondo[2] * (1.0 - $alfa),
        ];
    }

    /**
     * @param list<float|int> $c
     * @return list<float>
     */
    private static function rgb(array $c): array
    {
        return [(float) $c[0], (float) $c[1], (float) $c[2]];
    }
}
