<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Catalogo;

use ProconSEO\Engine\Motor;
use ProconSEO\Shared\Contract\FichaComprobacion;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\Bloque;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\Esfuerzo;
use ProconSEO\Shared\Enum\ModoEvaluacion;

/**
 * Datos de la página «Catálogo y cobertura» (especificación 4.8, RF-60).
 *
 * Lista todas las subcomprobaciones con su cobertura real: la que declara el motor que se
 * ejecuta, no la que se planificó. Solo cuenta: sin porcentajes ni notas.
 */
final class VistaCatalogo
{
    public const LARGO_BUSQUEDA = 100;

    private const MVP = [
        'si' => 'Entra en la primera versión.',
        'parcial' => 'En la primera versión, solo lo que se puede comprobar sin navegador ni IA.',
        'psi' => 'Se obtiene de Google PageSpeed Insights.',
        'no' => 'No entra en la primera versión.',
    ];

    public function __construct(private readonly CatalogoJson $catalogo)
    {
    }

    /** La vista del catálogo con la cobertura del motor actual. */
    public static function actual(): self
    {
        return new self(Motor::v1()->catalogo());
    }

    public function version(): string
    {
        return $this->catalogo->version();
    }

    /**
     * Cuántas subcomprobaciones hay de cada cobertura, por bloque y en total.
     *
     * @return array<string, array<string, int>> bloque (o «total») => cobertura => número
     */
    public function resumen(): array
    {
        $vacio = array_fill_keys(array_map(static fn (Cobertura $c) => $c->value, Cobertura::cases()), 0) + ['total' => 0];
        $resumen = [];
        foreach (Bloque::cases() as $b) {
            $resumen[$b->value] = $vacio;
        }
        $resumen['total'] = $vacio;
        foreach ($this->catalogo->todas() as $f) {
            foreach ([$f->bloque->value, 'total'] as $fila) {
                $resumen[$fila][$f->cobertura->value]++;
                $resumen[$fila]['total']++;
            }
        }
        return $resumen;
    }

    /**
     * Filtros válidos a partir de lo que llega en la petición. Lo que no se reconoce se ignora.
     *
     * @return array{bloque: ?Bloque, cobertura: ?Cobertura, texto: string}
     */
    public static function filtros(mixed $bloque, mixed $cobertura, mixed $texto): array
    {
        $texto = is_string($texto) ? trim((string) preg_replace('/\s+/u', ' ', $texto)) : '';
        return [
            'bloque' => is_string($bloque) ? Bloque::tryFrom($bloque) : null,
            'cobertura' => is_string($cobertura) ? Cobertura::tryFrom($cobertura) : null,
            'texto' => mb_substr($texto, 0, self::LARGO_BUSQUEDA),
        ];
    }

    /**
     * Criterios con las subcomprobaciones que pasan los filtros, en el orden del catálogo.
     *
     * @param array{bloque: ?Bloque, cobertura: ?Cobertura, texto: string} $filtros
     * @return list<array{id: string, nombre: string, bloque: Bloque, explicacion: string, que_comprueba: string, modo: string, esfuerzo: string, wcag: list<array<string, mixed>>, fuentes: list<array<string, mixed>>, subcomprobaciones: list<array<string, mixed>>}>
     */
    public function criterios(array $filtros): array
    {
        $porCriterio = [];
        foreach ($this->catalogo->todas() as $f) {
            if (!$this->pasa($f, $filtros)) {
                continue;
            }
            $porCriterio[$f->idCriterio][] = $this->subcomprobacion($f);
        }

        $criterios = [];
        foreach ($porCriterio as $id => $subs) {
            $c = $this->catalogo->criterio($id) ?? [];
            $criterios[] = [
                'id' => $id,
                'nombre' => (string) ($c['nombre'] ?? $id),
                'bloque' => Bloque::from((string) $c['bloque']),
                'explicacion' => (string) ($c['explicacion_sencilla'] ?? ''),
                'que_comprueba' => (string) ($c['que_comprueba'] ?? ''),
                'modo' => ModoEvaluacion::tryFrom((string) ($c['modo'] ?? ''))?->etiqueta() ?? '',
                'esfuerzo' => Esfuerzo::tryFrom((string) ($c['esfuerzo'] ?? ''))?->etiqueta() ?? '',
                'wcag' => array_values($c['wcag'] ?? []),
                'fuentes' => array_values($c['fuentes'] ?? []),
                'subcomprobaciones' => $subs,
            ];
        }
        return $criterios;
    }

    /** Número total de subcomprobaciones del catálogo. */
    public function total(): int
    {
        return count($this->catalogo->todas());
    }

    /** @param array{bloque: ?Bloque, cobertura: ?Cobertura, texto: string} $filtros */
    private function pasa(FichaComprobacion $f, array $filtros): bool
    {
        if ($filtros['bloque'] !== null && $f->bloque !== $filtros['bloque']) {
            return false;
        }
        if ($filtros['cobertura'] !== null && $f->cobertura !== $filtros['cobertura']) {
            return false;
        }
        if ($filtros['texto'] === '') {
            return true;
        }
        $donde = mb_strtolower($f->id . ' ' . $f->nombre . ' ' . $f->queComprueba . ' ' . ($f->extra['nombre_criterio'] ?? '') . ' ' . ($f->wcagCriterio ?? ''));
        return str_contains($donde, mb_strtolower($filtros['texto']));
    }

    /** @return array<string, mixed> */
    private function subcomprobacion(FichaComprobacion $f): array
    {
        $notas = [];
        if ($f->cobertura === Cobertura::NoImplementada) {
            $notas[] = Motor::motivoNoImplementada($f);
        } elseif ($f->cobertura === Cobertura::ParcialmenteImplementada) {
            $notas[] = 'Se comprueba solo una parte; el resultado explica qué queda fuera.';
        }
        $mvp = self::MVP[(string) ($f->extra['mvp'] ?? '')] ?? null;

        $alcance = Alcance::tryFrom((string) ($f->extra['alcance_minimo'] ?? ''));
        $wcag = array_map(static fn (array $w): array => [
            'criterio' => (string) $w['criterio'],
            'nombre' => (string) $w['nombre'],
            'nivel' => (string) $w['nivel'],
            'version' => (string) $w['version_wcag'] === '2.2' ? 'nuevo en 2.2' : '2.1',
            'url' => (string) ($w['url'] ?? ''),
        ], $f->extra['wcag'] ?? []);

        return [
            'id' => $f->id,
            'nombre' => $f->nombre,
            'que_comprueba' => $f->queComprueba,
            'cobertura' => $f->cobertura,
            'modo' => $f->modo?->etiqueta() ?? '',
            'alcance' => $alcance?->etiqueta() ?? '',
            'wcag' => $wcag,
            'kit_digital' => ($f->extra['kit_digital'] ?? []) !== [],
            'mvp' => $mvp,
            'notas' => $notas,
        ];
    }
}
