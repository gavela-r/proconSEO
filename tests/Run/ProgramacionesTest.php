<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Run;

use PDO;
use PHPUnit\Framework\TestCase;
use ProconSEO\Engine\Catalogo\CatalogoJson;
use ProconSEO\Fetch\ValidadorDestino;
use ProconSEO\Fetch\ValidadorUrl;
use ProconSEO\Run\Avisos;
use ProconSEO\Run\Interruptor;
use ProconSEO\Run\LanzadorAnalisis;
use ProconSEO\Run\Programaciones;
use ProconSEO\Run\RegistroAuditoria;
use ProconSEO\Run\RepositorioAnalisis;
use ProconSEO\Shared\BaseDatos;
use ProconSEO\Shared\Config;
use ProconSEO\Shared\Error\ErrorDominio;
use ProconSEO\Tests\Fetch\DnsFalso;

/**
 * Análisis programados y avisos, contra la base de datos local y sin red.
 * Crea un proyecto de prueba y lo borra al terminar. Se salta si no hay base de datos.
 */
final class ProgramacionesTest extends TestCase
{
    private const URL = 'https://programados-prueba.es/';

    private PDO $bd;
    private int $proyectoId;
    private Programaciones $programaciones;
    private Interruptor $interruptor;

    protected function setUp(): void
    {
        Config::cargar(dirname(__DIR__, 2));
        try {
            $this->bd = BaseDatos::conexion();
            $this->bd->query('SELECT 1 FROM programaciones LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('Sin base de datos local (composer migrar).');
        }
        $this->bd->exec("INSERT INTO proyectos (nombre, url_principal, tipo, es_dato_prueba) VALUES ('[TEST] Programados', 'https://programados-prueba.es/', 'web_corporativa', 1)");
        $this->proyectoId = (int) $this->bd->lastInsertId();

        $auditoria = new RegistroAuditoria($this->bd);
        $this->interruptor = new Interruptor($this->bd, $auditoria);
        $validador = new ValidadorDestino(new ValidadorUrl(), new DnsFalso(['programados-prueba.es' => ['93.184.216.50']]));
        $lanzador = new LanzadorAnalisis(new RepositorioAnalisis($this->bd), $validador, CatalogoJson::v1(), $this->interruptor, $auditoria);
        $this->programaciones = new Programaciones($this->bd, $lanzador);
    }

    protected function tearDown(): void
    {
        if (isset($this->proyectoId)) {
            $this->bd->prepare('DELETE FROM proyectos WHERE id = ?')->execute([$this->proyectoId]);
            $this->bd->prepare('DELETE FROM registro_auditoria WHERE proyecto_id = ?')->execute([$this->proyectoId]);
        }
    }

    private function valor(string $sql, array $parametros = []): mixed
    {
        $consulta = $this->bd->prepare($sql);
        $consulta->execute($parametros);

        return $consulta->fetchColumn();
    }

    /** Las programaciones de este test que el worker lanzaría ahora (puede haber otras en la base de datos). */
    private function lanzarLasMias(): array
    {
        $this->bd->prepare('UPDATE programaciones SET activa = 0 WHERE proyecto_id <> ? AND activa = 1 AND siguiente_en <= NOW(3)')->execute([$this->proyectoId]);

        return $this->programaciones->lanzarPendientes();
    }

    public function testValidaLoQueSeProgramar(): void
    {
        foreach ([[self::URL, 'url_individual', 3], [self::URL, 'otro', 7], ['ftp://programados-prueba.es/', 'url_individual', 7]] as [$url, $alcance, $dias]) {
            try {
                $this->programaciones->crear($this->proyectoId, $url, $alcance, $dias);
                self::fail("Debería rechazar {$url} · {$alcance} · {$dias}");
            } catch (ErrorDominio $e) {
                self::assertSame(422, $e->estadoHttp);
            }
        }
    }

    public function testLanzaLaQueTocaUnaSolaVezYApuntaLaSiguiente(): void
    {
        $programacion = $this->programaciones->crear($this->proyectoId, self::URL, 'url_individual', 7);

        $lanzados = $this->lanzarLasMias();

        self::assertCount(1, $lanzados);
        self::assertSame($programacion, (int) $this->valor('SELECT programacion_id FROM analisis WHERE id = ?', [$lanzados[0]]), 'El análisis sabe que es programado');
        self::assertStringContainsString('cada semana', (string) $this->valor('SELECT objetivo FROM analisis WHERE id = ?', [$lanzados[0]]));
        self::assertSame(7, (int) $this->valor('SELECT DATEDIFF(siguiente_en, NOW()) FROM programaciones WHERE id = ?', [$programacion]));
        self::assertSame([], $this->lanzarLasMias(), 'Hasta dentro de 7 días no vuelve a tocar');
    }

    public function testSiElAnteriorSigueEnMarchaSeAplaza(): void
    {
        $programacion = $this->programaciones->crear($this->proyectoId, self::URL, 'url_individual', 1);
        $this->lanzarLasMias();
        $this->bd->prepare('UPDATE programaciones SET siguiente_en = NOW(3) - INTERVAL 1 MINUTE WHERE id = ?')->execute([$programacion]);

        self::assertSame([], $this->lanzarLasMias(), 'El primero sigue en cola');
        self::assertStringContainsString('todavía no ha terminado', (string) $this->valor('SELECT ultimo_error FROM programaciones WHERE id = ?', [$programacion]));
    }

    public function testConLaHerramientaDetenidaSeReintentaEnUnaHora(): void
    {
        $programacion = $this->programaciones->crear($this->proyectoId, self::URL, 'url_individual', 7);
        $this->interruptor->activar('Prueba de análisis programados', 'test');
        try {
            self::assertSame([], $this->lanzarLasMias());
        } finally {
            $this->interruptor->desactivar('test');
        }

        self::assertNotNull($this->valor('SELECT ultimo_error FROM programaciones WHERE id = ?', [$programacion]));
        $minutos = (int) $this->valor('SELECT TIMESTAMPDIFF(MINUTE, NOW(), siguiente_en) FROM programaciones WHERE id = ?', [$programacion]);
        self::assertTrue($minutos >= 58 && $minutos <= 60, 'Dentro de una hora, no de una semana');
    }

    public function testPausadaNoSeLanza(): void
    {
        $programacion = $this->programaciones->crear($this->proyectoId, self::URL, 'url_individual', 7);
        $this->programaciones->activar($programacion, false);

        self::assertSame([], $this->lanzarLasMias());
    }

    public function testAvisoSiLaWebNoRespondeYSoloEnLosProgramados(): void
    {
        $programado = $this->analisis(programado: true, estado: 'parcial');
        $this->bd->prepare("INSERT INTO recursos (analisis_id, url, estado_http, resultado, motivo) VALUES (?, ?, 503, 'error_http', 'página analizada')")->execute([$programado, self::URL]);
        $manual = $this->analisis(programado: false, estado: 'parcial');
        $this->bd->prepare("INSERT INTO recursos (analisis_id, url, estado_http, resultado, motivo) VALUES (?, ?, 503, 'error_http', 'página analizada')")->execute([$manual, self::URL]);
        $avisos = new Avisos($this->bd);

        self::assertCount(1, $avisos->trasAnalisis($programado));
        self::assertSame([], $avisos->trasAnalisis($manual), 'Lo lanzado a mano ya lo está mirando alguien');
        self::assertSame([], $avisos->trasAnalisis($programado), 'No se repite el aviso');
        self::assertStringContainsString('HTTP 503', (string) $this->valor('SELECT detalle FROM avisos WHERE analisis_id = ?', [$programado]));
    }

    public function testAvisoSiAparecenProblemasGraves(): void
    {
        $anterior = $this->analisis(programado: true);
        $this->resultado($anterior, 'SEO-01.noindex-desarrollo', 'correcto');
        $this->resultado($anterior, 'SEO-03.title-presente', 'correcto');
        $actual = $this->analisis(programado: true);
        $this->resultado($actual, 'SEO-01.noindex-desarrollo', 'problema', 'critico');
        $this->resultado($actual, 'SEO-03.title-presente', 'problema', 'medio');
        $avisos = new Avisos($this->bd, CatalogoJson::v1());

        $creados = $avisos->trasAnalisis($actual);

        self::assertCount(1, $creados);
        $aviso = $this->bd->query('SELECT * FROM avisos WHERE id = ' . $creados[0])->fetch();
        self::assertSame('1 problema grave nuevo en ' . self::URL, $aviso['titulo'], 'El de gravedad media no avisa');
        self::assertSame($anterior, (int) $aviso['analisis_anterior_id']);
        self::assertStringContainsString('(critico)', (string) $aviso['detalle']);
        self::assertGreaterThan(0, $avisos->sinLeer());

        $avisos->marcarLeido($creados[0]);
        self::assertNotNull($this->valor('SELECT leido_en FROM avisos WHERE id = ?', [$creados[0]]));
    }

    public function testSinCambiosGravesNoHayAviso(): void
    {
        $anterior = $this->analisis(programado: true);
        $this->resultado($anterior, 'SEO-01.noindex-desarrollo', 'problema', 'critico');
        $actual = $this->analisis(programado: true);
        $this->resultado($actual, 'SEO-01.noindex-desarrollo', 'problema', 'critico');

        self::assertSame([], (new Avisos($this->bd))->trasAnalisis($actual), 'Ya estaba antes: no es nuevo');
    }

    private function analisis(bool $programado, string $estado = 'completado'): int
    {
        $programacion = null;
        if ($programado) {
            $this->bd->prepare("INSERT INTO programaciones (proyecto_id, url, alcance, cada_dias, siguiente_en, activa) VALUES (?, ?, 'url_individual', 7, NOW(3) + INTERVAL 7 DAY, 0)")
                ->execute([$this->proyectoId, self::URL]);
            $programacion = (int) $this->bd->lastInsertId();
        }
        $perfil = (int) $this->bd->query('SELECT id FROM perfiles_ejecucion ORDER BY id LIMIT 1')->fetchColumn();
        $this->bd->prepare('INSERT INTO analisis (proyecto_id, programacion_id, url_introducida, url_normalizada, alcance, perfil_id, estado) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$this->proyectoId, $programacion, self::URL, self::URL, 'url_individual', $perfil, $estado]);

        return (int) $this->bd->lastInsertId();
    }

    private function resultado(int $analisis, string $id, string $estado, ?string $gravedad = null): void
    {
        $this->bd->prepare(
            "INSERT INTO resultados (analisis_id, id_comprobacion, id_criterio, bloque, estado, ejecucion, cobertura, tipo_conclusion, url_recurso, explicacion_sencilla, gravedad, version_regla)
             VALUES (?, ?, ?, 'seo', ?, 'ejecutada', 'implementada', 'hecho', ?, 'Prueba', ?, 'v1')"
        )->execute([$analisis, $id, explode('.', $id)[0], $estado, self::URL, $gravedad]);
    }
}
