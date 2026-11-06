<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Results;

use PDO;
use PHPUnit\Framework\TestCase;
use ProconSEO\Results\BorradoAnalisis;
use ProconSEO\Review\CapturaSegura;
use ProconSEO\Run\RegistroAuditoria;
use ProconSEO\Shared\BaseDatos;
use ProconSEO\Shared\Config;
use ProconSEO\Shared\Error\ErrorDominio;
use ProconSEO\Shared\Vista;

/**
 * Borrado definitivo de análisis (RF-16; decisión 16) contra la base de datos local.
 * Se salta si no hay base de datos.
 */
final class BorradoAnalisisTest extends TestCase
{
    private PDO $bd;
    private BorradoAnalisis $borrado;
    private int $proyectoId;
    private string $capturas;

    protected function setUp(): void
    {
        Config::cargar(dirname(__DIR__, 2));
        try {
            $this->bd = BaseDatos::conexion();
            $this->bd->query('SELECT 1 FROM registro_auditoria LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('Sin base de datos local (composer migrar).');
        }

        $this->capturas = sys_get_temp_dir() . '/proconseo-borrado-' . bin2hex(random_bytes(4));
        mkdir($this->capturas, 0700, true);
        $this->borrado = new BorradoAnalisis($this->bd, new CapturaSegura($this->capturas), new RegistroAuditoria($this->bd));
        $this->bd->exec("INSERT INTO proyectos (nombre, es_dato_prueba) VALUES ('[TEST-P3] Borrado', 1)");
        $this->proyectoId = (int) $this->bd->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->proyectoId)) {
            $this->bd->prepare('DELETE FROM proyectos WHERE id = ?')->execute([$this->proyectoId]);
            $this->bd->prepare("DELETE FROM registro_auditoria WHERE proyecto_id = ? AND accion = 'analisis_borrado'")->execute([$this->proyectoId]);
        }
        if (isset($this->capturas) && is_dir($this->capturas)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->capturas, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->capturas);
        }
    }

    public function testBorraElAnalisisConTodoLoQueCuelgaYSusCapturas(): void
    {
        [$id, $captura] = $this->analisisCompleto('completado');
        $otro = $this->analisis('completado');

        self::assertSame(['resultados' => 1, 'evidencias' => 2, 'revisiones' => 1, 'capturas' => 1], $this->borrado->resumen($id));

        self::assertSame($this->proyectoId, $this->borrado->borrar($id, '10.0.0.5'));

        foreach (['analisis' => 'id', 'resultados' => 'analisis_id', 'evidencias' => 'analisis_id', 'fases_analisis' => 'analisis_id'] as $tabla => $columna) {
            self::assertSame(0, $this->contar("SELECT COUNT(*) FROM {$tabla} WHERE {$columna} = {$id}"), "Queda algo en {$tabla}");
        }
        self::assertFileDoesNotExist($captura, 'La captura del revisor se borra del disco');
        self::assertDirectoryDoesNotExist(dirname($captura), 'Y su carpeta, si queda vacía');
        self::assertSame(1, $this->contar("SELECT COUNT(*) FROM analisis WHERE id = {$otro}"), 'Los demás análisis no se tocan');
    }

    public function testElBorradoQuedaEnLaAuditoria(): void
    {
        [$id] = $this->analisisCompleto('completado');

        $this->borrado->borrar($id, '10.0.0.5');

        $entrada = $this->bd->query("SELECT * FROM registro_auditoria WHERE accion = 'analisis_borrado' AND analisis_id = {$id}")->fetch();
        self::assertNotFalse($entrada, 'SEG-15: el borrado se registra aunque el análisis ya no exista');
        self::assertSame($this->proyectoId, (int) $entrada['proyecto_id']);
        self::assertSame('10.0.0.5', $entrada['ip_origen']);
        self::assertStringContainsString('1 resultado, 2 evidencias, 1 revisión y 1 captura', (string) $entrada['detalle']);
    }

    public function testNoSePuedeBorrarUnAnalisisEnMarcha(): void
    {
        foreach (['en_cola', 'en_curso', 'cancelando'] as $estado) {
            $id = $this->analisis($estado);
            try {
                $this->borrado->borrar($id);
                self::fail("Se ha borrado un análisis {$estado}");
            } catch (ErrorDominio $e) {
                self::assertSame('analisis_en_marcha', $e->codigo);
                self::assertSame(409, $e->estadoHttp);
            }
            self::assertSame(1, $this->contar("SELECT COUNT(*) FROM analisis WHERE id = {$id}"), "El análisis {$estado} sigue ahí");
        }
    }

    public function testSeBorranLosTerminados(): void
    {
        foreach (['completado', 'parcial', 'cancelado', 'detenido_error', 'detenido_seguridad'] as $estado) {
            $id = $this->analisis($estado);
            $this->borrado->borrar($id);
            self::assertSame(0, $this->contar("SELECT COUNT(*) FROM analisis WHERE id = {$id}"), "No se ha borrado el análisis {$estado}");
        }
    }

    public function testUnoQueNoExiste(): void
    {
        $this->expectException(ErrorDominio::class);
        $this->borrado->borrar(PHP_INT_MAX >> 33);
    }

    public function testPantallaDeConfirmacion(): void
    {
        Vista::configurar(dirname(__DIR__, 2) . '/templates');
        $_SESSION = [];
        $resumen = ['resultados' => 170, 'evidencias' => 8, 'revisiones' => 1, 'capturas' => 1];

        $html = Vista::render('results/borrar', ['analisis' => Filas::analisis(['proyecto_nombre' => '<script>x</script>']), 'resumen' => $resumen, 'enMarcha' => false, 'error' => null], false);
        self::assertStringContainsString('No se puede deshacer', $html);
        self::assertStringContainsString('/analisis/1/exportar/pdf', $html, 'Se ofrece exportar antes de borrar');
        self::assertStringContainsString('170 resultados, 8 evidencias, 1 revisión humana y 1 captura', preg_replace('/\s+/u', ' ', strip_tags($html)));
        self::assertStringContainsString('name="_metodo" value="DELETE"', $html);
        self::assertStringContainsString('name="_csrf"', $html);
        self::assertStringContainsString('name="confirmo"', $html);
        self::assertStringNotContainsString('<script>x', $html);

        $enMarcha = Vista::render('results/borrar', ['analisis' => Filas::analisis(['estado' => 'en_curso']), 'resumen' => $resumen, 'enMarcha' => true, 'error' => null], false);
        self::assertStringContainsString('todavía está en marcha', $enMarcha);
        self::assertStringNotContainsString('name="confirmo"', $enMarcha, 'Sin formulario si está en marcha');
    }

    // ---------- Ayudas ----------

    private function analisis(string $estado): int
    {
        $perfil = (int) $this->bd->query('SELECT id FROM perfiles_ejecucion ORDER BY id LIMIT 1')->fetchColumn();
        $this->bd->prepare("INSERT INTO analisis (proyecto_id, url_introducida, alcance, perfil_id, estado) VALUES (?, 'https://ejemplo.es/', 'url_individual', ?, ?)")
            ->execute([$this->proyectoId, $perfil, $estado]);
        $id = (int) $this->bd->lastInsertId();
        $this->bd->prepare("INSERT INTO fases_analisis (analisis_id, fase, estado) VALUES (?, 'validacion', 'terminada')")->execute([$id]);

        return $id;
    }

    /** @return array{int, string} id del análisis y ruta absoluta de la captura */
    private function analisisCompleto(string $estado): array
    {
        $id = $this->analisis($estado);
        $this->bd->prepare(
            "INSERT INTO resultados (analisis_id, id_comprobacion, id_criterio, bloque, estado, ejecucion, cobertura, tipo_conclusion, explicacion_sencilla, version_regla)
             VALUES (?, 'SEO-03.title-presente', 'SEO-03', 'seo', 'no_verificable', 'ejecutada', 'implementada', 'hecho', 'Prueba', 'v1')"
        )->execute([$id]);
        $resultado = (int) $this->bd->lastInsertId();
        $this->bd->prepare("INSERT INTO revisiones (resultado_id, revisor, motivo, decision, justificacion) VALUES (?, 'Test', 'Motivo', 'confirmar', 'Justificación')")->execute([$resultado]);
        $revision = (int) $this->bd->lastInsertId();

        $relativa = "analisis-{$id}/" . bin2hex(random_bytes(16)) . '.png';
        mkdir($this->capturas . "/analisis-{$id}", 0700, true);
        file_put_contents($this->capturas . '/' . $relativa, 'png');
        $this->bd->prepare("INSERT INTO evidencias (analisis_id, codigo, tipo, contenido) VALUES (?, 'E-001', 'texto', 'x')")->execute([$id]);
        $this->bd->prepare("INSERT INTO evidencias (analisis_id, codigo, tipo, ruta_fichero, tipo_mime, revision_id) VALUES (?, 'E-002', 'captura', ?, 'image/png', ?)")
            ->execute([$id, $relativa, $revision]);

        return [$id, $this->capturas . '/' . $relativa];
    }

    private function contar(string $sql): int
    {
        return (int) $this->bd->query($sql)->fetchColumn();
    }
}
