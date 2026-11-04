<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Fetch;

use ProconSEO\Fetch\Reloj;

final class RelojFalso implements Reloj
{
    private float $tiempo = 1000.0;
    /** @var list<float> */
    public array $esperas = [];

    public function ahora(): float
    {
        return $this->tiempo;
    }

    public function esperar(float $segundos): void
    {
        if ($segundos > 0) {
            $this->esperas[] = $segundos;
            $this->tiempo += $segundos;
        }
    }
}
