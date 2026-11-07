<?php

declare(strict_types=1);

namespace ProconSEO\Export;

use ProconSEO\Results\Informe;
use ProconSEO\Results\RepositorioResultados;
use ProconSEO\Shared\Contract\Catalogo;
use ProconSEO\Shared\Enum\Bloque;
use ProconSEO\Shared\Enum\EstadoResultado;

/**
 * Reúne todo lo que se exporta de un análisis (RF-50, RF-51, RF-52): identificación y perfil,
 * resumen por estado (sin notas ni porcentajes), resultados con su estado original y vigente,
 * evidencias, revisiones, recursos (incluidos los que fallaron), fases incompletas, eventos de
 * seguridad y la marca de datos de prueba.
 *
 * Los textos se exportan tal cual se guardaron (ya sanitizados por SEG-09). Cada formato se
 * encarga de escaparlos o neutralizarlos a su manera.
 */
final class DatosExportacion
{
    public const VERSION_FORMATO = '1';

    public function __construct(private readonly RepositorioResultados $repo, private readonly ?Catalogo $catalogo)
    {
    }

    /**
     * @return array<string, mixed>|null null si el análisis no existe
     */
    public function analisis(int $id): ?array
    {
        $analisis = $this->repo->analisis($id);
        if ($analisis === null) {
            return null;
        }

        $resultados = Informe::conTitulos($this->repo->resultados($id), $this->catalogo);
        $evidencias = [];
        $revisiones = [];
        foreach ($resultados as $r) {
            foreach ($this->repo->evidencias((int) $r['id']) as $e) {
                $evidencias[(string) $e['codigo']] = self::evidencia($e);
            }
            foreach ($this->repo->revisiones((int) $r['id']) as $v) {
                $revisiones[] = self::revision($v, (int) $r['id'], (string) $r['id_comprobacion']);
                foreach ($v['evidencias'] as $e) {
                    $evidencias[(string) $e['codigo']] = self::evidencia($e + ['url_recurso' => null, 'truncado' => 0], (int) $v['id']);
                }
            }
        }
        ksort($evidencias, SORT_NATURAL);
        $esPrueba = (bool) $analisis['es_dato_prueba'] || (bool) $analisis['proyecto_es_dato_prueba'];

        return [
            'formato' => ['nombre' => 'proconSEO', 'version' => self::VERSION_FORMATO],
            'datos_de_prueba' => $esPrueba,
            'aviso' => $esPrueba ? 'DATOS DE PRUEBA: estos resultados son de demostración y no corresponden a ninguna web real.' : null,
            'nota' => 'Recuento de resultados por estado: no es una nota ni un porcentaje de cumplimiento.',
            'analisis' => [
                'id' => (int) $analisis['id'],
                'proyecto' => ['id' => (int) $analisis['proyecto_id'], 'nombre' => $analisis['proyecto_nombre'], 'tipo' => $analisis['proyecto_tipo'], 'sector' => $analisis['proyecto_sector']],
                'url_introducida' => $analisis['url_introducida'],
                'url_final' => $analisis['url_final'],
                'alcance' => $analisis['alcance'],
                'objetivo' => $analisis['objetivo'],
                'hosts_autorizados' => self::json($analisis['hosts_autorizados']),
                'estado' => $analisis['estado'],
                'motivo_cierre' => $analisis['motivo_cierre'],
                'perfil_ejecucion' => $analisis['perfil_version'],
                'version_catalogo' => $analisis['version_catalogo'],
                'creado_en_utc' => $analisis['creado_en'],
                'iniciado_en_utc' => $analisis['iniciado_en'],
                'terminado_en_utc' => $analisis['terminado_en'],
            ],
            'resumen' => self::resumen($resultados),
            'resultados' => array_map(self::resultado(...), $resultados),
            'evidencias' => array_values($evidencias),
            'revisiones' => $revisiones,
            'recursos' => array_map(static fn (array $r): array => [
                'url' => $r['url'],
                'url_final' => $r['url_final'],
                'estado_http' => $r['estado_http'] === null ? null : (int) $r['estado_http'],
                'resultado' => $r['resultado'],
                'motivo' => $r['motivo'],
                'truncado' => (bool) $r['truncado'],
                'bytes' => (int) $r['bytes'],
                'justificado_por' => $r['justificado_por'],
            ], $this->repo->recursos($id)),
            'fases' => array_map(static fn (array $f): array => [
                'fase' => $f['fase'], 'estado' => $f['estado'], 'mensaje' => $f['mensaje'],
            ], $this->repo->fases($id)),
            'eventos_seguridad' => array_map(static fn (array $e): array => [
                'regla' => $e['regla'], 'fase' => $e['fase'], 'url' => $e['url_recurso'], 'condicion' => $e['condicion'], 'decision' => $e['decision'],
            ], $this->repo->eventosSeguridad($id)),
            // Lo revisado a mano de cada criterio WCAG (la última fila de cada criterio es la vigente).
            'revisiones_manuales' => array_map(static fn (array $m): array => [
                'criterio_wcag' => $m['criterio_wcag'], 'resultado' => $m['resultado'], 'revisor' => $m['revisor'],
                'paginas' => $m['paginas'], 'notas' => $m['notas'], 'creada_en_utc' => $m['creado_en'],
            ], $this->repo->revisionesManuales($id)),
        ];
    }

    /** Nombre de fichero seguro: proconseo-analisis-12-2026-11-04[-DATOS-DE-PRUEBA].csv */
    public static function nombreFichero(array $datos, string $extension): string
    {
        $fecha = substr((string) ($datos['analisis']['creado_en_utc'] ?? ''), 0, 10);

        return 'proconseo-analisis-' . (int) $datos['analisis']['id']
            . (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) === 1 ? '-' . $fecha : '')
            . ($datos['datos_de_prueba'] ? '-DATOS-DE-PRUEBA' : '')
            . '.' . preg_replace('/[^a-z]/', '', $extension);
    }

    /** @return array<string, array<string, int>> bloque => estado => número (todos los estados, aunque sean 0) */
    private static function resumen(array $resultados): array
    {
        $resumen = [];
        foreach (Bloque::cases() as $bloque) {
            $resumen[$bloque->value] = array_fill_keys(EstadoResultado::valores(), 0);
        }
        foreach ($resultados as $r) {
            if (isset($resumen[$r['bloque']][$r['estado_vigente']])) {
                $resumen[$r['bloque']][$r['estado_vigente']]++;
            }
        }

        return $resumen;
    }

    private static function resultado(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'comprobacion' => $r['id_comprobacion'],
            'criterio' => $r['id_criterio'],
            'nombre' => $r['titulo'],
            'bloque' => $r['bloque'],
            'estado_original' => $r['estado'],
            'estado_vigente' => $r['estado_vigente'],
            'revisado' => (bool) $r['revisado'],
            'revision_pendiente' => (bool) $r['revision_pendiente'],
            'gravedad' => $r['estado_vigente'] === 'problema' ? $r['gravedad'] : null,
            'esfuerzo' => $r['esfuerzo'],
            'ejecucion' => $r['ejecucion'],
            'cobertura' => $r['cobertura'],
            'tipo_conclusion' => $r['tipo_conclusion'],
            'modo' => $r['modo'],
            'url' => $r['url_recurso'],
            'elemento' => $r['elemento'],
            'valor_observado' => $r['valor_observado'],
            'explicacion_sencilla' => $r['explicacion_sencilla'],
            'consecuencia' => $r['consecuencia'],
            'correccion' => $r['correccion'],
            'correccion_cms' => $r['correccion_cms'],
            'limitacion' => $r['limitacion'],
            'motivo_revision' => $r['motivo_revision'],
            'wcag' => $r['wcag_criterio'] === null ? null : [
                'criterio' => $r['wcag_criterio'], 'nombre' => $r['wcag_nombre'], 'nivel' => $r['wcag_nivel'],
                'version' => $r['wcag_version'],
            ],
            'normativa' => $r['normativa'] ?? null,
            'causa' => $r['causa_codigo'] === null ? null : ['codigo' => $r['causa_codigo'], 'descripcion' => $r['causa_descripcion']],
            'fuente' => $r['fuente'],
            'evidencias' => $r['evidencias'] === null || $r['evidencias'] === '' ? [] : explode(' ', (string) $r['evidencias']),
            'version_regla' => $r['version_regla'],
        ];
    }

    private static function evidencia(array $e, ?int $revisionId = null): array
    {
        $esCaptura = $e['tipo'] === 'captura';

        return [
            'codigo' => $e['codigo'],
            'tipo' => $e['tipo'],
            // Las capturas no se incluyen en el fichero: se ven en la herramienta.
            'contenido' => $esCaptura ? null : $e['contenido'],
            'captura' => $esCaptura ? 'Captura añadida por un revisor: se puede ver en la herramienta (/evidencias/' . (int) ($e['id'] ?? 0) . '/captura).' : null,
            'url' => $e['url_recurso'],
            'truncada' => (bool) $e['truncado'],
            'revision_id' => $revisionId,
            'capturada_en_utc' => $e['capturado_en'],
        ];
    }

    private static function revision(array $v, int $resultadoId, string $comprobacion): array
    {
        return [
            'id' => (int) $v['id'],
            'resultado_id' => $resultadoId,
            'comprobacion' => $comprobacion,
            'revisor' => $v['revisor'],
            'motivo' => $v['motivo'],
            'decision' => $v['decision'],
            'nuevo_estado' => $v['nuevo_estado'],
            'justificacion' => $v['justificacion'],
            'evidencias' => array_column($v['evidencias'], 'codigo'),
            'creada_en_utc' => $v['creado_en'],
        ];
    }

    private static function json(?string $valor): mixed
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        $decodificado = json_decode($valor, true);

        return $decodificado ?? $valor;
    }
}
