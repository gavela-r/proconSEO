<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Contract;

use ProconSEO\Shared\Enum\Fase;

/**
 * Permite al motor informar del progreso real. Lo implementa la Parte 1, que lo guarda
 * en fases_analisis para mostrarlo en pantalla.
 */
interface InformadorProgreso
{
    public function fase(Fase $fase, int $hechos, int $total, string $mensaje = ''): void;
}
