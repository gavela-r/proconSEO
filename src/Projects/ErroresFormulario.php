<?php

declare(strict_types=1);

namespace ProconSEO\Projects;

use ProconSEO\Shared\Error\ErrorDominio;

/**
 * Errores de validación de un formulario, uno por campo. Se muestran junto a cada campo.
 */
final class ErroresFormulario extends ErrorDominio
{
    /**
     * @param array<string, string> $errores campo => mensaje para el usuario
     */
    public function __construct(public readonly array $errores)
    {
        parent::__construct('datos_no_validos', 'Revisa los campos marcados.', 422, ['campos' => $errores]);
    }
}
