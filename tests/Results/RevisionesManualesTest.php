<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Results;

use PDO;
use PHPUnit\Framework\TestCase;
use ProconSEO\Results\GuiaWcag;
use ProconSEO\Results\RevisionesManuales;
use ProconSEO\Results\VistaWcag;
use ProconSEO\Shared\BaseDatos;
use ProconSEO\Shared\Config;
use ProconSEO\Shared\Error\ErrorDominio;
use ProconSEO\Shared\Vista;

/**
 * Revisiones manuales guiadas de los criterios WCAG. Las de base de datos crean un proyecto de
 * prueba y lo borran al terminar; las demás se ejecutan siempre.
 */
final class RevisionesManualesTest extends TestCase
{
    private ?PDO $bd = null;
    private int $proyectoId = 0;

    protected function tearDown(): void
    {
        if ($this->bd !== null && $this->proyectoId > 0) {
            $this->bd->prepare('DELETE FROM proyectos WHERE id = ?')->execute([$this->proyectoId]);
        }
    }

    private function analisis(): int
    {
        Config::cargar(dirname(__DIR__, 2));
        try {
            $this->bd = BaseDatos::conexion();
            $this->bd->query('SELECT 1 FROM revisiones_manuales LIMIT 1');
        } catch (\Throwable) {
            self::markTestSkipped('Sin base de datos local (composer migrar).');
        }
        $this->bd->exec("INSERT INTO proyectos (nombre, url_principal, tipo, es_dato_prueba) VALUES ('[TEST] Revisión manual', 'https://manual-prueba.es/', 'web_cliente', 1)");
        $this->proyectoId = (int) $this->bd->lastInsertId();
        $perfil = (int) $this->bd->query('SELECT id FROM perfiles_ejecucion ORDER BY id LIMIT 1')->fetchColumn();
        $this->bd->prepare("INSERT INTO analisis (proyecto_id, url_introducida, url_normalizada, alcance, perfil_id, estado) VALUES (?, 'https://manual-prueba.es/', 'https://manual-prueba.es/', 'url_individual', ?, 'completado')")
            ->execute([$this->proyectoId, $perfil]);

        return (int) $this->bd->lastInsertId();
    }

    public function testCadaCriterioTieneSuGuia(): void
    {
        $criterios = array_column(VistaWcag::CRITERIOS, 0);
        self::assertSame($criterios, GuiaWcag::criterios(), 'Una guía por cada criterio A y AA, en el mismo orden');
        foreach ($criterios as $c) {
            $pasos = GuiaWcag::pasos($c);
            self::assertNotEmpty($pasos, $c);
            self::assertMatchesRegularExpression('/\bBien si\b|No aplica/u', implode(' ', $pasos), "{$c}: la guía dice qué tiene que pasar para que esté bien");
        }
    }

    public function testLaRevisionManualCompletaPeroNoTapaUnProblema(): void
    {
        $r = static fn (string $criterio, string $estado): array => Filas::resultado(['wcag_criterio' => $criterio, 'estado' => $estado, 'bloque' => 'acc']);
        $manual = static fn (string $resultado): array => ['resultado' => $resultado, 'revisor' => 'Ana', 'notas' => 'Revisado', 'paginas' => null, 'creado_en' => '2026-11-07 10:00:00'];
        $wcag = new VistaWcag(
            [$r('1.1.1', 'problema'), $r('2.1.1', 'no_verificable'), $r('3.1.2', 'no_verificable')],
            ['1.1.1' => $manual('correcto'), '2.1.1' => $manual('correcto'), '3.1.2' => $manual('problema'), '1.2.4' => $manual('no_aplicable')],
        );
        $por = array_column($wcag->criterios, null, 'numero');

        self::assertSame('problemas', $por['1.1.1']['situacion'], 'Un «correcto» a mano no quita un problema automático');
        self::assertSame('sin_problemas', $por['2.1.1']['situacion']);
        self::assertSame('revisar', $por['2.1.1']['situacion_automatica']);
        self::assertSame('problemas', $por['3.1.2']['situacion']);
        self::assertSame('no_aplica', $por['1.2.4']['situacion'], 'Un criterio sin comprobaciones automáticas se puede resolver a mano');
        self::assertSame('Ana', $por['2.1.1']['manual']['revisor']);
    }

    public function testGuardaSoloLoValidoYLaUltimaEsLaVigente(): void
    {
        $id = $this->analisis();
        \assert($this->bd !== null);
        $manuales = new RevisionesManuales($this->bd);

        foreach ([
            ['9.9.9', 'correcto', 'Ana', 'x'],
            ['2.1.1', 'cumple', 'Ana', 'x'],
            ['2.1.1', 'correcto', '', 'x'],
            ['2.1.1', 'correcto', 'Ana', '   '],
        ] as [$criterio, $resultado, $revisor, $notas]) {
            try {
                $manuales->registrar($id, $criterio, $resultado, $revisor, '', $notas);
                self::fail("Debería rechazar {$criterio} · {$resultado} · «{$revisor}» · «{$notas}»");
            } catch (ErrorDominio $e) {
                self::assertSame(422, $e->estadoHttp);
            }
        }

        $manuales->registrar($id, '2.1.1', 'problema', 'Ana', 'portada', 'El menú no abre con Intro');
        $manuales->registrar($id, '2.1.1', 'correcto', 'Luis', '', 'Ya abre con Intro y espacio');

        self::assertSame('correcto', $manuales->vigentes($id)['2.1.1']['resultado'], 'La última es la vigente');
        self::assertCount(2, $manuales->historial($id, '2.1.1'), 'No se borra ni se edita nada');
        self::assertSame('Luis', $manuales->historial($id, '2.1.1')[0]['revisor'], 'La más reciente primero');
        self::assertNull($manuales->historial($id, '2.1.1')[0]['paginas']);
    }

    public function testLaPaginaDelCriterioEscapaLoQueEscribe(): void
    {
        Vista::configurar(dirname(__DIR__, 2) . '/templates');
        $_SESSION = [];
        $wcag = new VistaWcag([], ['2.1.1' => ['resultado' => 'correcto', 'revisor' => '<script>alert(1)</script>', 'notas' => '<img src=x onerror=alert(1)>', 'paginas' => null, 'creado_en' => '2026-11-07 10:00:00']]);
        $criterio = array_values(array_filter($wcag->criterios, static fn (array $c): bool => $c['numero'] === '2.1.1'))[0];
        $html = Vista::render('results/wcag_criterio', [
            'analisis' => Filas::analisis(),
            'criterio' => $criterio,
            'pasos' => GuiaWcag::pasos('2.1.1'),
            'historial' => [$criterio['manual']],
            'error' => null,
            'valores' => [],
            'guardado' => false,
        ], false);

        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('name="_csrf"', $html);
        self::assertStringContainsString('name="criterio" value="2.1.1"', $html);
        self::assertStringContainsString('revisado a mano', $html);
    }
}
