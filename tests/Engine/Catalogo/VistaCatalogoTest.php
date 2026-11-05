<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Engine\Catalogo;

use PHPUnit\Framework\TestCase;
use ProconSEO\Engine\Catalogo\VistaCatalogo;
use ProconSEO\Engine\Motor;
use ProconSEO\Shared\Enum\Bloque;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Http\Peticion;
use ProconSEO\Shared\Http\Respuesta;
use ProconSEO\Shared\Http\Router;
use ProconSEO\Shared\Vista;

/**
 * Página «Catálogo y cobertura» (RF-60).
 */
final class VistaCatalogoTest extends TestCase
{
    private static function sinFiltros(): array
    {
        return VistaCatalogo::filtros(null, null, null);
    }

    private static function pedir(string $ruta, array $consulta = []): Respuesta
    {
        $raiz = dirname(__DIR__, 3);
        Vista::configurar($raiz . '/templates');
        $router = new Router();
        (require $raiz . '/routes/catalogo.php')($router);
        return $router->despachar(new Peticion('GET', $ruta, $consulta));
    }

    public function testListaTodasLasSubcomprobacionesConLaCoberturaDelMotor(): void
    {
        $vista = VistaCatalogo::actual();
        $coberturas = Motor::v1()->coberturas();

        $listadas = [];
        foreach ($vista->criterios(self::sinFiltros()) as $criterio) {
            foreach ($criterio['subcomprobaciones'] as $s) {
                $listadas[$s['id']] = $s['cobertura'];
            }
        }

        self::assertSame($coberturas, $listadas);
        self::assertSame(count($coberturas), $vista->total());
    }

    public function testElResumenCuentaPorBloqueYSumaElTotal(): void
    {
        $resumen = VistaCatalogo::actual()->resumen();
        $coberturas = Motor::v1()->coberturas();

        self::assertSame(count($coberturas), $resumen['total']['total']);
        $implementadas = count(array_filter($coberturas, static fn (Cobertura $c) => $c === Cobertura::Implementada));
        self::assertSame($implementadas, $resumen['total'][Cobertura::Implementada->value]);
        foreach (Cobertura::cases() as $c) {
            $suma = 0;
            foreach (Bloque::cases() as $b) {
                $suma += $resumen[$b->value][$c->value];
            }
            self::assertSame($resumen['total'][$c->value], $suma, $c->value);
        }
    }

    public function testLasNoImplementadasDicenPorQue(): void
    {
        foreach (VistaCatalogo::actual()->criterios(VistaCatalogo::filtros(null, 'no_implementada', null)) as $criterio) {
            foreach ($criterio['subcomprobaciones'] as $s) {
                self::assertSame(Cobertura::NoImplementada, $s['cobertura']);
                self::assertNotSame([], $s['notas'], $s['id']);
            }
        }
    }

    public function testFiltraPorBloqueCoberturaYTexto(): void
    {
        $vista = VistaCatalogo::actual();

        foreach ($vista->criterios(VistaCatalogo::filtros('acc', null, null)) as $c) {
            self::assertSame(Bloque::Acc, $c['bloque']);
        }
        $ids = [];
        foreach ($vista->criterios(VistaCatalogo::filtros(null, null, '  1.1.1 ')) as $c) {
            foreach ($c['subcomprobaciones'] as $s) {
                $ids[] = $s['id'];
            }
        }
        self::assertContains('ACC-01.img-alt-presente', $ids);
        self::assertSame([], $vista->criterios(VistaCatalogo::filtros(null, null, 'zzz-no-existe')));
    }

    public function testIgnoraFiltrosQueNoReconoce(): void
    {
        $f = VistaCatalogo::filtros('inventado', ['array'], str_repeat('a', 500));

        self::assertNull($f['bloque']);
        self::assertNull($f['cobertura']);
        self::assertSame(VistaCatalogo::LARGO_BUSQUEDA, mb_strlen($f['texto']));
    }

    public function testMarcaLosCriteriosNuevosEnWcag22(): void
    {
        $versiones = [];
        foreach (VistaCatalogo::actual()->criterios(VistaCatalogo::filtros('acc', null, null)) as $c) {
            foreach ($c['subcomprobaciones'] as $s) {
                foreach ($s['wcag'] as $w) {
                    $versiones[$w['version']] = true;
                }
            }
        }
        self::assertArrayHasKey('2.1', $versiones);
        self::assertArrayHasKey('nuevo en 2.2', $versiones);
    }

    public function testLaPaginaMuestraElCatalogoCompleto(): void
    {
        $respuesta = self::pedir('/catalogo');
        $html = $respuesta->cuerpo;

        self::assertSame(200, $respuesta->estado);
        self::assertStringContainsString('<h1>Catálogo y cobertura</h1>', $html);
        self::assertStringContainsString('/assets/css/catalogo.css', $html);
        foreach (Motor::v1()->catalogo()->todas() as $ficha) {
            self::assertStringContainsString('id="' . $ficha->id . '"', $html);
        }
        self::assertStringContainsString('Parcialmente implementada', $html);
        // Solo recuentos: ni notas ni porcentajes de cobertura.
        self::assertDoesNotMatchRegularExpression('/\d+(?:[.,]\d+)?\s?%\s*(?:de\s+)?(?:cobertura|implementad)/iu', strip_tags($html));
    }

    public function testLaPaginaAplicaLosFiltros(): void
    {
        $html = self::pedir('/catalogo', ['bloque' => 'seo', 'cobertura' => 'implementada'])->cuerpo;

        self::assertStringContainsString('id="SEO-03.title-presente"', $html);
        self::assertStringNotContainsString('id="ACC-01.img-alt-presente"', $html);
        self::assertStringContainsString('Quitar filtros', $html);
        self::assertMatchesRegularExpression('/<option value="seo" selected>/', $html);
    }

    public function testLaBusquedaSeEscapa(): void
    {
        $html = self::pedir('/catalogo', ['texto' => '"><script>alert(1)</script>'])->cuerpo;

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&quot;&gt;&lt;script&gt;', $html);
        self::assertStringContainsString('Ninguna subcomprobación coincide', $html);
    }

    public function testLaCoberturaEnJson(): void
    {
        $respuesta = self::pedir('/api/catalogo/cobertura');
        $datos = json_decode($respuesta->cuerpo, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(Motor::v1()->catalogo()->version(), $datos['version']);
        self::assertSame(VistaCatalogo::actual()->resumen(), $datos['resumen']);
    }
}
