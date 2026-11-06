<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Results;

use PHPUnit\Framework\TestCase;
use ProconSEO\Engine\Catalogo\CatalogoJson;
use ProconSEO\Results\CompromisosKitDigital;
use ProconSEO\Results\Etiquetas;
use ProconSEO\Results\FiltroResultados;
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

    public function testFiltrosEscapadosYConservados(): void
    {
        $filtro = FiltroResultados::desdeConsulta(['q' => self::MALICIOSO, 'bloque' => 'seo', 'pendiente' => '1']);
        $html = $this->dashboard([Filas::resultado(['id_criterio' => 'SEO-03'])], filtro: $filtro);

        $this->assertSinHtmlInyectado($html);
        self::assertMatchesRegularExpression('/value="seo" selected/', $html);
        self::assertStringContainsString('name="pendiente" value="1" checked', $html);
        self::assertStringContainsString('Se muestran <strong>0 de 1</strong>', $html);
        self::assertStringContainsString('Quitar filtros', $html);
        self::assertStringContainsString('Ninguna incidencia coincide con los filtros', $html);
    }

    public function testLosRecuentosDelResumenEnlazanAlFiltro(): void
    {
        $html = $this->dashboard([Filas::resultado(['bloque' => 'acc', 'estado' => 'problema', 'gravedad' => 'alto'])]);

        self::assertStringContainsString('href="/analisis/1?bloque=acc&amp;estado=problema#resultados"', $html);
    }

    public function testPestanaKitDigitalSoloEnProyectosKitDigital(): void
    {
        self::assertStringContainsString('/analisis/1/kit-digital', $this->dashboard([Filas::resultado()], conKit: true));
        self::assertStringNotContainsString('/analisis/1/kit-digital', $this->dashboard([Filas::resultado()]));
    }

    public function testVistaKitDigital(): void
    {
        $catalogo = CatalogoJson::v1();
        $resultados = Informe::conTitulos([
            Filas::resultado(['id_comprobacion' => 'SEO-01.noindex-desarrollo', 'estado' => 'problema', 'gravedad' => 'critico', 'explicacion_sencilla' => self::MALICIOSO]),
            Filas::resultado(['id_comprobacion' => 'ACC-08.tamano-objetivo', 'bloque' => 'acc', 'estado' => 'no_verificable', 'cobertura' => 'no_implementada']),
        ], $catalogo);

        $html = Vista::render('results/kit_digital', [
            'analisis' => Filas::analisis(['proyecto_nombre' => self::MALICIOSO]),
            'compromisos' => CompromisosKitDigital::agrupar($resultados, $catalogo, 'web_kit_digital'),
            'hayResultados' => true,
        ], false);

        $this->assertSinHtmlInyectado($html);
        self::assertStringContainsString('1 compromiso con problemas críticos', $html);
        self::assertStringContainsString('Criterios nuevos de WCAG 2.2, fuera del compromiso 2.1', $html);
        self::assertStringContainsString('WCAG 2.5.8 · AA · nuevo en 2.2', $html);
        self::assertStringContainsString('Solo para proyectos «Tienda Kit Digital»', $html);
        self::assertStringContainsString('no se puede decir si se cumple', $html, 'Un compromiso sin resultados no se da por cumplido');
        self::assertStringContainsString('aria-current="page">Compromisos Kit Digital', $html);
        self::assertDoesNotMatchRegularExpression('/\d+\s*%|puntuaci|aprobado:/iu', strip_tags($html));
    }

    public function testVistaKitDigitalSinCatalogoNiResultados(): void
    {
        $sinCatalogo = Vista::render('results/kit_digital', ['analisis' => Filas::analisis(), 'compromisos' => null, 'hayResultados' => true], false);
        $sinResultados = Vista::render('results/kit_digital', ['analisis' => Filas::analisis(), 'compromisos' => [], 'hayResultados' => false], false);

        self::assertStringContainsString('No se ha podido cargar el catálogo', $sinCatalogo);
        self::assertStringContainsString('no se puede decir si la web cumple', $sinResultados);
    }

    private function dashboard(array $resultados, array $recursos = [], array $eventos = [], array $analisis = [], ?FiltroResultados $filtro = null, bool $conKit = false): string
    {
        return Vista::render('results/dashboard', [
            'analisis' => Filas::analisis($analisis),
            'informe' => new Informe($resultados, $recursos, [], null, $filtro),
            'eventos' => $eventos,
            'conKitDigital' => $conKit,
        ], false);
    }

    public function testCambiosDesdeElAnalisisAnterior(): void
    {
        $antes = [
            Filas::resultado(['id_comprobacion' => 'SEO-03.title-presente', 'estado' => 'problema', 'gravedad' => 'alto']),
            Filas::resultado(['id_comprobacion' => 'SEO-04.description', 'estado' => 'correcto']),
        ];
        $ahora = [
            Filas::resultado(['id_comprobacion' => 'SEO-03.title-presente', 'estado' => 'correcto', 'titulo' => self::MALICIOSO]),
            Filas::resultado(['id_comprobacion' => 'SEO-04.description', 'estado' => 'problema', 'gravedad' => 'medio', 'url_recurso' => 'https://ejemplo.es/' . self::MALICIOSO]),
        ];
        $html = Vista::render('results/dashboard', [
            'analisis' => Filas::analisis(['version_catalogo' => '1.2.0']),
            'informe' => new Informe($ahora, [], [], null),
            'eventos' => [],
            'conKitDigital' => false,
            'anterior' => $anterior = ['id' => 7, 'estado' => 'completado', 'creado_en' => '2026-10-01 10:00:00', 'terminado_en' => '2026-10-01 10:05:00', 'version_catalogo' => '1.1.0', 'url_normalizada' => 'https://ejemplo.es/', 'url_introducida' => 'https://ejemplo.es/', 'alcance' => 'url_individual'],
            'comparacion' => new \ProconSEO\Results\ComparacionAnalisis($antes, $ahora),
            'candidatos' => [$anterior, ['id' => 3, 'estado' => 'completado', 'creado_en' => '2026-08-01 10:00:00', 'terminado_en' => null, 'version_catalogo' => '1.0.0', 'url_normalizada' => 'https://ejemplo.es/' . self::MALICIOSO, 'url_introducida' => 'https://ejemplo.es/', 'alcance' => 'sitio_completo']],
        ], false);

        $this->assertSinHtmlInyectado($html);
        self::assertStringContainsString('Cambios desde un análisis anterior', $html);
        self::assertStringContainsString('<option value="7" selected>', $html, 'Se puede elegir con cuál comparar');
        self::assertStringContainsString('<option value="3"', $html);
        self::assertStringNotContainsString('otra dirección', $html, 'Es la misma dirección');
        self::assertStringContainsString('href="/analisis/7"', $html);
        self::assertStringContainsString('Arreglados (1)', $html);
        self::assertStringContainsString('Nuevos (1)', $html);
        self::assertStringContainsString('no significa que Google', $html, 'Avisa de que el buscador puede tardar');
        self::assertStringContainsString('versiones distintas del catálogo', $html);
        self::assertLessThan(strpos($html, 'id="t-bloqueos"'), strpos($html, 'id="t-cambios"'), 'Va antes del detalle');
        self::assertDoesNotMatchRegularExpression('/\d+\s*%/', strip_tags($html), 'Sin porcentajes');
    }

    public function testSiNadaSePudoComprobarNoDiceQueNoHayaCambiado(): void
    {
        $nv = [Filas::resultado(['estado' => 'no_verificable'])];
        $anterior = ['id' => 7, 'creado_en' => '2026-10-01 10:00:00', 'terminado_en' => null, 'version_catalogo' => null, 'url_normalizada' => 'https://otra.es/', 'url_introducida' => 'https://otra.es/', 'alcance' => 'url_individual'];
        $html = Vista::render('results/_cambios', [
            'analisis' => Filas::analisis(),
            'anterior' => $anterior,
            'comparacion' => new \ProconSEO\Results\ComparacionAnalisis($nv, $nv),
            'candidatos' => [$anterior],
        ], false);

        self::assertStringContainsString('No se puede comparar', $html);
        self::assertStringContainsString('otra dirección', $html, 'Avisa de que es otra dirección');
        self::assertStringNotContainsString('No ha cambiado nada', $html);
    }

    public function testSinAnalisisAnteriorNoHaySeccionDeCambios(): void
    {
        self::assertStringNotContainsString('t-cambios', $this->dashboard([Filas::resultado()]));
    }

    private function assertSinHtmlInyectado(string $html): void
    {
        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }
}
