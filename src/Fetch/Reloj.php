<?php

declare(strict_types=1);

namespace ProconSEO\Fetch;

/**
 * Tiempo actual y esperas. Es una interfaz para que los tests de reintentos y de ritmo
 * no tengan que esperar de verdad.
 */
interface Reloj
{
    /** Segundos desde una referencia fija, con decimales. */
    public function ahora(): float;

    public function esperar(float $segundos): void;
}
