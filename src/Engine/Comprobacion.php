<?php

declare(strict_types=1);

namespace ProconSEO\Engine;

use ProconSEO\Shared\Contract\BorradorResultado;
use ProconSEO\Shared\Enum\Cobertura;

/**
 * Un grupo de subcomprobaciones del catálogo que se evalúan con la misma lógica.
 *
 * evaluar() se llama una vez por subcomprobación. Si falta un dato, lanza NoVerificable
 * (el motor lo convierte en no_verificable / no_ejecutada); cualquier otra excepción se trata
 * igual, para que un fallo en una comprobación nunca invente un resultado ni pare el análisis.
 */
interface Comprobacion
{
    /** @return array<string, Cobertura> id de subcomprobación => cobertura que implementa */
    public function subcomprobaciones(): array;

    public function evaluar(string $id, Entorno $entorno): BorradorResultado;
}
