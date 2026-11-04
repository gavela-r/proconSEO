<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Contract;

/**
 * El motor lo consulta entre comprobaciones. Si devuelve true, deja de lanzar trabajo nuevo
 * y devuelve lo que ya tenga: las comprobaciones pendientes quedan como no_ejecutada.
 * Lo implementa la Parte 1.
 */
interface TokenCancelacion
{
    public function estaCancelado(): bool;
}
