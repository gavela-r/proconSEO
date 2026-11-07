<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Run;

use PDO;
use PHPUnit\Framework\TestCase;
use ProconSEO\Run\ResumenInicio;
use ProconSEO\Shared\BaseDatos;
use ProconSEO\Shared\Config;

/**
 * Datos de la página de inicio, contra la base de datos local. Crea un proyecto de prueba y lo
 * borra al terminar. Se salta si no hay base de datos.
 */
final class ResumenInicioTest extends TestCase
{
    private PDO $bd;
    private int $proyectoId;
    private int $analisisId;

    protected function setUp(): void
    {
        Config::cargar(dirname(__DIR__, 2));
        try {
            $this->bd = BaseDatos::conexion();
            $this->bd->query('SELECT 1 FROM avisos LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('Sin base de datos local (composer migrar).');
        }
        $this->bd->exec("INSERT INTO proyectos (nombre, url_principal, tipo, es_dato_prueba) VALUES ('[TEST] Resumen de inicio', 'https://inicio-prueba.es/', 'web_cliente', 1)");
        $this->proyectoId = (int) $this->bd->lastInsertId();

        // En el futuro, para que sea el más reciente aunque la base de datos tenga otros análisis.
        $perfil = (int) $this->bd->query('SELECT id FROM perfiles_ejecucion ORDER BY id LIMIT 1')->fetchColumn();
        $this->bd->prepare("INSERT INTO analisis (proyecto_id, url_introducida, alcance, perfil_id, estado, creado_en) VALUES (?, 'https://inicio-prueba.es/', 'url_individual', ?, 'completado', '2099-01-01 10:00:00')")
            ->execute([$this->proyectoId, $perfil]);
        $this->analisisId = (int) $this->bd->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->proyectoId)) {
            $this->bd->prepare('DELETE FROM proyectos WHERE id = ?')->execute([$this->proyectoId]);
        }
    }

    private function resultado(string $estado): int
    {
        $this->bd->prepare(
            "INSERT INTO resultados (analisis_id, id_comprobacion, id_criterio, bloque, estado, ejecucion, cobertura, tipo_conclusion, explicacion_sencilla, version_regla)
             VALUES (?, 'SEO-03.title-presente', 'SEO-03', 'seo', ?, 'ejecutada', 'implementada', 'hecho', 'Prueba', 'v1')"
        )->execute([$this->analisisId, $estado]);

        return (int) $this->bd->lastInsertId();
    }

    public function testRecientesConElRecuentoPorEstadoVigente(): void
    {
        $this->resultado('problema');
        $revisado = $this->resultado('problema');
        $this->resultado('correcto');
        // Una revisión humana cambia el estado: el recuento usa el estado vigente, no el original.
        $this->bd->prepare("INSERT INTO revisiones (resultado_id, revisor, motivo, decision, nuevo_estado, justificacion) VALUES (?, 'Test', 'Motivo', 'cambiar_estado', 'decision_revisar', 'Es intencionado')")
            ->execute([$revisado]);

        $recientes = (new ResumenInicio($this->bd))->recientes(1);

        self::assertCount(1, $recientes);
        self::assertSame($this->analisisId, (int) $recientes[0]['id']);
        self::assertSame('[TEST] Resumen de inicio', $recientes[0]['proyecto_nombre']);
        self::assertSame(['correcto' => 1, 'decision_revisar' => 1, 'problema' => 1], self::ordenado($recientes[0]['recuento']));
    }

    public function testAnalisisSinResultadosLlevaElRecuentoVacio(): void
    {
        $recientes = (new ResumenInicio($this->bd))->recientes(1);

        self::assertSame([], $recientes[0]['recuento']);
    }

    public function testAvisosSinLeerYProyectosActivos(): void
    {
        $this->bd->prepare("INSERT INTO avisos (proyecto_id, analisis_id, tipo, titulo) VALUES (?, ?, 'prueba', '[TEST] Aviso de inicio')")
            ->execute([$this->proyectoId, $this->analisisId]);
        $resumen = new ResumenInicio($this->bd);

        self::assertContains('[TEST] Aviso de inicio', array_column($resumen->avisosSinLeer(50), 'titulo'));
        $proyecto = array_values(array_filter($resumen->proyectos(50), fn (array $p): bool => (int) $p['id'] === $this->proyectoId));
        self::assertCount(1, $proyecto, 'Activo y con el análisis más reciente: sale entre los primeros');
        self::assertSame(1, (int) $proyecto[0]['analisis']);
        self::assertGreaterThanOrEqual(1, $resumen->totalProyectosActivos());
    }

    /**
     * @param array<string, int> $recuento
     * @return array<string, int>
     */
    private static function ordenado(array $recuento): array
    {
        ksort($recuento);

        return $recuento;
    }
}
