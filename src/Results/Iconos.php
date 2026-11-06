<?php

declare(strict_types=1);

namespace ProconSEO\Results;

/**
 * Iconos de los estados de resultado (diseño de Stitch, docs del zip «proconseo_marca_proconsi»).
 * SVG en línea y propios: no se carga ninguna fuente de iconos externa (CSP y SEG-10).
 * El icono es decorativo (aria-hidden): el estado siempre va escrito al lado, nunca solo el color.
 * Son cadenas fijas, sin datos de entrada.
 */
final class Iconos
{
    private const TRAZOS = [
        'correcto' => '<path d="M3 8.5l3 3 7-7"/>',
        'mejorable' => '<path d="M8 13V3M4 7l4-4 4 4"/>',
        'problema' => '<path d="M8 2.2L14.8 13.8H1.2Z"/><path d="M8 6.5v3.2"/><circle cx="8" cy="11.6" r=".4" fill="currentColor"/>',
        'decision_revisar' => '<path d="M8 2.5v11M4.5 13.5h7M3 5h10"/><path d="M3 5l-1.8 4.2h3.6ZM13 5l-1.8 4.2h3.6Z"/>',
        'no_verificable' => '<circle cx="8" cy="8" r="6.3"/><path d="M6.3 6.4a1.8 1.8 0 1 1 2.5 1.7c-.5.2-.8.6-.8 1.1v.3"/><circle cx="8" cy="11.4" r=".4" fill="currentColor"/>',
        'no_aplicable' => '<path d="M4 8h8"/>',
    ];

    public static function estado(?string $estado): string
    {
        $trazo = self::TRAZOS[(string) $estado] ?? null;
        if ($trazo === null) {
            return '';
        }

        return '<svg class="icono" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">'
            . $trazo . '</svg>';
    }
}
