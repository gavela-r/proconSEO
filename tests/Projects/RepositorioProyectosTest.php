<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Projects;

use PDO;
use PHPUnit\Framework\TestCase;
use ProconSEO\Projects\DatosProyecto;
use ProconSEO\Projects\RepositorioProyectos;
use ProconSEO\Shared\BaseDatos;
use ProconSEO\Shared\Config;
use ProconSEO\Shared\Enum\EstadoProyecto;
use ProconSEO\Shared\Enum\TipoProyecto;

/**
 * Proyectos e histórico contra la base de datos local (RF-01 a RF-04).
 * Se salta si no hay base de datos. Todo lo que crea lleva un prefijo único y se borra al terminar.
 */
final class RepositorioProyectosTest extends TestCase
{
    private PDO $bd;
    private RepositorioProyectos $repo;
    private string $prefijo;

    protected function setUp(): void
    {
        Config::cargar(dirname(__DIR__, 2));
        try {
            $this->bd = BaseDatos::conexion();
            $this->bd->query('SELECT 1 FROM revisiones LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('Sin base de datos local (composer migrar).');
        }

        $this->repo = new RepositorioProyectos($this->bd);
        $this->prefijo = '[TEST-P3-' . bin2hex(random_bytes(4)) . ']';
    }

    protected function tearDown(): void
    {
        if (isset($this->bd)) {
            $this->bd->prepare('DELETE FROM proyectos WHERE nombre LIKE ?')->execute([$this->prefijo . '%']);
        }
    }

    public function testCrearYObtener(): void
    {
        $id = $this->crear('Gasolinera', ['cliente' => 'Cliente S.L.', 'url_principal' => 'https://gasolinera.es/', 'tipo' => 'web_cliente', 'sector' => 'hidrocarburos']);

        $proyecto = $this->repo->obtener($id);

        self::assertNotNull($proyecto);
        self::assertSame($this->prefijo . ' Gasolinera', $proyecto['nombre']);
        self::assertSame('Cliente S.L.', $proyecto['cliente']);
        self::assertSame('web_cliente', $proyecto['tipo']);
        self::assertSame('hidrocarburos', $proyecto['sector']);
        self::assertSame('activo', $proyecto['estado']);
        self::assertSame(0, (int) $proyecto['es_dato_prueba'], 'Un proyecto creado desde la aplicación nunca es de prueba');
    }

    public function testObtenerUnoQueNoExiste(): void
    {
        self::assertNull($this->repo->obtener(PHP_INT_MAX >> 33));
    }

    public function testActualizarNoTocaLaMarcaDeDatosDePrueba(): void
    {
        $this->bd->prepare('INSERT INTO proyectos (nombre, es_dato_prueba) VALUES (?, 1)')->execute([$this->prefijo . ' Demo']);
        $id = (int) $this->bd->lastInsertId();

        $this->repo->actualizar($id, DatosProyecto::desdeFormulario(['nombre' => $this->prefijo . ' Demo editada', 'estado' => 'cerrado', 'tipo' => 'otra']));

        $proyecto = $this->repo->obtener($id);
        self::assertSame($this->prefijo . ' Demo editada', $proyecto['nombre']);
        self::assertSame('cerrado', $proyecto['estado']);
        self::assertSame(1, (int) $proyecto['es_dato_prueba']);
    }

    public function testBuscarPorNombreClienteODireccion(): void
    {
        $a = $this->crear('Alfa', ['cliente' => 'Panadería Lola']);
        $b = $this->crear('Beta', ['url_principal' => 'https://www.clinica-norte.es/']);
        $this->crear('Gamma');

        self::assertSame([$a], $this->ids($this->repo->buscar($this->prefijo . ' Alfa')));
        self::assertContains($a, $this->ids($this->repo->buscar('panadería lola')), 'Sin distinguir mayúsculas');
        self::assertContains($b, $this->ids($this->repo->buscar('clinica-norte')));
        self::assertCount(3, $this->repo->buscar($this->prefijo));
    }

    public function testLosComodinesSeBuscanLiteralmente(): void
    {
        $con = $this->crear('100% natural_bio');
        $this->crear('100 naturalXbio');

        self::assertSame([$con], $this->ids($this->repo->buscar($this->prefijo . ' 100% natural_')));
        self::assertSame([], $this->repo->buscar($this->prefijo . '%'), 'El % del usuario no es un comodín');
    }

    public function testFiltrosPorTipoYEstado(): void
    {
        $kit = $this->crear('Kit', ['tipo' => 'web_kit_digital']);
        $tienda = $this->crear('Tienda', ['tipo' => 'tienda_kit_digital', 'estado' => 'en_pausa']);
        $archivado = $this->crear('Viejo', ['tipo' => 'web_kit_digital', 'estado' => 'archivado']);

        self::assertSame([$kit], $this->ids($this->repo->buscar($this->prefijo, TipoProyecto::WebKitDigital)), 'Por defecto no salen los archivados');
        self::assertSame([$tienda], $this->ids($this->repo->buscar($this->prefijo, null, EstadoProyecto::EnPausa)));
        self::assertSame([$archivado], $this->ids($this->repo->buscar($this->prefijo, null, EstadoProyecto::Archivado)));
    }

    public function testBuscarDevuelveElUltimoAnalisis(): void
    {
        $id = $this->crear('Con análisis');
        $this->analisis($id, 'completado', '2026-11-01 10:00:00');
        $ultimo = $this->analisis($id, 'en_curso', '2026-11-03 10:00:00');
        $sinAnalisis = $this->crear('Sin análisis');

        $filas = array_column($this->repo->buscar($this->prefijo), null, 'id');

        self::assertSame($ultimo, (int) $filas[$id]['ultimo_id']);
        self::assertSame('en_curso', $filas[$id]['ultimo_estado']);
        self::assertNull($filas[$sinAnalisis]['ultimo_id']);
    }

    public function testHistorialOrdenadoYConRecuentoPorEstadoVigente(): void
    {
        $id = $this->crear('Histórico');
        $antiguo = $this->analisis($id, 'cancelado', '2026-11-01 10:00:00');
        $reciente = $this->analisis($id, 'completado', '2026-11-03 10:00:00');

        $this->resultado($reciente, 'problema');
        $this->resultado($reciente, 'problema');
        $revisado = $this->resultado($reciente, 'decision_revisar');
        $this->resultado($reciente, 'no_verificable', requiereRevision: true); // pendiente: sin revisión
        $pidioEvidencia = $this->resultado($reciente, 'no_verificable', requiereRevision: true);
        $resuelto = $this->resultado($reciente, 'no_verificable', requiereRevision: true);

        // RF-42: la revisión cambia el estado vigente, nunca el original.
        $this->revision($revisado, 'cambiar_estado', 'correcto', '2026-11-03 11:00:00');
        $this->revision($revisado, 'confirmar', null, '2026-11-03 12:00:00');
        $this->revision($pidioEvidencia, 'pedir_evidencia', null, '2026-11-03 11:00:00');
        $this->revision($resuelto, 'cambiar_estado', 'mejorable', '2026-11-03 11:00:00');

        $historial = $this->repo->historial($id);

        self::assertSame([$reciente, $antiguo], array_map(static fn (array $a): int => (int) $a['id'], $historial));
        self::assertSame($this->ordenado(['problema' => 2, 'correcto' => 1, 'no_verificable' => 2, 'mejorable' => 1]), $this->ordenado($historial[0]['recuento']));
        self::assertSame(2, $historial[0]['pendientes'], 'Pendientes: sin revisión o con más evidencia pedida');
        self::assertSame([], $historial[1]['recuento']);
        self::assertSame(0, $historial[1]['pendientes']);

        $original = $this->bd->query('SELECT estado FROM resultados WHERE id = ' . $revisado)->fetchColumn();
        self::assertSame('decision_revisar', $original, 'El resultado original no se modifica');
    }

    public function testHistorialVacio(): void
    {
        self::assertSame([], $this->repo->historial($this->crear('Nuevo')));
    }

    // ---------- Ayudas ----------

    private function crear(string $nombre, array $campos = []): int
    {
        return $this->repo->crear(DatosProyecto::desdeFormulario(['nombre' => $this->prefijo . ' ' . $nombre] + $campos));
    }

    private function analisis(int $proyectoId, string $estado, string $fecha): int
    {
        $perfil = (int) $this->bd->query('SELECT id FROM perfiles_ejecucion ORDER BY id LIMIT 1')->fetchColumn();
        $this->bd->prepare(
            "INSERT INTO analisis (proyecto_id, url_introducida, alcance, perfil_id, estado, creado_en) VALUES (?, 'https://ejemplo.es/', 'url_individual', ?, ?, ?)"
        )->execute([$proyectoId, $perfil, $estado, $fecha]);

        return (int) $this->bd->lastInsertId();
    }

    private function resultado(int $analisisId, string $estado, bool $requiereRevision = false): int
    {
        $this->bd->prepare(
            "INSERT INTO resultados (analisis_id, id_comprobacion, id_criterio, bloque, estado, ejecucion, cobertura, tipo_conclusion,
                                     explicacion_sencilla, requiere_revision_humana, version_regla)
             VALUES (?, 'SEO-03.title-presente', 'SEO-03', 'seo', ?, 'ejecutada', 'implementada', 'hecho', 'Prueba', ?, 'v1')"
        )->execute([$analisisId, $estado, (int) $requiereRevision]);

        return (int) $this->bd->lastInsertId();
    }

    private function revision(int $resultadoId, string $decision, ?string $nuevoEstado, string $fecha): void
    {
        $this->bd->prepare(
            "INSERT INTO revisiones (resultado_id, revisor, motivo, decision, nuevo_estado, justificacion, creado_en) VALUES (?, 'Test', 'Motivo', ?, ?, 'Justificación', ?)"
        )->execute([$resultadoId, $decision, $nuevoEstado, $fecha]);
    }

    /** @return list<int> */
    private function ids(array $filas): array
    {
        $ids = array_map(static fn (array $f): int => (int) $f['id'], $filas);
        sort($ids);

        return $ids;
    }

    /** El orden de las claves no importa: se comparan ordenadas. */
    private function ordenado(array $recuento): array
    {
        ksort($recuento);

        return $recuento;
    }
}
