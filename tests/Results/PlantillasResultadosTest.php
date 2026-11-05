<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Results;

use PHPUnit\Framework\TestCase;
use ProconSEO\Results\Etiquetas;
use ProconSEO\Results\Informe;
use ProconSEO\Shared\Vista;

/**
 * Dashboard y detalle: el contenido de la web analizada se muestra como texto escapado (SEG-09),
 * el aviso de datos de prueba aparece cuando toca (RF-52) y nunca hay notas ni porcentajes.
 */
final class PlantillasResultadosTest extends TestCase
{
    private const MALICIOSO = '<script>alert("x")</script><img src=x onerror=alert(1)>';

    protected function setUp(): void
    {
        Vista::configurar(dirname(__DIR__, 2) . '/templates');
        $_SESSION = [];
    }

    public function testDashboardEscapaElContenidoDeLaWeb(): void
    {
        $causa = ['grupo_causa_id' => 3, 'causa_codigo' => 'C-001', 'causa_descripcion' => self::MALICIOSO];
        $resultados = [
            Filas::resultado($causa + ['estado' => 'problema', 'gravedad' => 'critico', 'explicacion_sencilla' => self::MALICIOSO, 'correccion' => self::MALICIOSO]),
            Filas::resultado($causa + ['estado' => 'problema', 'gravedad' => 'critico', 'bloque' => 'ia', 'consecuencia' => self::MALICIOSO]),
            Filas::resultado(['estado' => 'no_verificable', 'revision_pendiente' => true, 'motivo_revision' => self::MALICIOSO, 'url_recurso' => self::MALICIOSO]),
        ];
        $html = $this->dashboard($resultados, [Filas::recurso(['url' => 'https://ejemplo.es/' . self::MALICIOSO, 'resultado' => 'error_red', 'motivo' => self::MALICIOSO])], [
            ['fase' => 'renderizado', 'url_recurso' => null, 'regla' => 'SEG-03', 'condicion' => self::MALICIOSO, 'decision' => 'continuar', 'creado_en' => '2026-11-04 10:00:00'],
        ], ['objetivo' => self::MALICIOSO, 'motivo_cierre' => self::MALICIOSO, 'estado' => 'parcial']);

        $this->assertSinHtmlInyectado($html);
        self::assertStringContainsString('Causa C-001 · afecta a 2 bloques', $html);
        self::assertStringContainsString('Revisión humana pendiente', $html);
    }

    public function testDashboardSinNotasNiPorcentajes(): void
    {
        $html = $this->dashboard([Filas::resultado(['estado' => 'problema', 'gravedad' => 'alto']), Filas::resultado()]);

        // "No es una nota:" es la aclaración de la pantalla, no una nota.
        self::assertDoesNotMatchRegularExpression('/\d+\s*%|(?<!una )\bnota\b\s*:|puntuaci|\/\s*100\b/iu', strip_tags($html));
        self::assertStringContainsString('No es una nota', $html);
    }

    public function testElOrdenDeLasSeccionesEsElDeLaEspecificacion(): void
    {
        $html = $this->dashboard([Filas::resultado()]);

        $posiciones = array_map(static fn (string $id): int|false => strpos($html, 'id="' . $id . '"'), ['t-resumen', 't-bloqueos', 't-incidencias', 't-correctos', 't-noverif', 't-limites']);
        self::assertNotContains(false, $posiciones);
        $ordenadas = $posiciones;
        sort($ordenadas);
        self::assertSame($ordenadas, $posiciones);
    }

    public function testAvisoDeDatosDePrueba(): void
    {
        self::assertStringContainsString('DATOS DE PRUEBA', $this->dashboard([Filas::resultado()], analisis: ['proyecto_es_dato_prueba' => 1]));
        self::assertStringNotContainsString('DATOS DE PRUEBA', $this->dashboard([Filas::resultado()]));
    }

    public function testSinResultadosNoSeConcluyeNada(): void
    {
        $html = $this->dashboard([], analisis: ['estado' => 'cancelado', 'motivo_cierre' => 'Cancelado por el usuario.']);

        self::assertStringContainsString('No se puede decir nada de la web', $html);
        self::assertStringNotContainsString('no hay ningún bloqueo', $html);
        self::assertStringNotContainsString('id="t-resumen"', $html);
    }

    public function testDetalleEscapaEvidenciasYRevisiones(): void
    {
        $resultado = Filas::resultado([
            'estado' => 'decision_revisar', 'estado_vigente' => 'correcto', 'revisado' => true,
            'elemento' => self::MALICIOSO, 'valor_observado' => self::MALICIOSO, 'consecuencia' => self::MALICIOSO,
            'correccion_cms' => self::MALICIOSO, 'limitacion' => self::MALICIOSO, 'fuente' => self::MALICIOSO,
        ]);
        $html = Vista::render('results/detalle', [
            'resultado' => $resultado,
            'ficha' => null,
            'analisis' => Filas::analisis(['proyecto_nombre' => self::MALICIOSO, 'es_dato_prueba' => 1]),
            'evidencias' => [['codigo' => 'E-001', 'tipo' => 'fragmento_html', 'contenido' => self::MALICIOSO, 'tipo_mime' => null, 'url_recurso' => self::MALICIOSO, 'truncado' => 0, 'capturado_en' => '2026-11-04 10:00:00']],
            'revisiones' => [[
                'id' => 1, 'revisor' => self::MALICIOSO, 'motivo' => self::MALICIOSO, 'decision' => 'cambiar_estado', 'nuevo_estado' => 'correcto',
                'justificacion' => self::MALICIOSO, 'creado_en' => '2026-11-04 10:00:00',
                'evidencias' => [['codigo' => 'E-002', 'tipo' => 'texto', 'contenido' => self::MALICIOSO, 'tipo_mime' => null, 'capturado_en' => '2026-11-04 10:00:00']],
            ]],
        ], false);

        $this->assertSinHtmlInyectado($html);
        self::assertStringContainsString('DATOS DE PRUEBA', $html);
        self::assertStringContainsString('El resultado original se conserva sin cambios', $html);
        self::assertStringContainsString('Cambiar estado', $html);
    }

    public function testDetalleMarcaLosCriteriosNuevosDeWcag22(): void
    {
        $html = Vista::render('results/detalle', [
            'resultado' => Filas::resultado(['bloque' => 'acc', 'estado' => 'problema', 'gravedad' => 'medio', 'wcag_criterio' => '2.5.8', 'wcag_nombre' => 'Tamaño del objetivo (mínimo)', 'wcag_nivel' => 'AA', 'wcag_version' => '2.2']),
            'ficha' => null, 'analisis' => null, 'evidencias' => [], 'revisiones' => [],
        ], false);

        self::assertStringContainsString('WCAG 2.5.8 · AA · nuevo en 2.2', $html);
        self::assertStringContainsString('no forma parte del compromiso WCAG 2.1 AA', $html);
    }

    public function testEtiquetas(): void
    {
        $problema = Filas::resultado(['estado' => 'problema', 'gravedad' => 'critico']);
        self::assertSame('Gravedad: crítico', Etiquetas::gravedad($problema));
        self::assertNull(Etiquetas::gravedad(['estado_vigente' => 'correcto'] + $problema), 'Sin gravedad si una revisión cambió el estado');
        self::assertSame('WCAG 1.1.1 · A · en 2.1', Etiquetas::wcag(['wcag_criterio' => '1.1.1', 'wcag_nivel' => 'A', 'wcag_version' => '2.1']));
        self::assertTrue(Etiquetas::esNuevo22(['wcag_criterio' => '3.3.8', 'wcag_version' => null]), 'Se reconoce aunque falte la versión');
        self::assertNull(Etiquetas::wcag(['wcag_criterio' => null]));
        self::assertSame('ejemplo.es, www.ejemplo.es', Etiquetas::lista('["ejemplo.es","www.ejemplo.es"]'));
        self::assertSame('47 KB', Etiquetas::bytes(48211));
    }

    private function dashboard(array $resultados, array $recursos = [], array $eventos = [], array $analisis = []): string
    {
        return Vista::render('results/dashboard', [
            'analisis' => Filas::analisis($analisis),
            'informe' => new Informe($resultados, $recursos, []),
            'eventos' => $eventos,
        ], false);
    }

    private function assertSinHtmlInyectado(string $html): void
    {
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }
}
