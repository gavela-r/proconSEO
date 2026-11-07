<?php

declare(strict_types=1);

namespace ProconSEO\Run;

use PDO;
use ProconSEO\Results\EstadoVigente;

/**
 * Datos de la página de inicio: últimos análisis con su recuento por estado vigente, avisos
 * sin leer y proyectos activos. Solo lectura y siempre con sentencias preparadas.
 */
final class ResumenInicio
{
    public function __construct(private readonly PDO $bd)
    {
    }

    /**
     * Últimos análisis de cualquier proyecto, del más reciente al más antiguo.
     *
     * @return list<array<string, mixed>> cada fila lleva 'recuento' (estado vigente => número)
     */
    public function recientes(int $limite = 6): array
    {
        $consulta = $this->bd->prepare(
            'SELECT a.id, a.proyecto_id, a.url_introducida, a.url_final, a.alcance, a.estado, a.fase_actual,
                    a.es_dato_prueba, a.creado_en, p.nombre AS proyecto_nombre, p.tipo AS proyecto_tipo
             FROM analisis a JOIN proyectos p ON p.id = a.proyecto_id
             ORDER BY a.creado_en DESC, a.id DESC
             LIMIT ' . max(1, min(50, $limite))
        );
        $consulta->execute();
        $analisis = $consulta->fetchAll();
        if ($analisis === []) {
            return [];
        }

        $ids = array_map(static fn (array $a): int => (int) $a['id'], $analisis);
        $huecos = implode(',', array_fill(0, count($ids), '?'));
        $recuentos = $this->bd->prepare(
            'SELECT r.analisis_id, ' . EstadoVigente::EXPRESION_ESTADO . ' AS estado, COUNT(*) AS total
             FROM resultados r
             LEFT JOIN (' . EstadoVigente::SUBCONSULTA_REVISIONES . ') AS rev ON rev.resultado_id = r.id
             WHERE r.analisis_id IN (' . $huecos . ')
             GROUP BY r.analisis_id, ' . EstadoVigente::EXPRESION_ESTADO
        );
        $recuentos->execute($ids);

        $porAnalisis = [];
        foreach ($recuentos->fetchAll() as $fila) {
            $porAnalisis[(int) $fila['analisis_id']][(string) $fila['estado']] = (int) $fila['total'];
        }

        return array_map(static fn (array $a): array => $a + ['recuento' => $porAnalisis[(int) $a['id']] ?? []], $analisis);
    }

    /** @return list<array<string, mixed>> */
    public function avisosSinLeer(int $limite = 4): array
    {
        return (new Avisos($this->bd))->lista(true, $limite);
    }

    /**
     * Proyectos activos con su número de análisis, los últimos usados primero.
     *
     * @return list<array<string, mixed>>
     */
    public function proyectos(int $limite = 5): array
    {
        $consulta = $this->bd->prepare(
            "SELECT p.id, p.nombre, p.tipo, p.sector, p.es_dato_prueba, COUNT(a.id) AS analisis, MAX(a.creado_en) AS ultimo
             FROM proyectos p LEFT JOIN analisis a ON a.proyecto_id = p.id
             WHERE p.estado = 'activo'
             GROUP BY p.id, p.nombre, p.tipo, p.sector, p.es_dato_prueba
             ORDER BY ultimo IS NULL, ultimo DESC, p.nombre
             LIMIT " . max(1, min(50, $limite))
        );
        $consulta->execute();

        return $consulta->fetchAll();
    }

    public function totalProyectosActivos(): int
    {
        return (int) $this->bd->query("SELECT COUNT(*) FROM proyectos WHERE estado = 'activo'")->fetchColumn();
    }
}
