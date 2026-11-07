<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Run;

use PDO;
use PHPUnit\Framework\TestCase;
use ProconSEO\Run\RepositorioAnalisis;
use ProconSEO\Shared\BaseDatos;
use ProconSEO\Shared\Config;

/**
 * Análisis que se quedaron «en curso» o «cancelando» porque el worker se detuvo a mitad.
 * Contra la base de datos local; se salta si no la hay.
 */
final class AnalisisHuerfanosTest extends TestCase
{
    private PDO $bd;
    private int $proyectoId;

    protected function setUp(): void
    {
        Config::cargar(dirname(__DIR__, 2));
        try {
            $this->bd = BaseDatos::conexion();
            $this->bd->query('SELECT 1 FROM analisis LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('Sin base de datos local (composer migrar).');
        }
        $this->bd->exec("INSERT INTO proyectos (nombre, url_principal, tipo, es_dato_prueba) VALUES ('[TEST] Huérfanos', 'https://huerfanos-prueba.es/', 'web_cliente', 1)");
        $this->proyectoId = (int) $this->bd->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->proyectoId)) {
            $this->bd->prepare('DELETE FROM proyectos WHERE id = ?')->execute([$this->proyectoId]);
        }
    }

    private function analisis(string $estado, string $hace): int
    {
        $perfil = (int) $this->bd->query('SELECT id FROM perfiles_ejecucion ORDER BY id LIMIT 1')->fetchColumn();
        $this->bd->prepare("INSERT INTO analisis (proyecto_id, url_introducida, alcance, perfil_id, estado, iniciado_en) VALUES (?, 'https://huerfanos-prueba.es/', 'url_individual', ?, ?, NOW(3) - INTERVAL {$hace})")
            ->execute([$this->proyectoId, $perfil, $estado]);
        $id = (int) $this->bd->lastInsertId();
        $this->bd->prepare("INSERT INTO fases_analisis (analisis_id, fase, estado) VALUES (?, 'comprobaciones', 'en_curso'), (?, 'cierre', 'pendiente')")->execute([$id, $id]);

        return $id;
    }

    private function fila(int $id): array
    {
        $c = $this->bd->prepare('SELECT estado, motivo_cierre, terminado_en FROM analisis WHERE id = ?');
        $c->execute([$id]);

        return $c->fetch();
    }

    public function testCierraLosViejosYDejaLosQueSiguenVivos(): void
    {
        $colgado = $this->analisis('en_curso', '3 HOUR');
        $cancelando = $this->analisis('cancelando', '2 DAY');
        $reciente = $this->analisis('en_curso', '5 MINUTE');

        $cerrados = (new RepositorioAnalisis($this->bd))->cerrarHuerfanos(3600);

        self::assertEqualsCanonicalizing([$colgado, $cancelando], array_values(array_intersect($cerrados, [$colgado, $cancelando, $reciente])));
        self::assertSame('detenido_error', $this->fila($colgado)['estado']);
        self::assertStringContainsString('el worker se detuvo', (string) $this->fila($colgado)['motivo_cierre']);
        self::assertNotNull($this->fila($colgado)['terminado_en']);
        self::assertSame('cancelado', $this->fila($cancelando)['estado'], 'Si se había pedido cancelar, queda cancelado');
        self::assertSame('en_curso', $this->fila($reciente)['estado'], 'Uno reciente puede seguir trabajando: no se toca');
        $pendientes = (int) $this->bd->query("SELECT COUNT(*) FROM fases_analisis WHERE analisis_id = {$colgado} AND estado IN ('pendiente', 'en_curso')")->fetchColumn();
        self::assertSame(0, $pendientes, 'Sus fases quedan cerradas');
    }
}
