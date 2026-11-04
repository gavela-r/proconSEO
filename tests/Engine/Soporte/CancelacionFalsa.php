<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Engine\Soporte;

use ProconSEO\Shared\Contract\TokenCancelacion;

/**
 * Token de cancelación controlable: sin cancelar, cancelado desde el principio o
 * cancelado tras un número de consultas.
 */
final class CancelacionFalsa implements TokenCancelacion
{
    private int $consultas = 0;

    public function __construct(private readonly ?int $cancelarTrasConsultas = null)
    {
    }

    public static function nunca(): self
    {
        return new self(null);
    }

    public static function yaCancelado(): self
    {
        return new self(0);
    }

    public function estaCancelado(): bool
    {
        $cancelado = $this->cancelarTrasConsultas !== null && $this->consultas >= $this->cancelarTrasConsultas;
        $this->consultas++;
        return $cancelado;
    }
}
