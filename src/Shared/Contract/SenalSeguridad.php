<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Contract;

use ProconSEO\Shared\Enum\DecisionCierre;

/**
 * Algo relevante para la seguridad del analizador detectado por el motor, p. ej. texto de la
 * página dirigido al analizador (SEG-07). Es informativo: nunca cambia un resultado.
 * La Parte 1 lo guarda en eventos_seguridad.
 */
final class SenalSeguridad
{
    public function __construct(
        public readonly string $regla,
        public readonly string $descripcion,
        public readonly DecisionCierre $decision = DecisionCierre::Continuar,
        public readonly ?string $urlRecurso = null,
    ) {
    }
}
