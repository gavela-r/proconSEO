<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Contract;

/**
 * Todo lo que devuelve el motor de un análisis. La Parte 1 lo guarda tal cual (tras sanitizar).
 */
final class SalidaMotor
{
    /**
     * @param list<BorradorResultado>  $resultados  Uno por subcomprobación del catálogo, incluidas las no implementadas
     * @param list<BorradorEvidencia>  $evidencias
     * @param list<BorradorGrupoCausa> $gruposCausa
     * @param list<SenalSeguridad>     $senales
     */
    public function __construct(
        public readonly array $resultados = [],
        public readonly array $evidencias = [],
        public readonly array $gruposCausa = [],
        public readonly array $senales = [],
    ) {
    }
}
