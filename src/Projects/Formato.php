<?php

declare(strict_types=1);

namespace ProconSEO\Projects;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Formatos para mostrar datos en pantalla. Devuelven texto sin escapar: en las plantillas
 * se imprime siempre con e().
 */
final class Formato
{
    private const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic'];

    /** Fecha guardada en UTC → "4 nov 2026, 10:42" en hora de España. */
    public static function fecha(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }

        try {
            $fecha = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Madrid'));
        } catch (\Exception) {
            return $utc;
        }

        return sprintf('%d %s %s, %s', (int) $fecha->format('j'), self::MESES[(int) $fecha->format('n') - 1], $fecha->format('Y'), $fecha->format('H:i'));
    }

    /**
     * Recuento de resultados en una frase: "3 problemas · 1 mejorable · 2 correctos".
     * Es un recuento por estado, nunca una nota ni un porcentaje.
     *
     * @param array<string, int> $recuento estado => número
     */
    public static function recuento(array $recuento): string
    {
        $nombres = [
            'problema' => ['problema', 'problemas'],
            'mejorable' => ['mejorable', 'mejorables'],
            'decision_revisar' => ['decisión a revisar', 'decisiones a revisar'],
            'correcto' => ['correcto', 'correctos'],
            'no_verificable' => ['no verificable', 'no verificables'],
            'no_aplicable' => ['no aplicable', 'no aplicables'],
        ];

        $partes = [];
        foreach ($nombres as $estado => [$singular, $plural]) {
            $n = $recuento[$estado] ?? 0;
            if ($n > 0) {
                $partes[] = $n . ' ' . ($n === 1 ? $singular : $plural);
            }
        }

        return implode(' · ', $partes);
    }
}
