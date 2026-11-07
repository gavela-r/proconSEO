<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Results;

use PDO;
use PHPUnit\Framework\TestCase;
use ProconSEO\Results\FiltroResultados;
use ProconSEO\Results\Informe;
use ProconSEO\Results\Prioridades;
use ProconSEO\Results\Seguimientos;
use ProconSEO\Shared\BaseDatos;
use ProconSEO\Shared\Config;
use ProconSEO\Shared\Error\ErrorDominio;

/**
 * Seguimiento de hallazgos por proyecto, contra la base de datos local. Crea un proyecto de prueba
 * y lo borra al terminar. Las pruebas sin base de datos (filtro y prioridades) se ejecutan siempre.
 */
final class SeguimientosTest extends TestCase
{
    private ?PDO $bd = null;
    private int $proyectoId = 0;
    private int $perfil = 0;

    private function conBaseDeDatos(): void
    {
        Config::cargar(dirname(__DIR__, 2));
        try {
            $this->bd = BaseDatos::conexion();
            $this->bd->query('SELECT 1 FROM seguimientos LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('Sin base de datos local (composer migrar).');
        }
        $this->bd->exec("INSERT INTO proyectos (nombre, url_principal, tipo, es_dato_prueba) VALUES ('[TEST] Seguimiento', 'https://seguimiento-prueba.es/', 'web_cliente', 1)");
        $this->proyectoId = (int) $this->bd->lastInsertId();
        $this->perfil = (int) $this->bd->query('SELECT id FROM perfiles_ejecucion ORDER BY id LIMIT 1')->fetchColumn();
    }

    protected function tearDown(): void
    {
        if ($this->bd !== null && $this->proyectoId > 0) {
            $this->bd->prepare('DELETE FROM proyectos WHERE id = ?')->execute([$this->proyectoId]);
        }
    }

    private function analisis(string $fecha, ?string $problemaEn = null): int
    {
        \assert($this->bd !== null);
        $this->bd->prepare("INSERT INTO analisis (proyecto_id, url_introducida, url_normalizada, alcance, perfil_id, estado, creado_en) VALUES (?, 'https://seguimiento-prueba.es/', 'https://seguimiento-prueba.es/', 'url_individual', ?, 'completado', ?)")
            ->execute([$this->proyectoId, $this->perfil, $fecha]);
        $id = (int) $this->bd->lastInsertId();
        if ($problemaEn !== null) {
            $this->bd->prepare("INSERT INTO resultados (analisis_id, id_comprobacion, id_criterio, bloque, estado, gravedad, ejecucion, cobertura, tipo_conclusion, explicacion_sencilla, version_regla)
                                VALUES (?, ?, 'ACC-01', 'acc', 'problema', 'alto', 'ejecutada', 'implementada', 'hecho', 'Prueba', 'v1')")->execute([$id, $problemaEn]);
        }

        return $id;
    }

    public function testNuncaSeEditaYElUltimoEsElActual(): void
    {
        $this->conBaseDeDatos();
        $s = new Seguimientos($this->bd);
        $a = $this->analisis('2099-01-01 10:00:00', 'ACC-01.alt-ausente');

        $s->registrar($this->proyectoId, 'ACC-01.alt-ausente', Seguimientos::EN_CURSO, 'Laura', null, $a);
        $s->registrar($this->proyectoId, 'ACC-01.alt-ausente', Seguimientos::ACEPTADO, 'Marta', 'Imágenes decorativas', $a);

        $actual = $s->actuales($this->proyectoId)['ACC-01.alt-ausente'];
        self::assertSame(Seguimientos::ACEPTADO, $actual['estado']);
        self::assertSame('Imágenes decorativas', $actual['motivo']);
        self::assertCount(2, $s->historial($this->proyectoId, 'ACC-01.alt-ausente'), 'Los dos cambios se conservan');
    }

    public function testAceptarExigeMotivoYNombre(): void
    {
        $this->conBaseDeDatos();
        $s = new Seguimientos($this->bd);

        foreach ([[Seguimientos::ACEPTADO, 'Laura', ''], [Seguimientos::EN_CURSO, '  ', null], ['borrado', 'Laura', null]] as [$estado, $autor, $motivo]) {
            try {
                $s->registrar($this->proyectoId, 'X', $estado, $autor, $motivo, null);
                self::fail("Debería rechazar {$estado}");
            } catch (ErrorDominio $e) {
                self::assertSame('seguimiento_no_valido', $e->codigo);
            }
        }
        self::assertSame([], $s->actuales($this->proyectoId));
    }

    public function testCorregidoSeComprueba(): void
    {
        $this->conBaseDeDatos();
        $s = new Seguimientos($this->bd);
        $primero = $this->analisis('2099-01-01 10:00:00', 'ACC-01.alt-ausente');
        $s->registrar($this->proyectoId, 'ACC-01.alt-ausente', Seguimientos::CORREGIDO, 'Laura', null, $primero);
        self::assertNull($s->actuales($this->proyectoId)['ACC-01.alt-ausente']['comprobacion'], 'Sin análisis posterior no se afirma nada');

        $this->analisis('2099-12-31 10:00:00', 'ACC-01.alt-ausente');
        self::assertSame('reaparece', $s->actuales($this->proyectoId)['ACC-01.alt-ausente']['comprobacion']);
        self::assertSame('Corregido, pero reaparece', Seguimientos::etiqueta($s->actuales($this->proyectoId)['ACC-01.alt-ausente']));
    }

    public function testCorregidoYYaNoAparece(): void
    {
        $this->conBaseDeDatos();
        $s = new Seguimientos($this->bd);
        $primero = $this->analisis('2099-01-01 10:00:00', 'ACC-01.alt-ausente');
        $s->registrar($this->proyectoId, 'ACC-01.alt-ausente', Seguimientos::CORREGIDO, 'Laura', null, $primero);
        $this->analisis('2099-12-31 10:00:00');

        self::assertSame('Comprobado', Seguimientos::etiqueta($s->actuales($this->proyectoId)['ACC-01.alt-ausente']));
    }

    public function testFiltroYPrioridadesSinBaseDeDatos(): void
    {
        $aceptado = Filas::resultado(['id_comprobacion' => 'A', 'titulo' => 'A', 'estado' => 'problema', 'gravedad' => 'critico', 'seguimiento' => 'aceptado']);
        $corregido = Filas::resultado(['id_comprobacion' => 'C', 'titulo' => 'C', 'estado' => 'problema', 'gravedad' => 'alto', 'seguimiento' => 'corregido']);
        $pendiente = Filas::resultado(['id_comprobacion' => 'P', 'titulo' => 'P', 'estado' => 'problema', 'gravedad' => 'medio']);
        $todos = [$aceptado, $corregido, $pendiente];

        $ids = static fn (array $q): array => array_column(FiltroResultados::desdeConsulta($q)->aplicar($todos), 'id');
        self::assertSame([$corregido['id'], $pendiente['id']], $ids(['seguimiento' => 'sin_aceptados']));
        self::assertSame([$aceptado['id']], $ids(['seguimiento' => 'aceptado']));
        self::assertSame([$pendiente['id']], $ids(['seguimiento' => 'pendiente']), 'Sin seguimiento guardado cuenta como pendiente');

        self::assertSame(['P'], array_column(Prioridades::desde(new Informe($todos, [], []))['empezar'], 'titulo'),
            'Lo aceptado o ya corregido no sale en «Por dónde empezar»');

        $enDosPaginas = Filas::resultado(['id_comprobacion' => 'P', 'titulo' => 'P', 'estado' => 'problema', 'gravedad' => 'medio', 'url_recurso' => 'https://ejemplo.es/otra']);
        self::assertEqualsCanonicalizing(['aceptado' => 1, 'corregido' => 1, 'pendiente' => 1],
            Prioridades::seguimiento(new Informe([...$todos, $enDosPaginas], [], [])), 'Se cuentan hallazgos, no resultados');
    }
}
