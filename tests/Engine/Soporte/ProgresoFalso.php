<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Engine\Soporte;

use ProconSEO\Shared\Contract\InformadorProgreso;
use ProconSEO\Shared\Enum\Fase;

/** Guarda en memoria el progreso que informa el motor. */
final class ProgresoFalso implements InformadorProgreso
{
    /** @var list<array{fase: Fase, hechos: int, total: int, mensaje: string}> */
    public array $avisos = [];

    public function fase(Fase $fase, int $hechos, int $total, string $mensaje = ''): void
    {
        $this->avisos[] = ['fase' => $fase, 'hechos' => $hechos, 'total' => $total, 'mensaje' => $mensaje];
    }
}
