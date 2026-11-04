<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Contract;

/**
 * Catálogo de subcomprobaciones. Lo implementa la Parte 2 leyendo catalog/v1/.
 */
interface Catalogo
{
    /** Versión del catálogo, que se guarda en cada análisis. */
    public function version(): string;

    /** @return list<FichaComprobacion> */
    public function todas(): array;

    public function obtener(string $idComprobacion): ?FichaComprobacion;
}
