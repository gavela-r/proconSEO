<?php

declare(strict_types=1);

namespace ProconSEO\Results;

use ProconSEO\Shared\Enum\Bloque;
use ProconSEO\Shared\Enum\EstadoResultado;
use ProconSEO\Shared\Enum\Gravedad;

/**
 * Textos de la interfaz para valores guardados. Devuelven texto sin escapar: en las
 * plantillas se imprimen siempre con e(). Si un valor no se reconoce, se devuelve tal cual.
 */
final class Etiquetas
{
    /** Criterios nuevos en WCAG 2.2 (especificación 6.3): no forman parte del compromiso 2.1 AA. */
    public const WCAG_NUEVOS_22 = ['2.4.11', '2.5.7', '2.5.8', '3.2.6', '3.3.7', '3.3.8'];

    public static function estado(?string $valor): string
    {
        return EstadoResultado::tryFrom((string) $valor)?->etiqueta() ?? (string) $valor;
    }

    public static function bloque(?string $valor): string
    {
        return Bloque::tryFrom((string) $valor)?->etiqueta() ?? (string) $valor;
    }

    /** Gravedad solo si el estado vigente es "problema" (la gravedad no se aplica a otros estados). */
    public static function gravedad(array $resultado): ?string
    {
        if ($resultado['estado_vigente'] !== EstadoResultado::Problema->value) {
            return null;
        }
        $gravedad = Gravedad::tryFrom((string) $resultado['gravedad']);

        return $gravedad === null ? null : 'Gravedad: ' . mb_strtolower($gravedad->etiqueta());
    }

    public static function claseGravedad(array $resultado): string
    {
        return Gravedad::tryFrom((string) $resultado['gravedad'])?->value ?? '';
    }

    /** "WCAG 1.1.1 · A · en 2.1" o "WCAG 2.5.8 · AA · nuevo en 2.2"; null si no es un criterio WCAG. */
    public static function wcag(array $resultado): ?string
    {
        $criterio = $resultado['wcag_criterio'] ?? null;
        if ($criterio === null || $criterio === '') {
            return null;
        }

        $partes = ['WCAG ' . $criterio];
        if (($resultado['wcag_nivel'] ?? null) !== null) {
            $partes[] = (string) $resultado['wcag_nivel'];
        }
        $partes[] = self::esNuevo22($resultado) ? 'nuevo en 2.2' : 'en 2.1';

        return implode(' · ', $partes);
    }

    /**
     * "Normativa: Real Decreto 1112/2018" cuando el resultado sale de una norma y no de un criterio WCAG
     * (p. ej. la declaración de accesibilidad). Si tiene referencia WCAG, se muestra esa y esto devuelve null.
     */
    public static function normativa(array $resultado): ?string
    {
        $normativa = $resultado['normativa'] ?? null;
        if (self::wcag($resultado) !== null || !is_string($normativa) || trim($normativa) === '') {
            return null;
        }

        return 'Normativa: ' . trim($normativa);
    }

    public static function esNuevo22(array $resultado): bool
    {
        return ($resultado['wcag_version'] ?? null) === '2.2'
            || in_array($resultado['wcag_criterio'] ?? null, self::WCAG_NUEVOS_22, true);
    }

    /** Texto de un valor guardado como JSON (lista de hosts, etc.), o null si no es una lista. */
    public static function lista(?string $json): ?string
    {
        if ($json === null || $json === '') {
            return null;
        }
        $valores = json_decode($json, true);

        return is_array($valores) ? implode(', ', array_map('strval', $valores)) : null;
    }

    /** Tamaño legible: "47 KB". */
    public static function bytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1048576 => number_format($bytes / 1048576, 1, ',', '') . ' MB',
            $bytes >= 1024 => number_format($bytes / 1024, 0, ',', '') . ' KB',
            default => $bytes . ' bytes',
        };
    }
}
