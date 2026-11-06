<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Export;

use PDO;
use PHPUnit\Framework\TestCase;
use ProconSEO\Export\DatosExportacion;
use ProconSEO\Results\RepositorioResultados;
use ProconSEO\Shared\BaseDatos;
use ProconSEO\Shared\Config;

/**
 * Datos que se exportan de un análisis, contra la base de datos local (RF-51, RF-52).
 * Se salta si no hay base de datos.
 */
final class DatosExportacionTest extends TestCase
{
    private PDO $bd;
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

        $this->bd->exec("INSERT INTO proyectos (nombre, tipo, es_dato_prueba) VALUES ('[TEST-P3] Exportar', 'web_kit_digital', 1)");
        $this->proyectoId = (int) $this->bd->lastInsertId();
        $perfil = (int) $this->bd->query('SELECT id FROM perfiles_ejecucion ORDER BY id LIMIT 1')->fetchColumn();
        $this->bd->prepare("INSERT INTO analisis (proyecto_id, url_introducida, alcance, perfil_id, estado, hosts_autorizados) VALUES (?, 'https://ejemplo.es/', 'url_individual', ?, 'completado', '[\"ejemplo.es\"]')")
            ->execute([$this->proyectoId, $perfil]);
        $this->analisisId = (int) $this->bd->lastInsertId();

        $this->bd->prepare(
            "INSERT INTO resultados (analisis_id, id_comprobacion, id_criterio, bloque, estado, ejecucion, cobertura, tipo_conclusion, explicacion_sencilla, gravedad, version_regla)
             VALUES (?, 'SEO-01.noindex-desarrollo', 'SEO-01', 'seo', 'problema', 'ejecutada', 'implementada', 'hecho', 'Prueba', 'critico', 'v1')"
        )->execute([$this->analisisId]);
        $resultado = (int) $this->bd->lastInsertId();
        $this->bd->prepare("INSERT INTO evidencias (analisis_id, codigo, tipo, contenido) VALUES (?, 'E-001', 'fragmento_html', '<meta name=\"robots\" content=\"noindex\">')")->execute([$this->analisisId]);
        $this->bd->prepare('INSERT INTO resultado_evidencia (resultado_id, evidencia_id) VALUES (?, ?)')->execute([$resultado, (int) $this->bd->lastInsertId()]);
        $this->bd->prepare("INSERT INTO revisiones (resultado_id, revisor, motivo, decision, nuevo_estado, justificacion) VALUES (?, 'Marta', 'Motivo', 'cambiar_estado', 'decision_revisar', 'Puede ser intencionado')")->execute([$resultado]);
        $revision = (int) $this->bd->lastInsertId();
        $this->bd->prepare("INSERT INTO evidencias (analisis_id, codigo, tipo, ruta_fichero, tipo_mime, revision_id) VALUES (?, 'E-002', 'captura', 'analisis-1/abc.png', 'image/png', ?)")->execute([$this->analisisId, $revision]);
        $this->bd->prepare("INSERT INTO recursos (analisis_id, url, estado_http, resultado, justificado_por) VALUES (?, 'https://ejemplo.es/robots.txt', 404, 'error_http', 'IA-01')")->execute([$this->analisisId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->proyectoId)) {
            $this->bd->prepare('DELETE FROM proyectos WHERE id = ?')->execute([$this->proyectoId]);
        }
    }

    public function testReuneTodoElAnalisis(): void
    {
        $d = (new DatosExportacion(new RepositorioResultados($this->bd), null))->analisis($this->analisisId);

        self::assertNotNull($d);
        self::assertTrue($d['datos_de_prueba'], 'RF-52');
        self::assertSame(['ejemplo.es'], $d['analisis']['hosts_autorizados']);
        self::assertSame(1, $d['resumen']['seo']['decision_revisar'], 'El resumen usa el estado vigente');

        $r = $d['resultados'][0];
        self::assertSame('problema', $r['estado_original']);
        self::assertSame('decision_revisar', $r['estado_vigente']);
        self::assertNull($r['gravedad'], 'Sin gravedad: ya no es "problema"');
        self::assertSame(['E-001'], $r['evidencias']);

        self::assertSame(['E-001', 'E-002'], array_column($d['evidencias'], 'codigo'));
        self::assertNull($d['evidencias'][1]['contenido'], 'La captura no va dentro del fichero');
        self::assertStringContainsString('/evidencias/', (string) $d['evidencias'][1]['captura']);
        self::assertSame(['E-002'], $d['revisiones'][0]['evidencias']);
        self::assertSame(404, $d['recursos'][0]['estado_http'], 'RF-51: fallos de acceso');
    }

    public function testAnalisisQueNoExiste(): void
    {
        self::assertNull((new DatosExportacion(new RepositorioResultados($this->bd), null))->analisis(PHP_INT_MAX >> 33));
    }
}
