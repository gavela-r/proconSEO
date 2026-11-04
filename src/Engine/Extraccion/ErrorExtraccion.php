<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Extraccion;

use RuntimeException;

/**
 * No se ha podido extraer un recurso (demasiado grande, ilegible o peligroso). Las
 * comprobaciones que dependen de él deben quedar como no_verificable con este motivo.
 */
final class ErrorExtraccion extends RuntimeException
{
    public function __construct(public readonly string $codigo, string $mensaje)
    {
        parent::__construct($mensaje);
    }
}
