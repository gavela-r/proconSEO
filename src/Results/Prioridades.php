<?php

declare(strict_types=1);

namespace ProconSEO\Results;

use ProconSEO\Shared\Enum\Esfuerzo;
use ProconSEO\Shared\Enum\Gravedad;

/**
 * «Por dónde empezar» y «Arreglos rápidos» (docs/diseno/analisis-herramientas.md, mejora 3).
 *
 * Ordena las incidencias del informe por gravedad, después por cuántas páginas afecta y al final
 * por lo poco que cuesta arreglarla. Es una ayuda para decidir, no una nota: no da cifras globales
 * ni porcentajes, solo un orden.
 */
final class Prioridades
{
    private const ESTADOS = ['problema', 'decision_revisar', 'mejorable'];

    /**
     * @return array{empezar: list<array<string, mixed>>, rapidos: list<array<string, mixed>>}
     */
    public static function desde(Informe $informe, int $cuantas = 5, int $rapidas = 3): array
    {
        $items = array_map(self::item(...), [...$informe->bloqueos, ...$informe->incidencias]);
        $items = array_values(array_filter($items, static fn (array $i): bool => in_array($i['estado'], self::ESTADOS, true)));
        usort($items, static fn (array $a, array $b): int => self::orden($a) <=> self::orden($b));

        // Rápidos: poco esfuerzo y que merezca la pena (un problema, o algo repetido en varias páginas).
        $rapidos = array_values(array_filter($items, static fn (array $i): bool => $i['esfuerzo'] === Esfuerzo::Bajo->value
            && ($i['estado'] === 'problema' || $i['paginas'] >= 3)));

        return ['empezar' => array_slice($items, 0, $cuantas), 'rapidos' => array_slice($rapidos, 0, $rapidas)];
    }

    /**
     * @param array<string, mixed> $entrada elemento de Informe::$bloqueos o Informe::$incidencias
     * @return array<string, mixed>
     */
    private static function item(array $entrada): array
    {
        /** @var array<string, mixed> $principal */
        $principal = $entrada['principal'];
        $estado = (string) $principal['estado_vigente'];
        $paginas = count(array_unique(array_map(static fn (array $r): string => (string) ($r['url_recurso'] ?? ''), $entrada['resultados'])));
        $esCausa = $entrada['tipo'] === 'causa' && count($entrada['resultados']) > 1;

        return [
            'titulo' => $esCausa ? (string) $entrada['descripcion'] : (string) $principal['titulo'],
            'enlace' => $esCausa ? '#causa-' . $entrada['codigo'] : '#r-' . $principal['id'],
            'estado' => $estado,
            'gravedad' => $estado === 'problema' ? Gravedad::tryFrom((string) $principal['gravedad'])?->value : null,
            'esfuerzo' => Esfuerzo::tryFrom((string) $principal['esfuerzo'])?->value,
            'paginas' => $paginas,
            'repetido' => $entrada['tipo'] === 'repetido',
            'bloques' => array_values(array_unique(array_map(static fn (array $r): string => (string) $r['bloque'], $entrada['resultados']))),
        ];
    }

    /**
     * @param array<string, mixed> $i
     * @return list<int>
     */
    private static function orden(array $i): array
    {
        $estado = array_search($i['estado'], self::ESTADOS, true);
        $gravedad = $i['gravedad'] === null ? false : array_search(Gravedad::from((string) $i['gravedad']), Gravedad::cases(), true);
        $esfuerzo = $i['esfuerzo'] === null ? false : array_search(Esfuerzo::from((string) $i['esfuerzo']), Esfuerzo::cases(), true);

        return [
            $estado === false ? 9 : (int) $estado,
            $gravedad === false ? 9 : (int) $gravedad,
            -(int) $i['paginas'],
            $esfuerzo === false ? 9 : (int) $esfuerzo,
        ];
    }
}
