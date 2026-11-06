<?php

declare(strict_types=1);

namespace ProconSEO\Results;

use ProconSEO\Shared\Enum\Gravedad;

/**
 * Qué ha cambiado entre dos análisis de la misma dirección (especificación, «Análisis
 * programados y comparación entre análisis»). Se compara cada comprobación en cada página
 * o recurso, con el estado vigente (después de las revisiones humanas).
 *
 * - Arreglado: antes era un problema o una mejora y ahora está bien (o ya no aplica).
 * - Nuevo: ahora es un problema o una mejora y antes estaba bien o no se comprobaba.
 * - Empeorado / mejorado: sigue sin estar bien, pero es más o menos grave que antes.
 * - Sigue igual: el mismo problema o mejora, con la misma gravedad.
 *
 * Lo que en alguno de los dos análisis quedó sin comprobar (no verificable, decisión a revisar)
 * no se compara: no se puede afirmar que haya cambiado. Tampoco lo que solo existe en uno de los
 * dos, salvo los «nuevos» (por ejemplo, una página que antes no se analizó).
 */
final class ComparacionAnalisis
{
    public const ARREGLADOS = 'arreglados';
    public const NUEVOS = 'nuevos';
    public const EMPEORADOS = 'empeorados';
    public const MEJORADOS = 'mejorados';
    public const SIGUEN = 'siguen';

    /** Estados que no permiten decir si algo ha cambiado. */
    private const SIN_CONCLUSION = ['no_verificable', 'decision_revisar'];

    /** @var array<string, list<array{ahora: ?array<string, mixed>, antes: ?array<string, mixed>}>> */
    public readonly array $grupos;

    /** Comprobaciones que no se pueden comparar porque en alguno de los dos análisis no se pudo concluir. */
    public readonly int $sinComparar;

    /** Comprobaciones con conclusión en los dos análisis (estén bien o mal): lo que de verdad se ha comparado. */
    public readonly int $comparadas;

    /**
     * @param list<array<string, mixed>> $antes Resultados del análisis anterior (con estado_vigente)
     * @param list<array<string, mixed>> $ahora Resultados de este análisis (con estado_vigente)
     */
    public function __construct(array $antes, array $ahora)
    {
        $previos = self::unicos($antes);

        $grupos = array_fill_keys([self::NUEVOS, self::EMPEORADOS, self::ARREGLADOS, self::MEJORADOS, self::SIGUEN], []);
        $sinComparar = 0;
        $comparadas = 0;
        foreach (self::unicos($ahora) as $clave => $r) {
            $previo = $previos[$clave] ?? null;
            unset($previos[$clave]);

            if ($previo !== null && !self::sinConclusion($r) && !self::sinConclusion($previo)) {
                $comparadas++;
            }
            $grupo = self::grupo($previo, $r, $sinComparar);
            if ($grupo !== null) {
                $grupos[$grupo][] = ['ahora' => $r, 'antes' => $previo];
            }
        }
        // Lo que había antes y ahora no aparece (p. ej., una página que esta vez no se analizó): no se sabe.
        foreach ($previos as $previo) {
            if (self::rango($previo) > 0) {
                $sinComparar++;
            }
        }

        foreach ($grupos as &$lista) {
            usort($lista, static fn (array $a, array $b): int => self::rango($b['ahora'] ?? []) <=> self::rango($a['ahora'] ?? []));
        }
        unset($lista);

        $this->grupos = $grupos;
        $this->sinComparar = $sinComparar;
        $this->comparadas = $comparadas;
    }

    public function hayCambios(): bool
    {
        return $this->grupos[self::NUEVOS] !== [] || $this->grupos[self::EMPEORADOS] !== []
            || $this->grupos[self::ARREGLADOS] !== [] || $this->grupos[self::MEJORADOS] !== [];
    }

    /** @return array<string, int> */
    public function recuento(): array
    {
        return array_map('count', $this->grupos);
    }

    /**
     * @param array<string, mixed>|null $antes
     * @param array<string, mixed>      $ahora
     */
    private static function grupo(?array $antes, array $ahora, int &$sinComparar): ?string
    {
        if (self::sinConclusion($ahora) || ($antes !== null && self::sinConclusion($antes))) {
            if (self::rango($ahora) > 0 || ($antes !== null && self::rango($antes) > 0)) {
                $sinComparar++;
            }

            return null;
        }
        $ahoraRango = self::rango($ahora);
        $antesRango = $antes === null ? 0 : self::rango($antes);

        return match (true) {
            $antesRango === 0 && $ahoraRango === 0 => null,
            $antesRango === 0 => self::NUEVOS,
            $ahoraRango === 0 => self::ARREGLADOS,
            $ahoraRango > $antesRango => self::EMPEORADOS,
            $ahoraRango < $antesRango => self::MEJORADOS,
            default => self::SIGUEN,
        };
    }

    /** @param array<string, mixed> $r */
    private static function sinConclusion(array $r): bool
    {
        return in_array((string) ($r['estado_vigente'] ?? ''), self::SIN_CONCLUSION, true);
    }

    /**
     * Cuánto pesa: 0 si está bien o no aplica, 1 si es mejorable, y de 2 (bajo) a 5 (crítico)
     * si es un problema. Un problema sin gravedad cuenta como medio.
     *
     * @param array<string, mixed> $r
     */
    private static function rango(array $r): int
    {
        return match ((string) ($r['estado_vigente'] ?? '')) {
            'mejorable' => 1,
            'problema' => match (Gravedad::tryFrom((string) ($r['gravedad'] ?? ''))) {
                Gravedad::Bajo => 2,
                Gravedad::Alto => 4,
                Gravedad::Critico => 5,
                default => 3,
            },
            default => 0,
        };
    }

    /**
     * Una fila por comprobación y página. En el sitio completo se puede llegar a la misma página
     * por dos caminos (directamente y por una redirección) y su resultado sale dos veces: se queda
     * el que tiene conclusión y, entre ellos, el más grave.
     *
     * @param list<array<string, mixed>> $resultados
     * @return array<string, array<string, mixed>>
     */
    private static function unicos(array $resultados): array
    {
        $unicos = [];
        foreach ($resultados as $r) {
            $clave = self::clave($r);
            $actual = $unicos[$clave] ?? null;
            if ($actual === null
                || (self::sinConclusion($actual) && !self::sinConclusion($r))
                || (self::sinConclusion($actual) === self::sinConclusion($r) && self::rango($r) > self::rango($actual))) {
                $unicos[$clave] = $r;
            }
        }

        return $unicos;
    }

    /** @param array<string, mixed> $r */
    private static function clave(array $r): string
    {
        return (string) $r['id_comprobacion'] . '|' . rtrim((string) ($r['url_recurso'] ?? ''), '/');
    }
}
