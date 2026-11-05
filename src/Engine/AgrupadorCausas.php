<?php

declare(strict_types=1);

namespace ProconSEO\Engine;

use ProconSEO\Shared\Contract\BorradorGrupoCausa;
use ProconSEO\Shared\Contract\BorradorResultado;

/**
 * Fase 7 del análisis: agrupa los resultados que se deben a la misma causa (RF-24).
 *
 * Solo cambia el campo codigoGrupoCausa: el estado, la gravedad y el resto del resultado
 * quedan exactamente igual. Una causa con un único resultado no forma grupo, porque no hay nada
 * que dejar de repetir; salvo en el sitio completo, donde la misma causa suele repetirse en otras
 * páginas y la Parte 1 junta los grupos del mismo código en uno solo para todo el análisis.
 */
final class AgrupadorCausas
{
    public const MINIMO = 2;

    /**
     * @param list<BorradorResultado> $resultados
     * @param int                     $minimo     Resultados que necesita una causa para formar grupo
     * @return array{0: list<BorradorResultado>, 1: list<BorradorGrupoCausa>}
     */
    public function agrupar(array $resultados, bool $paginaDisponible, int $minimo = self::MINIMO): array
    {
        $causas = [];
        $cuenta = [];
        foreach ($resultados as $i => $r) {
            $codigo = $r->codigoGrupoCausa ?? Causas::porRegla($r->idComprobacion, $r->estado, $paginaDisponible);
            if ($codigo !== null && Causas::existe($codigo)) {
                $causas[$i] = $codigo;
                $cuenta[$codigo] = ($cuenta[$codigo] ?? 0) + 1;
            }
        }

        $grupos = [];
        $salida = [];
        foreach ($resultados as $i => $r) {
            $codigo = $causas[$i] ?? null;
            if ($codigo !== null && $cuenta[$codigo] < max(1, $minimo)) {
                $codigo = null;
            }
            if ($codigo !== null && !isset($grupos[$codigo])) {
                $grupos[$codigo] = new BorradorGrupoCausa($codigo, Causas::descripcion($codigo));
            }
            $salida[] = $codigo === $r->codigoGrupoCausa ? $r : self::conCausa($r, $codigo);
        }

        return [$salida, array_values($grupos)];
    }

    /** Copia del resultado con otra causa. El constructor vuelve a validar todo. */
    public static function conCausa(BorradorResultado $r, ?string $codigo): BorradorResultado
    {
        $campos = get_object_vars($r);
        $campos['codigoGrupoCausa'] = $codigo;
        return new BorradorResultado(...$campos);
    }
}
