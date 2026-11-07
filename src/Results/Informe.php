<?php

declare(strict_types=1);

namespace ProconSEO\Results;

use ProconSEO\Shared\Contract\Catalogo;
use ProconSEO\Shared\Enum\Bloque;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\EstadoFase;
use ProconSEO\Shared\Enum\EstadoResultado;
use ProconSEO\Shared\Enum\Gravedad;
use ProconSEO\Shared\Enum\ResultadoDescarga;

/**
 * Ordena los resultados de un análisis tal como pide la especificación (4.4, RF-20, RF-21, RF-24):
 *
 *   1. resumen por bloque: cuántos resultados hay en cada estado vigente y qué cobertura hay (sin notas);
 *   2. bloqueos de acceso, recuperación o indexación;
 *   3. incidencias agrupadas por causa (una causa se muestra una vez con sus efectos);
 *   4. correctos y no aplicables;
 *   5. no verificables y revisiones pendientes;
 *   6. capacidades pendientes (no implementadas) y límites.
 *
 * Es una clase pura: recibe las filas ya leídas y no consulta la base de datos.
 */
final class Informe
{
    /** Estados que se muestran como incidencias, en orden de prioridad. */
    private const ESTADOS_INCIDENCIA = ['problema', 'decision_revisar', 'mejorable'];

    /** @var array<string, array{recuento: array<string, int>, total: int, no_implementadas: int, pendientes: int}> */
    public readonly array $resumen;

    public readonly FiltroResultados $filtro;

    /** Resultados del análisis y, de ellos, los que pasan los filtros. */
    public readonly int $total;
    public readonly int $mostrados;

    /** @var list<string> criterios presentes en el análisis, para el filtro */
    public readonly array $criterios;

    /** @var list<string> páginas o recursos presentes en el análisis, para el filtro */
    public readonly array $urls;

    /** @var list<array<string, mixed>> recursos que no se pudieron descargar */
    public readonly array $recursosBloqueados;

    /**
     * Recursos secundarios que no existen (404/410), como un robots.txt o un sitemap que no se ha
     * publicado. No son un bloqueo: cada comprobación que los necesita ya dice qué significa.
     *
     * @var list<array<string, mixed>>
     */
    public readonly array $recursosNoEncontrados;

    /** @var list<array<string, mixed>> entradas (causa o resultado) que bloquean el acceso o la indexación */
    public readonly array $bloqueos;

    /** @var list<array<string, mixed>> entradas (causa o resultado) del resto de incidencias */
    public readonly array $incidencias;

    /** @var list<array<string, mixed>> */
    public readonly array $correctos;

    /** @var list<array<string, mixed>> */
    public readonly array $noAplicables;

    /** @var list<array<string, mixed>> no verificables implementados, primero los pendientes de revisión */
    public readonly array $noVerificables;

    /** @var list<array<string, mixed>> comprobaciones que la herramienta aún no hace */
    public readonly array $noImplementadas;

    /** @var list<array<string, mixed>> fases omitidas, parciales o fallidas */
    public readonly array $fasesIncompletas;

    /** @var list<array<string, mixed>> recursos descargados solo en parte */
    public readonly array $recursosTruncados;

    /**
     * @param list<array<string, mixed>> $resultados filas de RepositorioResultados::resultados()
     * @param list<array<string, mixed>> $recursos
     * @param list<array<string, mixed>> $fases
     */
    public function __construct(array $resultados, array $recursos, array $fases, ?Catalogo $catalogo = null, ?FiltroResultados $filtro = null)
    {
        $todos = array_map(static fn (array $r): array => self::conTitulo($r, $catalogo), $resultados);
        $this->filtro = $filtro ?? FiltroResultados::ninguno();
        $this->total = count($todos);
        $this->criterios = self::valoresUnicos($todos, 'id_criterio');
        $this->urls = self::valoresUnicos($todos, 'url_recurso');

        // El resumen es la foto completa del análisis; los filtros solo afectan a las listas.
        $this->resumen = self::resumir($todos);
        $resultados = $this->filtro->aplicar($todos);
        $this->mostrados = count($resultados);
        $fallidos = array_filter($recursos, static fn (array $r): bool => $r['resultado'] !== ResultadoDescarga::Correcto->value);
        $this->recursosBloqueados = array_values(array_filter($fallidos, static fn (array $r): bool => !self::noExiste($r)));
        $this->recursosNoEncontrados = array_values(array_filter($fallidos, self::noExiste(...)));
        $this->recursosTruncados = array_values(array_filter($recursos, static fn (array $r): bool => (bool) $r['truncado']));
        $this->fasesIncompletas = array_values(array_filter($fases, static fn (array $f): bool => in_array(
            $f['estado'],
            [EstadoFase::Omitida->value, EstadoFase::Parcial->value, EstadoFase::Fallida->value],
            true,
        )));

        $incidencias = array_values(array_filter($resultados, static fn (array $r): bool => in_array($r['estado_vigente'], self::ESTADOS_INCIDENCIA, true)));
        $entradas = self::agruparPorCausa($incidencias);
        $this->bloqueos = array_values(array_filter($entradas, static fn (array $e): bool => $e['bloquea']));
        $this->incidencias = array_values(array_filter($entradas, static fn (array $e): bool => !$e['bloquea']));

        $this->correctos = self::conEstado($resultados, 'correcto');
        $this->noAplicables = self::conEstado($resultados, 'no_aplicable');

        $noVerificables = self::conEstado($resultados, 'no_verificable');
        $implementado = static fn (array $r): bool => $r['cobertura'] !== Cobertura::NoImplementada->value;
        $pendientes = array_filter($noVerificables, static fn (array $r): bool => $implementado($r) && $r['revision_pendiente']);
        $resto = array_filter($noVerificables, static fn (array $r): bool => $implementado($r) && !$r['revision_pendiente']);
        $this->noVerificables = [...array_values($pendientes), ...array_values($resto)];
        $this->noImplementadas = array_values(array_filter($noVerificables, static fn (array $r): bool => !$implementado($r)));
    }

    public function pendientesDeRevision(): int
    {
        return array_sum(array_column($this->resumen, 'pendientes'));
    }

    public function numeroIncidencias(): int
    {
        return self::contarResultados($this->bloqueos) + self::contarResultados($this->incidencias);
    }

    /**
     * @param list<array<string, mixed>> $resultados
     * @return array<string, array{recuento: array<string, int>, total: int, no_implementadas: int, pendientes: int}>
     */
    private static function resumir(array $resultados): array
    {
        $resumen = [];
        foreach (Bloque::cases() as $bloque) {
            $resumen[$bloque->value] = [
                'recuento' => array_fill_keys(EstadoResultado::valores(), 0),
                'total' => 0,
                'no_implementadas' => 0,
                'pendientes' => 0,
            ];
        }

        foreach ($resultados as $r) {
            $bloque = (string) $r['bloque'];
            if (!isset($resumen[$bloque])) {
                continue;
            }
            $resumen[$bloque]['total']++;
            $resumen[$bloque]['recuento'][(string) $r['estado_vigente']] = ($resumen[$bloque]['recuento'][(string) $r['estado_vigente']] ?? 0) + 1;
            $resumen[$bloque]['no_implementadas'] += (int) ($r['cobertura'] === Cobertura::NoImplementada->value);
            $resumen[$bloque]['pendientes'] += (int) $r['revision_pendiente'];
        }

        return $resumen;
    }

    /**
     * Una entrada por causa (con todos sus resultados) y una por cada resultado sin causa (RF-24),
     * ordenadas de más a menos prioritaria.
     *
     * @param list<array<string, mixed>> $incidencias
     * @return list<array<string, mixed>>
     */
    private static function agruparPorCausa(array $incidencias): array
    {
        // La misma comprobación, con el mismo estado y gravedad, en varias páginas distintas es un solo
        // hallazgo repetido (suele ser la plantilla): se muestra una vez con la lista de páginas.
        $repetidos = [];
        foreach ($incidencias as $r) {
            if ($r['grupo_causa_id'] === null) {
                $repetidos[self::claveRepeticion($r)][(string) ($r['url_recurso'] ?? '')] = true;
            }
        }

        $entradas = [];
        foreach ($incidencias as $r) {
            if ($r['grupo_causa_id'] !== null) {
                $clave = 'causa-' . $r['grupo_causa_id'];
                $tipo = 'causa';
            } elseif (count($repetidos[self::claveRepeticion($r)]) > 1) {
                $clave = 'repetido-' . self::claveRepeticion($r);
                $tipo = 'repetido';
            } else {
                $clave = 'resultado-' . $r['id'];
                $tipo = 'resultado';
            }
            $entradas[$clave] ??= [
                'tipo' => $tipo,
                'codigo' => $r['causa_codigo'],
                'descripcion' => $r['causa_descripcion'],
                'resultados' => [],
            ];
            $entradas[$clave]['resultados'][] = $r;
        }

        $entradas = array_map(static function (array $e): array {
            usort($e['resultados'], self::comparar(...));
            $principal = $e['resultados'][0];

            return $e + [
                'principal' => $principal,
                'bloques' => array_values(array_unique(array_column($e['resultados'], 'bloque'))),
                'bloquea' => array_reduce(
                    $e['resultados'],
                    static fn (bool $c, array $r): bool => $c || ($r['bloquea_dependientes'] && $r['estado_vigente'] === 'problema'),
                    false,
                ),
            ];
        }, array_values($entradas));

        usort($entradas, static fn (array $a, array $b): int => self::comparar($a['principal'], $b['principal']));

        return $entradas;
    }

    /** @param array<string, mixed> $r */
    private static function claveRepeticion(array $r): string
    {
        return $r['id_comprobacion'] . '|' . $r['estado_vigente'] . '|' . ($r['estado_vigente'] === 'problema' ? (string) $r['gravedad'] : '');
    }

    /** Problemas por gravedad, después decisiones a revisar y al final mejorables. */
    private static function comparar(array $a, array $b): int
    {
        return [self::rangoEstado($a), self::rangoGravedad($a), $a['id']] <=> [self::rangoEstado($b), self::rangoGravedad($b), $b['id']];
    }

    private static function rangoEstado(array $r): int
    {
        $rango = array_search($r['estado_vigente'], self::ESTADOS_INCIDENCIA, true);

        return $rango === false ? 99 : $rango;
    }

    private static function rangoGravedad(array $r): int
    {
        // La gravedad solo vale para el estado original "problema"; si una revisión cambió el estado, no cuenta.
        $gravedad = $r['estado_vigente'] === 'problema' ? Gravedad::tryFrom((string) $r['gravedad']) : null;
        $rango = $gravedad === null ? false : array_search($gravedad, Gravedad::cases(), true);

        return $rango === false ? 99 : $rango;
    }

    /**
     * Un recurso secundario (pedido por una comprobación) que respondió 404 o 410 no existe, pero no
     * bloquea el análisis. Si falla la página principal (sin justificado_por), siempre es un bloqueo.
     */
    private static function noExiste(array $recurso): bool
    {
        return $recurso['justificado_por'] !== null
            && $recurso['resultado'] === ResultadoDescarga::ErrorHttp->value
            && in_array((int) $recurso['estado_http'], [404, 410], true);
    }

    /** @return list<array<string, mixed>> */
    private static function conEstado(array $resultados, string $estado): array
    {
        return array_values(array_filter($resultados, static fn (array $r): bool => $r['estado_vigente'] === $estado));
    }

    private static function contarResultados(array $entradas): int
    {
        return array_sum(array_map(static fn (array $e): int => count($e['resultados']), $entradas));
    }

    /**
     * Añade a cada resultado su nombre y el de su criterio, sacados del catálogo.
     *
     * @param list<array<string, mixed>> $resultados
     * @return list<array<string, mixed>>
     */
    public static function conTitulos(array $resultados, ?Catalogo $catalogo): array
    {
        return array_map(static fn (array $r): array => self::conTitulo($r, $catalogo), $resultados);
    }

    /** @return list<string> */
    private static function valoresUnicos(array $resultados, string $campo): array
    {
        $valores = array_values(array_unique(array_filter(array_column($resultados, $campo), static fn (mixed $v): bool => is_string($v) && $v !== '')));
        sort($valores, SORT_NATURAL);

        return $valores;
    }

    /** Añade el nombre de la comprobación y del criterio desde el catálogo; si no está, se usa el identificador. */
    private static function conTitulo(array $r, ?Catalogo $catalogo): array
    {
        $ficha = $catalogo?->obtener((string) $r['id_comprobacion']);

        // Si el resultado no trae la referencia WCAG (p. ej. una comprobación no implementada),
        // se completa con la de su ficha del catálogo, que es un dato fijo de la comprobación.
        if ($ficha !== null && ($r['wcag_criterio'] ?? null) === null && $ficha->wcagCriterio !== null) {
            $r['wcag_criterio'] = $ficha->wcagCriterio;
            $r['wcag_nombre'] = $ficha->wcagNombre;
            $r['wcag_nivel'] = $ficha->wcagNivel;
            $r['wcag_version'] = $ficha->wcagVersion;
        }
        // Igual con la normativa (p. ej. Real Decreto 1112/2018) de las comprobaciones que no son WCAG.
        if ($ficha !== null && ($r['wcag_criterio'] ?? null) === null && ($r['normativa'] ?? null) === null && isset($ficha->extra['normativa'])) {
            $r['normativa'] = (string) $ficha->extra['normativa'];
        }

        return $r + [
            'titulo' => $ficha?->nombre ?? (string) $r['id_comprobacion'],
            'nombre_criterio' => $ficha !== null ? (string) ($ficha->extra['nombre_criterio'] ?? '') : '',
        ];
    }
}
