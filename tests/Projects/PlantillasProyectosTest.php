<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Projects;

use PHPUnit\Framework\TestCase;
use ProconSEO\Projects\Formato;
use ProconSEO\Shared\Enum\EstadoProyecto;
use ProconSEO\Shared\Enum\Sector;
use ProconSEO\Shared\Enum\TipoProyecto;
use ProconSEO\Shared\Vista;

/**
 * Pantallas de proyectos: todo dato se muestra escapado (SEG-09), el aviso de datos de prueba
 * aparece cuando toca (RF-52) y los recuentos nunca son notas ni porcentajes.
 */
final class PlantillasProyectosTest extends TestCase
{
    private const MALICIOSO = '<script>alert("x")</script><img src=x onerror=alert(1)>';

    protected function setUp(): void
    {
        Vista::configurar(dirname(__DIR__, 2) . '/templates');
        $_SESSION = [];
    }

    public function testListaEscapaLosDatos(): void
    {
        $html = Vista::render('projects/lista', [
            'proyectos' => [$this->proyecto(['es_dato_prueba' => 1])],
            'limite' => 200,
            'filtros' => ['q' => self::MALICIOSO, 'tipo' => '', 'estado' => ''],
            'tipos' => TipoProyecto::opciones(),
            'estados' => EstadoProyecto::opciones(),
        ], false);

        $this->assertSinHtmlInyectado($html);
        self::assertStringContainsString('Datos de prueba', $html);
        self::assertStringContainsString('Todavía sin análisis', $html);
    }

    public function testListaVaciaConFiltros(): void
    {
        $html = Vista::render('projects/lista', [
            'proyectos' => [],
            'limite' => 200,
            'filtros' => ['q' => 'nada', 'tipo' => '', 'estado' => ''],
            'tipos' => TipoProyecto::opciones(),
            'estados' => EstadoProyecto::opciones(),
        ], false);

        self::assertStringContainsString('Ningún proyecto coincide', $html);
        self::assertStringContainsString('Quitar filtros', $html);
    }

    public function testFichaEscapaYMuestraElAvisoDePrueba(): void
    {
        $html = Vista::render('projects/ficha', [
            'proyecto' => $this->proyecto(['es_dato_prueba' => 1]),
            'historial' => [[
                'id' => 7,
                'url_introducida' => 'https://ejemplo.es/' . self::MALICIOSO,
                'url_final' => null,
                'alcance' => 'url_individual',
                'estado' => 'completado',
                'fase_actual' => 'cierre',
                'motivo_cierre' => self::MALICIOSO,
                'es_dato_prueba' => 1,
                'creado_en' => '2026-11-04 09:42:00.000',
                'recuento' => ['problema' => 3, 'correcto' => 1],
                'pendientes' => 1,
            ]],
        ], false);

        $this->assertSinHtmlInyectado($html);
        self::assertStringContainsString('DATOS DE PRUEBA', $html);
        self::assertStringContainsString('<strong>3</strong> Problema detectado', $html);
        self::assertStringContainsString('<strong>1</strong> Correcto', $html);
        self::assertStringContainsString('aria-hidden="true"', $html, 'El icono del estado es decorativo; el texto va al lado');
        self::assertStringContainsString('1 revisión pendiente', $html);
        self::assertStringContainsString('4 nov 2026, 10:42', $html, 'La fecha UTC se muestra en hora de España');
        self::assertDoesNotMatchRegularExpression('/\d+\s*%|\bnota\b\s*:|puntuaci/iu', strip_tags($html), 'Sin notas ni porcentajes');
    }

    public function testFichaSinDatosDePruebaNoMuestraElAviso(): void
    {
        $html = Vista::render('projects/ficha', ['proyecto' => $this->proyecto(), 'historial' => []], false);

        self::assertStringNotContainsString('DATOS DE PRUEBA', $html);
        self::assertStringContainsString('todavía no tiene análisis', $html);
    }

    public function testFormularioEscapaValoresYErroresYLlevaCsrf(): void
    {
        $html = Vista::render('projects/formulario', [
            'titulo' => 'Editar proyecto',
            'accion' => '/proyectos/5',
            'id' => 5,
            'valores' => ['nombre' => self::MALICIOSO, 'descripcion' => self::MALICIOSO, 'tipo' => 'web_cliente', 'sector' => 'salud'],
            'errores' => ['url_principal' => 'Escribe una dirección completa'],
            'esPrueba' => false,
            'tipos' => TipoProyecto::opciones(),
            'sectores' => Sector::opciones(),
            'estados' => EstadoProyecto::opciones(),
        ], false);

        $this->assertSinHtmlInyectado($html);
        self::assertStringContainsString('name="_csrf"', $html);
        self::assertStringContainsString('name="_metodo" value="PUT"', $html);
        self::assertStringContainsString('aria-invalid="true"', $html);
        self::assertStringContainsString('id="error-url_principal"', $html);
        self::assertMatchesRegularExpression('/value="web_cliente" checked/', $html);
        self::assertMatchesRegularExpression('/value="salud" selected/', $html);
    }

    public function testRecuentoSinNotas(): void
    {
        self::assertSame('1 problema · 2 mejorables · 1 decisión a revisar', Formato::recuento(['decision_revisar' => 1, 'mejorable' => 2, 'problema' => 1, 'correcto' => 0]));
        self::assertSame('', Formato::recuento([]));
    }

    public function testFechaVaciaONoValida(): void
    {
        self::assertSame('', Formato::fecha(null));
        self::assertSame('no es fecha', Formato::fecha('no es fecha'));
        self::assertSame('1 jul 2026, 14:00', Formato::fecha('2026-07-01 12:00:00'), 'En verano, UTC+2');
    }

    private function assertSinHtmlInyectado(string $html): void
    {
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    private function proyecto(array $campos = []): array
    {
        return $campos + [
            'id' => 5,
            'nombre' => self::MALICIOSO,
            'cliente' => self::MALICIOSO,
            'url_principal' => 'https://ejemplo.es/?q="><script>',
            'descripcion' => self::MALICIOSO,
            'estado' => 'activo',
            'tipo' => 'web_cliente',
            'sector' => 'salud',
            'es_dato_prueba' => 0,
            'creado_en' => '2026-11-04 09:00:00',
            'actualizado_en' => '2026-11-04 09:00:00',
            'ultimo_id' => null,
            'ultimo_estado' => null,
            'ultimo_creado_en' => null,
        ];
    }
}
