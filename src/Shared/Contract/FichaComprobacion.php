<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Contract;

use ProconSEO\Shared\Enum\Bloque;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\Esfuerzo;
use ProconSEO\Shared\Enum\ModoEvaluacion;

/**
 * Ficha de una subcomprobación del catálogo (Anexo B.1). La Parte 2 las mantiene en catalog/v1/
 * y la Parte 3 las usa para mostrar nombres, explicación y referencia WCAG en el detalle.
 */
final class FichaComprobacion
{
    /**
     * @param array<string, mixed> $extra Campos adicionales de la ficha (aplicabilidad, ejemplos…)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $idCriterio,
        public readonly Bloque $bloque,
        public readonly string $nombre,
        public readonly string $queComprueba,
        public readonly Cobertura $cobertura,
        public readonly bool $enMvp,
        public readonly ?ModoEvaluacion $modo = null,
        public readonly ?Esfuerzo $esfuerzo = null,
        public readonly ?string $wcagCriterio = null,
        public readonly ?string $wcagNombre = null,
        public readonly ?string $wcagNivel = null,
        public readonly ?string $wcagVersion = null,
        public readonly ?string $referenciaInvestigacion = null,
        public readonly ?string $fuente = null,
        public readonly array $extra = [],
    ) {
    }
}
