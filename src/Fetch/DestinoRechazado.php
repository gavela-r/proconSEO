<?php

declare(strict_types=1);

namespace ProconSEO\Fetch;

use ProconSEO\Shared\Error\ErrorDominio;

/**
 * Una URL o un destino de red que no se puede visitar por seguridad (SEG-01 / SEG-02).
 * Se lanza ANTES de abrir cualquier conexión.
 */
final class DestinoRechazado extends ErrorDominio
{
    public function __construct(
        string $codigo,
        string $mensaje,
        public readonly string $regla,
    ) {
        parent::__construct($codigo, $mensaje, 422, ['regla' => $regla]);
    }

    public static function url(string $codigo, string $mensaje): self
    {
        return new self($codigo, $mensaje, 'SEG-01');
    }

    public static function red(string $codigo, string $mensaje): self
    {
        return new self($codigo, $mensaje, 'SEG-02');
    }
}
