<?php

declare(strict_types=1);

use ProconSEO\Shared\Http\Csrf;

if (!function_exists('e')) {
    /**
     * Escapa un valor para mostrarlo en HTML. Úsalo con TODO dato que se imprima,
     * y especialmente con cualquier contenido de la web analizada (SEG-09).
     */
    function e(mixed $valor): string
    {
        return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('csrf_campo')) {
    /** Campo oculto con el token CSRF para incluir en cada formulario POST. */
    function csrf_campo(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
    }
}
