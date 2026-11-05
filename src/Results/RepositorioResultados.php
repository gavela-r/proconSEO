<?php

declare(strict_types=1);

namespace ProconSEO\Results;

use PDO;

/**
 * Lectura de un análisis y de sus resultados, evidencias y revisiones (Parte 3).
 * Solo consultas: esta clase nunca modifica resultados (RF-42). Solo sentencias preparadas.
 */
final class RepositorioResultados
{
    public function __construct(private readonly PDO $bd)
    {
    }

    /** Análisis con los datos de su proyecto. @return array<string, mixed>|null */
    public function analisis(int $id): ?array
    {
        $consulta = $this->bd->prepare(
            'SELECT a.*, p.nombre AS proyecto_nombre, p.tipo AS proyecto_tipo, p.sector AS proyecto_sector,
                    p.es_dato_prueba AS proyecto_es_dato_prueba, pe.version AS perfil_version
             FROM analisis a
             JOIN proyectos p ON p.id = a.proyecto_id
             LEFT JOIN perfiles_ejecucion pe ON pe.id = a.perfil_id
             WHERE a.id = ?'
        );
        $consulta->execute([$id]);
        $fila = $consulta->fetch();

        return $fila === false ? null : $fila;
    }

    /**
     * Resultados del análisis con su estado vigente, si la revisión está pendiente,
     * su grupo de causa y los códigos de sus evidencias.
     *
     * @return list<array<string, mixed>>
     */
    public function resultados(int $analisisId): array
    {
        $consulta = $this->bd->prepare(
            'SELECT r.*, ' . EstadoVigente::EXPRESION_ESTADO . ' AS estado_vigente,
                    ' . EstadoVigente::EXPRESION_PENDIENTE . ' AS revision_pendiente,
                    rev.ultimo_estado IS NOT NULL AS revisado,
                    g.codigo AS causa_codigo, g.descripcion AS causa_descripcion,
                    (SELECT GROUP_CONCAT(e.codigo ORDER BY e.codigo SEPARATOR \' \')
                     FROM resultado_evidencia re JOIN evidencias e ON e.id = re.evidencia_id
                     WHERE re.resultado_id = r.id) AS evidencias
             FROM resultados r
             LEFT JOIN (' . EstadoVigente::SUBCONSULTA_REVISIONES . ') AS rev ON rev.resultado_id = r.id
             LEFT JOIN grupos_causa g ON g.id = r.grupo_causa_id
             WHERE r.analisis_id = ?
             ORDER BY r.id'
        );
        $consulta->execute([$analisisId]);

        return array_map(self::normalizar(...), $consulta->fetchAll());
    }

    /** Un resultado con su estado vigente. @return array<string, mixed>|null */
    public function resultado(int $id): ?array
    {
        $consulta = $this->bd->prepare(
            'SELECT r.*, ' . EstadoVigente::EXPRESION_ESTADO . ' AS estado_vigente,
                    ' . EstadoVigente::EXPRESION_PENDIENTE . ' AS revision_pendiente,
                    rev.ultimo_estado IS NOT NULL AS revisado,
                    g.codigo AS causa_codigo, g.descripcion AS causa_descripcion
             FROM resultados r
             LEFT JOIN (' . EstadoVigente::SUBCONSULTA_REVISIONES . ') AS rev ON rev.resultado_id = r.id
             LEFT JOIN grupos_causa g ON g.id = r.grupo_causa_id
             WHERE r.id = ?'
        );
        $consulta->execute([$id]);
        $fila = $consulta->fetch();

        return $fila === false ? null : self::normalizar($fila);
    }

    /** Evidencias originales del resultado (las que guardó el análisis). @return list<array<string, mixed>> */
    public function evidencias(int $resultadoId): array
    {
        $consulta = $this->bd->prepare(
            'SELECT e.id, e.codigo, e.tipo, e.contenido, e.tipo_mime, e.url_recurso, e.truncado, e.capturado_en
             FROM resultado_evidencia re JOIN evidencias e ON e.id = re.evidencia_id
             WHERE re.resultado_id = ? ORDER BY e.codigo'
        );
        $consulta->execute([$resultadoId]);

        return $consulta->fetchAll();
    }

    /**
     * Revisiones humanas del resultado, de la más antigua a la más reciente, con la evidencia
     * que añadió cada revisor (RF-41).
     *
     * @return list<array<string, mixed>> cada revisión lleva 'evidencias'
     */
    public function revisiones(int $resultadoId): array
    {
        $consulta = $this->bd->prepare(
            'SELECT id, revisor, motivo, decision, nuevo_estado, justificacion, creado_en
             FROM revisiones WHERE resultado_id = ? ORDER BY creado_en, id'
        );
        $consulta->execute([$resultadoId]);
        $revisiones = $consulta->fetchAll();
        if ($revisiones === []) {
            return [];
        }

        $evidencias = $this->bd->prepare(
            'SELECT e.id, e.revision_id, e.codigo, e.tipo, e.contenido, e.tipo_mime, e.capturado_en,
                    e.ruta_fichero IS NOT NULL AS tiene_fichero
             FROM evidencias e JOIN revisiones v ON v.id = e.revision_id
             WHERE v.resultado_id = ? ORDER BY e.codigo'
        );
        $evidencias->execute([$resultadoId]);
        $porRevision = [];
        foreach ($evidencias->fetchAll() as $e) {
            $porRevision[(int) $e['revision_id']][] = $e;
        }

        return array_map(static fn (array $v): array => $v + ['evidencias' => $porRevision[(int) $v['id']] ?? []], $revisiones);
    }

    /** Recursos consultados por el análisis (para bloqueos de acceso y límites). @return list<array<string, mixed>> */
    public function recursos(int $analisisId): array
    {
        $consulta = $this->bd->prepare(
            'SELECT id, url, url_final, estado_http, tipo_contenido, bytes, truncado, resultado, motivo, justificado_por
             FROM recursos WHERE analisis_id = ? ORDER BY id'
        );
        $consulta->execute([$analisisId]);

        return $consulta->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function fases(int $analisisId): array
    {
        $consulta = $this->bd->prepare(
            'SELECT fase, estado, hechos, total, mensaje FROM fases_analisis WHERE analisis_id = ? ORDER BY id'
        );
        $consulta->execute([$analisisId]);

        return $consulta->fetchAll();
    }

    /** Limitaciones y detenciones por seguridad registradas durante el análisis. @return list<array<string, mixed>> */
    public function eventosSeguridad(int $analisisId): array
    {
        $consulta = $this->bd->prepare(
            'SELECT fase, url_recurso, regla, condicion, decision, creado_en FROM eventos_seguridad WHERE analisis_id = ? ORDER BY id'
        );
        $consulta->execute([$analisisId]);

        return $consulta->fetchAll();
    }

    /** Convierte los indicadores 0/1 de MySQL en booleanos. */
    private static function normalizar(array $fila): array
    {
        foreach (['revision_pendiente', 'revisado', 'requiere_revision_humana', 'bloquea_dependientes', 'es_dato_prueba'] as $campo) {
            if (array_key_exists($campo, $fila)) {
                $fila[$campo] = (bool) $fila[$campo];
            }
        }

        return $fila;
    }
}
