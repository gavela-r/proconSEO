<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Results;

use PHPUnit\Framework\TestCase;
use ProconSEO\Engine\Catalogo\CatalogoJson;
use ProconSEO\Results\CompromisosKitDigital;
use ProconSEO\Results\Informe;

/**
 * Vista "Compromisos Kit Digital" (especificación 6.7, RF-07 y prueba de aceptación de WCAG 2.2),
 * con el catálogo real del repositorio.
 */
final class CompromisosKitDigitalTest extends TestCase
{
    private CatalogoJson $catalogo;

    protected function setUp(): void
    {
        $this->catalogo = CatalogoJson::v1();
    }

    public function testConoceTodosLosCompromisosDelCatalogo(): void
    {
        $meta = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/catalog/v1/catalogo.json'), true);

        self::assertSame(array_keys($meta['valores']['kit_digital']), array_keys(CompromisosKitDigital::COMPROMISOS));
        foreach ($this->catalogo->todas() as $ficha) {
            foreach ($ficha->extra['kit_digital'] as $codigo) {
                self::assertArrayHasKey($codigo, CompromisosKitDigital::COMPROMISOS, "{$ficha->id} usa un compromiso desconocido");
            }
        }
    }

    public function testSoloEnProyectosKitDigital(): void
    {
        self::assertTrue(CompromisosKitDigital::aplica('web_kit_digital'));
        self::assertTrue(CompromisosKitDigital::aplica('tienda_kit_digital'));
        self::assertFalse(CompromisosKitDigital::aplica('web_cliente'));
        self::assertFalse(CompromisosKitDigital::aplica(null));
    }

    public function testAgrupaSegunElCatalogoYOrdenaPorPrioridad(): void
    {
        $correcto = Filas::resultado(['id_comprobacion' => 'SEO-01.indexable', 'estado' => 'correcto']);
        $noindex = Filas::resultado(['id_comprobacion' => 'SEO-01.noindex-desarrollo', 'estado' => 'problema', 'gravedad' => 'critico']);
        $title = Filas::resultado(['id_comprobacion' => 'SEO-03.title-presente', 'estado' => 'mejorable']);
        $ajeno = Filas::resultado(['id_comprobacion' => 'IA-05.autoria-respaldo', 'bloque' => 'ia', 'estado' => 'no_verificable']);

        $compromisos = $this->agrupar([$correcto, $noindex, $title, $ajeno], 'web_kit_digital');

        self::assertSame([$noindex['id'], $correcto['id']], array_column($compromisos['indexacion']['resultados'], 'id'), 'Primero el problema');
        self::assertSame(1, $compromisos['indexacion']['criticos']);
        self::assertSame(['problema' => 1, 'correcto' => 1], $compromisos['indexacion']['recuento']);
        self::assertSame([$title['id']], array_column($compromisos['seo_on_page']['resultados'], 'id'));
        self::assertNotContains($ajeno['id'], array_merge(...array_map(static fn (array $c): array => array_column($c['resultados'], 'id'), array_values($compromisos))));
    }

    public function testWcag22AparteYSinContarComoIncumplimiento(): void
    {
        // Prueba de aceptación: un fallo en 2.5.8 sale como "nuevo en 2.2" y no como incumplimiento del compromiso 2.1 AA.
        $alt = Filas::resultado(['id_comprobacion' => 'ACC-01.img-alt-presente', 'bloque' => 'acc', 'estado' => 'problema', 'gravedad' => 'alto']);
        $objetivo = Filas::resultado(['id_comprobacion' => 'ACC-08.tamano-objetivo', 'bloque' => 'acc', 'estado' => 'problema', 'gravedad' => 'critico']);

        $wcag = $this->agrupar([$alt, $objetivo], 'web_kit_digital')['wcag_2_1_aa'];

        self::assertSame([$alt['id']], array_column($wcag['resultados'], 'id'));
        self::assertSame([$objetivo['id']], array_column($wcag['fuera'], 'id'));
        self::assertSame('2.5.8', $wcag['fuera'][0]['wcag_criterio'], 'La referencia WCAG sale del catálogo si el resultado no la trae');
        self::assertSame(['problema' => 1], $wcag['recuento'], 'El 2.5.8 no cuenta en el compromiso');
        self::assertSame(0, $wcag['criticos'], 'Ni como problema crítico del compromiso');
    }

    public function testElCompromisoDeTiendaSoloAplicaATiendas(): void
    {
        self::assertFalse($this->agrupar([], 'web_kit_digital')['tienda_producto']['aplica']);
        self::assertTrue($this->agrupar([], 'tienda_kit_digital')['tienda_producto']['aplica']);
        self::assertTrue($this->agrupar([], 'web_kit_digital')['indexacion']['aplica']);
    }

    /** @return array<string, array<string, mixed>> */
    private function agrupar(array $resultados, string $tipo): array
    {
        return array_column(CompromisosKitDigital::agrupar(Informe::conTitulos($resultados, $this->catalogo), $this->catalogo, $tipo), null, 'codigo');
    }
}
