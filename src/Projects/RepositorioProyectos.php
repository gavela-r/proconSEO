<?php

declare(strict_types=1);

namespace ProconSEO\Projects;

use PDO;
use ProconSEO\Results\EstadoVigente;
use ProconSEO\Shared\Enum\EstadoProyecto;
use ProconSEO\Shared\Enum\TipoProyecto;

/**
 * Acceso a la tabla proyectos y al histórico de análisis de cada proyecto (Parte 3).
 * Solo sentencias preparadas. Esta parte no lanza análisis: solo los lee.
 */
final class RepositorioProyectos
{
    /** Máximo de proyectos que devuelve una búsqueda. */
    public const LIMITE_BUSQUEDA = 200;

    public function __construct(private readonly PDO $bd)
    {
    }

    public function crear(DatosProyecto $datos): int
    {
        $this->bd->prepare(
            'INSERT INTO proyectos (nombre, cliente, url_principal, descripcion, estado, tipo, sector, es_dato_prueba)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0)'
        )->execute($this->valores($datos));

        return (int) $this->bd->lastInsertId();
    }

    /** Actualiza los datos editables. No cambia es_dato_prueba: un proyecto de demostración lo sigue siendo. */
    public function actualizar(int $id, DatosProyecto $datos): void
    {
        $this->bd->prepare(
            'UPDATE proyectos SET nombre = ?, cliente = ?, url_principal = ?, descripcion = ?, estado = ?, tipo = ?, sector = ?
             WHERE id = ?'
        )->execute([...$this->valores($datos), $id]);
    }

    /** @return array<string, mixed>|null */
    public function obtener(int $id): ?array
    {
        $consulta = $this->bd->prepare(
            'SELECT id, nombre, cliente, url_principal, descripcion, estado, tipo, sector, es_dato_prueba, creado_en, actualizado_en
             FROM proyectos WHERE id = ?'
        );
        $consulta->execute([$id]);
        $fila = $consulta->fetch();

        return $fila === false ? null : $fila;
    }

    /**
     * Busca proyectos por texto (nombre, cliente o dirección), tipo y estado. Sin estado,
     * se muestran todos menos los archivados. Cada fila trae su último análisis.
     *
     * @return list<array<string, mixed>>
     */
    public function buscar(string $texto = '', ?TipoProyecto $tipo = null, ?EstadoProyecto $estado = null): array
    {
        $condiciones = [];
        $parametros = [];

        $texto = trim($texto);
        if ($texto !== '') {
            $patron = '%' . self::escaparLike($texto) . '%';
            $condiciones[] = "(p.nombre LIKE ? ESCAPE '!' OR p.cliente LIKE ? ESCAPE '!' OR p.url_principal LIKE ? ESCAPE '!')";
            array_push($parametros, $patron, $patron, $patron);
        }
        if ($tipo !== null) {
            $condiciones[] = 'p.tipo = ?';
            $parametros[] = $tipo->value;
        }
        if ($estado !== null) {
            $condiciones[] = 'p.estado = ?';
            $parametros[] = $estado->value;
        } else {
            $condiciones[] = 'p.estado <> ?';
            $parametros[] = EstadoProyecto::Archivado->value;
        }

        $sql = 'SELECT p.id, p.nombre, p.cliente, p.url_principal, p.estado, p.tipo, p.sector, p.es_dato_prueba,
                       u.id AS ultimo_id, u.estado AS ultimo_estado, u.creado_en AS ultimo_creado_en
                FROM proyectos p
                LEFT JOIN analisis u ON u.id = (
                    SELECT a.id FROM analisis a WHERE a.proyecto_id = p.id ORDER BY a.creado_en DESC, a.id DESC LIMIT 1
                )
                WHERE ' . implode(' AND ', $condiciones) . '
                ORDER BY p.nombre
                LIMIT ' . self::LIMITE_BUSQUEDA;

        $consulta = $this->bd->prepare($sql);
        $consulta->execute($parametros);

        return $consulta->fetchAll();
    }

    /**
     * Histórico de análisis del proyecto, del más reciente al más antiguo (RF-04), con el
     * recuento de resultados por estado vigente y las revisiones pendientes de cada uno.
     *
     * @return list<array<string, mixed>> cada fila lleva 'recuento' (estado => número) y 'pendientes'
     */
    public function historial(int $proyectoId): array
    {
        $consulta = $this->bd->prepare(
            'SELECT id, url_introducida, url_final, alcance, estado, fase_actual, motivo_cierre, es_dato_prueba,
                    creado_en, iniciado_en, terminado_en
             FROM analisis WHERE proyecto_id = ? ORDER BY creado_en DESC, id DESC'
        );
        $consulta->execute([$proyectoId]);
        $analisis = $consulta->fetchAll();
        if ($analisis === []) {
            return [];
        }

        $recuentos = $this->bd->prepare(
            'SELECT r.analisis_id, ' . EstadoVigente::EXPRESION_ESTADO . ' AS estado, COUNT(*) AS total,
                    SUM(' . EstadoVigente::EXPRESION_PENDIENTE . ') AS pendientes
             FROM resultados r
             JOIN analisis a ON a.id = r.analisis_id
             LEFT JOIN (' . EstadoVigente::SUBCONSULTA_REVISIONES . ') AS rev ON rev.resultado_id = r.id
             WHERE a.proyecto_id = ?
             GROUP BY r.analisis_id, ' . EstadoVigente::EXPRESION_ESTADO
        );
        $recuentos->execute([$proyectoId]);

        $porAnalisis = [];
        foreach ($recuentos->fetchAll() as $fila) {
            $id = (int) $fila['analisis_id'];
            $porAnalisis[$id]['recuento'][(string) $fila['estado']] = (int) $fila['total'];
            $porAnalisis[$id]['pendientes'] = ($porAnalisis[$id]['pendientes'] ?? 0) + (int) $fila['pendientes'];
        }

        return array_map(static fn (array $a): array => $a + [
            'recuento' => $porAnalisis[(int) $a['id']]['recuento'] ?? [],
            'pendientes' => $porAnalisis[(int) $a['id']]['pendientes'] ?? 0,
        ], $analisis);
    }

    /** @return list<string|null> */
    private function valores(DatosProyecto $datos): array
    {
        return [
            $datos->nombre,
            $datos->cliente,
            $datos->urlPrincipal,
            $datos->descripcion,
            $datos->estado->value,
            $datos->tipo?->value,
            $datos->sector?->value,
        ];
    }

    /** Escapa los comodines de LIKE para buscar el texto literal (con ESCAPE '!'). */
    private static function escaparLike(string $texto): string
    {
        return strtr($texto, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }
}
