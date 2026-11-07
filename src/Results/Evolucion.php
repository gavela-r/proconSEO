<?php

declare(strict_types=1);

namespace ProconSEO\Results;

use ProconSEO\Shared\Enum\Alcance;

/**
 * Evolución de un proyecto: cuántos problemas, decisiones y mejorables tuvo cada análisis, en
 * orden de fecha. Solo se comparan análisis de la misma dirección y el mismo alcance: una URL
 * suelta y un sitio completo no se pueden poner en la misma línea.
 *
 * Son recuentos de resultados después de las revisiones humanas, nunca una nota ni un porcentaje.
 */
final class Evolucion
{
    public const SERIES = ['problema' => 'Problemas', 'decision_revisar' => 'Decisiones a revisar', 'mejorable' => 'Mejorables'];
    private const EN_MARCHA = ['en_cola', 'en_curso', 'cancelando'];

    /**
     * Una serie por dirección y alcance con al menos dos análisis, la más reciente primero.
     *
     * @var list<array{titulo: string, puntos: list<array{id: int, fecha: string, estado: string, valores: array<string, int>}>, maximo: int}>
     */
    public readonly array $series;

    /** @param list<array<string, mixed>> $historial RepositorioProyectos::historial() (el más reciente primero) */
    public function __construct(array $historial)
    {
        $grupos = [];
        foreach (array_reverse($historial) as $a) {
            $recuento = is_array($a['recuento'] ?? null) ? $a['recuento'] : [];
            if (in_array($a['estado'], self::EN_MARCHA, true) || $recuento === []) {
                continue;
            }
            $url = (string) ($a['url_final'] ?? $a['url_introducida']);
            $clave = $url . '|' . $a['alcance'];
            $grupos[$clave] ??= ['titulo' => (Alcance::tryFrom((string) $a['alcance'])?->etiqueta() ?? (string) $a['alcance']) . ' · ' . $url, 'puntos' => []];
            $valores = [];
            foreach (array_keys(self::SERIES) as $estado) {
                $valores[$estado] = (int) ($recuento[$estado] ?? 0);
            }
            $grupos[$clave]['puntos'][] = ['id' => (int) $a['id'], 'fecha' => (string) ($a['terminado_en'] ?? $a['creado_en']), 'estado' => (string) $a['estado'], 'valores' => $valores];
        }

        $series = [];
        foreach ($grupos as $g) {
            if (count($g['puntos']) < 2) {
                continue;
            }
            $maximo = max(array_map(static fn (array $p): int => (int) array_sum($p['valores']), $g['puntos']));
            $series[] = $g + ['maximo' => max(1, $maximo)];
        }
        // La serie con el análisis más reciente, primero.
        usort($series, static fn (array $a, array $b): int => end($b['puntos'])['fecha'] <=> end($a['puntos'])['fecha']);
        $this->series = $series;
    }
}
