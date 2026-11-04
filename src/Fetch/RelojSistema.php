<?php

declare(strict_types=1);

namespace ProconSEO\Fetch;

final class RelojSistema implements Reloj
{
    public function ahora(): float
    {
        return hrtime(true) / 1e9;
    }

    public function esperar(float $segundos): void
    {
        if ($segundos > 0) {
            usleep((int) round($segundos * 1e6));
        }
    }
}
