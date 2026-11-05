<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Review;

use PDO;
use PHPUnit\Framework\TestCase;
use ProconSEO\Results\RepositorioResultados;
use ProconSEO\Review\DatosRevision;
use ProconSEO\Review\RepositorioRevisiones;
use ProconSEO\Shared\BaseDatos;
use ProconSEO\Shared\Config;

/**
 * Guardado de revisiones contra la base de datos local (RF-41, RF-42). Se salta si no hay base de datos.
 */
final class RepositorioRevisionesTest extends TestCase
{
    private PDO $bd;
    private RepositorioRevisiones $repo;
    private int $proyectoId;
    private int $analisisId;
    private int $resultadoId;

    protected function setUp(): void
    {
        Config::cargar(dirname(__DIR__, 2));
        try {
            $this->bd = BaseDatos::conexion();
            $this->bd->query('SELECT 1 FROM revisiones LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('Sin base de datos local (composer migrar).');
        }

        $this->repo = new RepositorioRevisiones($this->bd);
        $this->bd->exec("INSERT INTO proyectos (nombre, es_dato_prueba) VALUES ('[TEST-P3] Revisiones', 1)");
        $this->proyectoId = (int) $this->bd->lastInsertId();
        $perfil = (int) $this->bd->query('SELECT id FROM perfiles_ejecucion ORDER BY id LIMIT 1')->fetchColumn();
        $this->bd->prepare("INSERT INTO analisis (proyecto_id, url_introducida, alcance, perfil_id, estado) VALUES (?, 'https://ejemplo.es/', 'url_individual', ?, 'completado')")
            ->execute([$this->proyectoId, $perfil]);
        $this->analisisId = (int) $this->bd->lastInsertId();
        $this->bd->prepare(
            "INSERT INTO resultados (analisis_id, id_comprobacion, id_criterio, bloque, estado, ejecucion, cobertura, tipo_conclusion,
                                     explicacion_sencilla, requiere_revision_humana, version_regla)
             VALUES (?, 'ACC-01.alt-adecuado', 'ACC-01', 'acc', 'no_verificable', 'ejecutada', 'implementada', 'inferencia', 'Prueba', 1, 'v1')"
        )->execute([$this->analisisId]);
        $this->resultadoId = (int) $this->bd->lastInsertId();
        // Ya hay evidencias del análisis: las del revisor siguen la numeración.
        $this->bd->prepare("INSERT INTO evidencias (analisis_id, codigo, tipo, contenido) VALUES (?, 'E-001', 'texto', 'x'), (?, 'E-009', 'texto', 'y')")
            ->execute([$this->analisisId, $this->analisisId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->proyectoId)) {
            $this->bd->prepare('DELETE FROM proyectos WHERE id = ?')->execute([$this->proyectoId]);
        }
    }

    public function testGuardarNoModificaElResultadoOriginal(): void
    {
        $antes = $this->bd->query('SELECT * FROM resultados WHERE id = ' . $this->resultadoId)->fetch();

        $id = $this->repo->guardar($this->resultadoId, $this->analisisId, $this->datos(['decision' => 'cambiar_estado', 'nuevo_estado' => 'correcto', 'evidencia_texto' => 'Correo del cliente']), null);

        self::assertSame($antes, $this->bd->query('SELECT * FROM resultados WHERE id = ' . $this->resultadoId)->fetch(), 'RF-42: el original no cambia');
        $revision = $this->bd->query('SELECT * FROM revisiones WHERE id = ' . $id)->fetch();
        self::assertSame('Marta', $revision['revisor']);
        self::assertSame('cambiar_estado', $revision['decision']);
        self::assertSame('correcto', $revision['nuevo_estado']);

        $resultado = (new RepositorioResultados($this->bd))->resultado($this->resultadoId);
        self::assertSame('correcto', $resultado['estado_vigente']);
        self::assertSame('no_verificable', $resultado['estado']);
        self::assertFalse($resultado['revision_pendiente']);
    }

    public function testEvidenciasDelRevisorNumeradasYVinculadas(): void
    {
        $captura = ['ruta' => 'analisis-1/' . str_repeat('a', 32) . '.png', 'mime' => 'image/png', 'bytes' => 100];

        $id = $this->repo->guardar($this->resultadoId, $this->analisisId, $this->datos(['evidencia_texto' => 'Lo he probado con NVDA']), $captura);

        $evidencias = $this->bd->query('SELECT id, codigo, tipo, contenido, ruta_fichero, tipo_mime FROM evidencias WHERE revision_id = ' . $id . ' ORDER BY codigo')->fetchAll();
        self::assertSame(['E-010', 'E-011'], array_column($evidencias, 'codigo'), 'Siguen a la última evidencia del análisis');
        self::assertSame(['texto', 'captura'], array_column($evidencias, 'tipo'));
        self::assertSame($captura['ruta'], $evidencias[1]['ruta_fichero']);

        self::assertSame(['ruta_fichero' => $captura['ruta'], 'tipo_mime' => 'image/png'], $this->repo->captura((int) $evidencias[1]['id']));
        self::assertNull($this->repo->captura((int) $evidencias[0]['id']), 'Un texto no se sirve como captura');

        $revisiones = (new RepositorioResultados($this->bd))->revisiones($this->resultadoId);
        self::assertSame([false, true], array_map(static fn (array $e): bool => (bool) $e['tiene_fichero'], $revisiones[0]['evidencias']));
    }

    public function testCadaRevisionApuntaALaAnteriorYNingunaSeBorra(): void
    {
        $primera = $this->repo->guardar($this->resultadoId, $this->analisisId, $this->datos(['decision' => 'pedir_evidencia']), null);
        $segunda = $this->repo->guardar($this->resultadoId, $this->analisisId, $this->datos(), null);

        $filas = $this->bd->query('SELECT id, sustituye_a FROM revisiones WHERE resultado_id = ' . $this->resultadoId . ' ORDER BY id')->fetchAll();
        self::assertSame([$primera, $segunda], array_map(static fn (array $f): int => (int) $f['id'], $filas));
        self::assertNull($filas[0]['sustituye_a']);
        self::assertSame($primera, (int) $filas[1]['sustituye_a']);
    }

    public function testSiFallaNoQuedaNadaAMedias(): void
    {
        try {
            $this->repo->guardar(PHP_INT_MAX >> 33, $this->analisisId, $this->datos(['evidencia_texto' => 'x']), null);
            self::fail('Debía fallar: el resultado no existe');
        } catch (\PDOException) {
        }

        self::assertSame(0, (int) $this->bd->query('SELECT COUNT(*) FROM evidencias WHERE analisis_id = ' . $this->analisisId . ' AND revision_id IS NOT NULL')->fetchColumn());
    }

    private function datos(array $campos = []): DatosRevision
    {
        return DatosRevision::desdeFormulario($campos + [
            'revisor' => 'Marta', 'motivo' => 'Revisión', 'decision' => 'confirmar', 'justificacion' => 'Porque sí',
        ], 'no_verificable');
    }
}
