<?php

declare(strict_types=1);

namespace ProconSEO\Engine;

/**
 * Interpreta el objetivo de visibilidad que se escribe al lanzar el análisis (texto libre).
 *
 * Solo se reconoce la intención de no indexar (especificación 10: con el objetivo «no indexar»
 * declarado, un noindex es correcto). El objetivo es un dato del usuario de Proconsi, no de la
 * web analizada.
 */
final class Objetivo
{
    private const NO_INDEXAR = [
        'no_indexar', 'no indexar', 'sin indexar', 'noindex', 'no index', 'no aparecer', 'que no aparezca',
        'no salir en', 'que no salga', 'ocultar a los buscadores', 'oculta a los buscadores',
    ];

    public static function pideNoIndexar(?string $objetivo): bool
    {
        if ($objetivo === null || trim($objetivo) === '') {
            return false;
        }
        $texto = mb_strtolower(trim($objetivo));
        $texto = strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
        foreach (self::NO_INDEXAR as $frase) {
            if (str_contains($texto, $frase)) {
                return true;
            }
        }
        return false;
    }
}
