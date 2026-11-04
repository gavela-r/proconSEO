<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Contract;

/**
 * Una causa compartida por varios resultados (Guía 0.3): se muestra una vez, con sus efectos
 * en cada bloque, sin repetir recomendaciones.
 */
final class BorradorGrupoCausa
{
    public function __construct(
        public readonly string $codigo,
        public readonly string $descripcion,
    ) {
    }
}
