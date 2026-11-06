<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Results;

use PDO;
use PHPUnit\Framework\TestCase;
use ProconSEO\Results\RepositorioResultados;
use ProconSEO\Shared\BaseDatos;
use ProconSEO\Shared\Config;

/**
 * Lectura de resultados, evidencias y revisiones contra la base de datos local (RF-22, RF-41, RF-42).
 * Se salta si no hay base de datos. Lo que crea se borra al terminar (en cascada desde el proyecto).
 */
final class RepositorioResultadosTest extends TestCase
{
    private PDO $bd;
    private RepositorioResultados $repo;
    private int $proyectoId;
    private int $analisisId;

    protected function setUp(): void
    {
        Config::cargar(dirname(__DIR__, 2));
        try {
            $this->bd = BaseDatos::conexion();
            $this->bd->query('SELECT 1 FROM revisiones LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('Sin base de datos local (composer migrar).');
        }

        $this->repo = new RepositorioResultados($this->bd);
        $this->bd->exec("INSERT INTO proyectos (nombre, es_dato_prueba) VALUES ('[TEST-P3] Resultados', 1)");
        $this->proyectoId = (int) $this->bd->lastInsertId();
        $perfil = (int) $this->bd->query('SELECT id FROM perfiles_ejecucion ORDER BY id LIMIT 1')->fetchColumn();
        $this->bd->prepare("INSERT INTO analisis (proyecto_id, url_introducida, alcance, perfil_id, estado) VALUES (?, 'https://ejemplo.es/', 'url_individual', ?, 'completado')")
            ->execute([$this->proyectoId, $perfil]);
        $this->analisisId = (int) $this->bd->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->proyectoId)) {
            $this->bd->prepare('DELETE FROM proyectos WHERE id = ?')->execute([$this->proyectoId]);
        }
    }

    public function testAnalisisConSuProyecto(): void
    {
        $analisis = $this->repo->analisis($this->analisisId);

        self::assertSame('[TEST-P3] Resultados', $analisis['proyecto_nombre']);
        self::assertSame(1, (int) $analisis['proyecto_es_dato_prueba']);
        self::assertNotNull($analisis['perfil_version']);
        self::assertNull($this->repo->analisis(PHP_INT_MAX >> 33));
    }

    public function testAnalisisAnteriorDeLaMismaDireccionYAlcance(): void
    {
        $perfil = (int) $this->bd->query('SELECT id FROM perfiles_ejecucion ORDER BY id LIMIT 1')->fetchColumn();
        $nuevo = function (string $url, string $alcance, string $estado) use ($perfil): int {
            $this->bd->prepare('INSERT INTO analisis (proyecto_id, url_introducida, url_normalizada, alcance, perfil_id, estado) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$this->proyectoId, $url, $url, $alcance, $perfil, $estado]);

            return (int) $this->bd->lastInsertId();
        };
        $primero = $nuevo('https://ejemplo.es/', 'url_individual', 'parcial');
        $nuevo('https://ejemplo.es/', 'url_individual', 'cancelado');
        $nuevo('https://ejemplo.es/', 'sitio_completo', 'completado');
        $nuevo('https://otra.es/', 'url_individual', 'completado');
        $actual = $nuevo('https://ejemplo.es/', 'url_individual', 'completado');

        $anterior = $this->repo->analisisAnterior((array) $this->repo->analisis($actual));

        self::assertSame($primero, (int) ($anterior['id'] ?? 0), 'Ni el cancelado, ni otro alcance, ni otra dirección');
        self::assertNull($this->repo->analisisAnterior((array) $this->repo->analisis($primero)));
    }

    public function testResultadosConEstadoVigenteCausaYEvidencias(): void
    {
        $this->bd->prepare("INSERT INTO grupos_causa (analisis_id, codigo, descripcion) VALUES (?, 'C-001', 'Causa de prueba')")->execute([$this->analisisId]);
        $grupo = (int) $this->bd->lastInsertId();

        $problema = $this->resultado('problema', ['grupo_causa_id' => $grupo, 'gravedad' => 'alto', 'bloquea_dependientes' => 1]);
        $revisado = $this->resultado('decision_revisar');
        $pendiente = $this->resultado('no_verificable', ['requiere_revision_humana' => 1]);
        $this->evidencia('E-002', $problema);
        $this->evidencia('E-001', $problema);
        $this->revision($revisado, 'cambiar_estado', 'correcto');

        $filas = array_column($this->repo->resultados($this->analisisId), null, 'id');

        self::assertSame('problema', $filas[$problema]['estado_vigente']);
        self::assertSame('C-001', $filas[$problema]['causa_codigo']);
        self::assertSame('E-001 E-002', $filas[$problema]['evidencias']);
        self::assertTrue($filas[$problema]['bloquea_dependientes']);
        self::assertFalse($filas[$problema]['revisado']);

        self::assertSame('decision_revisar', $filas[$revisado]['estado'], 'El original no cambia');
        self::assertSame('correcto', $filas[$revisado]['estado_vigente']);
        self::assertTrue($filas[$revisado]['revisado']);

        self::assertTrue($filas[$pendiente]['revision_pendiente']);
        self::assertFalse($filas[$problema]['revision_pendiente']);
    }

    public function testDetalleConEvidenciasYRevisiones(): void
    {
        $id = $this->resultado('no_verificable', ['requiere_revision_humana' => 1]);
        $this->evidencia('E-001', $id, '<img src="logo.png" alt="logo">');
        $primera = $this->revision($id, 'pedir_evidencia', null, '2026-11-04 10:00:00');
        $segunda = $this->revision($id, 'cambiar_estado', 'correcto', '2026-11-04 11:00:00');
        $this->bd->prepare("INSERT INTO evidencias (analisis_id, codigo, tipo, contenido, revision_id) VALUES (?, 'E-002', 'texto', 'Correo del cliente', ?)")
            ->execute([$this->analisisId, $segunda]);

        $resultado = $this->repo->resultado($id);
        self::assertSame('correcto', $resultado['estado_vigente']);
        self::assertFalse($resultado['revision_pendiente']);

        $evidencias = $this->repo->evidencias($id);
        self::assertSame(['E-001'], array_column($evidencias, 'codigo'), 'Las evidencias del revisor no se mezclan con las originales');
        self::assertSame('<img src="logo.png" alt="logo">', $evidencias[0]['contenido'], 'Se guarda tal cual; se escapa al mostrarlo');

        $revisiones = $this->repo->revisiones($id);
        self::assertSame([$primera, $segunda], array_map(static fn (array $v): int => (int) $v['id'], $revisiones), 'De la más antigua a la más reciente');
        self::assertSame([], $revisiones[0]['evidencias']);
        self::assertSame(['E-002'], array_column($revisiones[1]['evidencias'], 'codigo'));
    }

    public function testRecursosFasesYEventos(): void
    {
        $this->bd->prepare("INSERT INTO recursos (analisis_id, url, estado_http, resultado, justificado_por) VALUES (?, 'https://ejemplo.es/robots.txt', 404, 'error_http', 'IA-01')")->execute([$this->analisisId]);
        $this->bd->prepare("INSERT INTO fases_analisis (analisis_id, fase, estado) VALUES (?, 'renderizado', 'omitida')")->execute([$this->analisisId]);
        $this->bd->prepare("INSERT INTO eventos_seguridad (analisis_id, regla, condicion, decision) VALUES (?, 'SEG-03', 'HTTP sin cifrar', 'continuar')")->execute([$this->analisisId]);

        self::assertSame(404, (int) $this->repo->recursos($this->analisisId)[0]['estado_http']);
        self::assertSame('omitida', $this->repo->fases($this->analisisId)[0]['estado']);
        self::assertSame('SEG-03', $this->repo->eventosSeguridad($this->analisisId)[0]['regla']);
    }

    public function testResultadoQueNoExiste(): void
    {
        self::assertNull($this->repo->resultado(PHP_INT_MAX >> 33));
        self::assertSame([], $this->repo->revisiones(PHP_INT_MAX >> 33));
    }

    // ---------- Ayudas ----------

    private function resultado(string $estado, array $campos = []): int
    {
        $campos += ['grupo_causa_id' => null, 'gravedad' => null, 'bloquea_dependientes' => 0, 'requiere_revision_humana' => 0];
        $this->bd->prepare(
            "INSERT INTO resultados (analisis_id, id_comprobacion, id_criterio, bloque, estado, ejecucion, cobertura, tipo_conclusion,
                                     explicacion_sencilla, gravedad, grupo_causa_id, bloquea_dependientes, requiere_revision_humana, version_regla)
             VALUES (?, 'SEO-01.noindex-desarrollo', 'SEO-01', 'seo', ?, 'ejecutada', 'implementada', 'hecho', 'Prueba', ?, ?, ?, ?, 'v1')"
        )->execute([$this->analisisId, $estado, $campos['gravedad'], $campos['grupo_causa_id'], $campos['bloquea_dependientes'], $campos['requiere_revision_humana']]);

        return (int) $this->bd->lastInsertId();
    }

    private function evidencia(string $codigo, int $resultadoId, string $contenido = '<meta name="robots" content="noindex">'): void
    {
        $this->bd->prepare("INSERT INTO evidencias (analisis_id, codigo, tipo, contenido) VALUES (?, ?, 'fragmento_html', ?)")
            ->execute([$this->analisisId, $codigo, $contenido]);
        $this->bd->prepare('INSERT INTO resultado_evidencia (resultado_id, evidencia_id) VALUES (?, ?)')
            ->execute([$resultadoId, (int) $this->bd->lastInsertId()]);
    }

    private function revision(int $resultadoId, string $decision, ?string $nuevoEstado, string $fecha = '2026-11-04 10:00:00'): int
    {
        $this->bd->prepare("INSERT INTO revisiones (resultado_id, revisor, motivo, decision, nuevo_estado, justificacion, creado_en) VALUES (?, 'Test', 'Motivo', ?, ?, 'Justificación', ?)")
            ->execute([$resultadoId, $decision, $nuevoEstado, $fecha]);

        return (int) $this->bd->lastInsertId();
    }
}
