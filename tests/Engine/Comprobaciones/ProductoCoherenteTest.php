<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Engine\Comprobaciones;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProconSEO\Engine\Comprobaciones\ProductoCoherente;
use ProconSEO\Shared\Contract\ContextoAnalisis;
use ProconSEO\Shared\Contract\RecursoDescargado;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\ResultadoDescarga;
use ProconSEO\Tests\Engine\Soporte\Ejecucion;

/**
 * SEO-14.producto-precio-coherente y SEO-14.producto-disponibilidad (especificación 6.6).
 */
final class ProductoCoherenteTest extends TestCase
{
    private const URL = 'https://tienda.x.example/producto/1';
    private const PRECIO = 'SEO-14.producto-precio-coherente';
    private const DISPONIBILIDAD = 'SEO-14.producto-disponibilidad';

    private static function ejecutar(string $precio, string $disponibilidad, string $body): Ejecucion
    {
        $oferta = ['@type' => 'Offer', 'priceCurrency' => 'EUR'];
        if ($precio !== '') {
            $oferta['price'] = $precio;
        }
        if ($disponibilidad !== '') {
            $oferta['availability'] = 'https://schema.org/' . $disponibilidad;
        }
        $jsonld = json_encode(['@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'Garrafa', 'offers' => $oferta], JSON_UNESCAPED_SLASHES);
        $html = '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>Garrafa</title><script type="application/ld+json">' . $jsonld . '</script></head><body><main><h1>Garrafa</h1>' . $body . '</main></body></html>';
        $pagina = new RecursoDescargado(self::URL, self::URL, ResultadoDescarga::Correcto, 200, [], ['content-type' => 'text/html'], 'text/html', $html, bytes: strlen($html), tlsVerificado: true);
        return Ejecucion::de('cumple', contexto: new ContextoAnalisis(1, Alcance::UrlIndividual, $pagina, ['tienda.x.example']));
    }

    public function testElPrecioDelMarcadoSeVeEnLaPagina(): void
    {
        $e = self::ejecutar('1299.00', 'InStock', '<p class="price">1.299,00&nbsp;€</p><p>Hay existencias</p>');

        self::assertSame('correcto', $e->estado(self::PRECIO));
        self::assertSame('correcto', $e->estado(self::DISPONIBILIDAD));
    }

    public function testUnPrecioDistintoEsUnProblemaMedio(): void
    {
        $r = self::ejecutar('35.00', 'InStock', '<p class="price">42,50 €</p>')->resultado(self::PRECIO);

        self::assertSame('problema', $r->estado->value);
        self::assertSame('medio', $r->gravedad?->value);
        self::assertStringContainsString('42,50 €', (string) $r->valorObservado);
        self::assertNotEmpty($r->codigosEvidencia);
    }

    public function testSinPrecioVisibleNoSePuedeSaber(): void
    {
        $r = self::ejecutar('35.00', 'InStock', '<div id="precio"></div>')->resultado(self::PRECIO);

        self::assertSame('no_verificable', $r->estado->value);
        self::assertStringContainsString('JavaScript', (string) $r->limitacion);
    }

    public function testSinMarcadoProductNoAplica(): void
    {
        $e = Ejecucion::de('cumple');

        self::assertSame('no_aplicable', $e->estado(self::PRECIO));
        self::assertSame('no_aplicable', $e->estado(self::DISPONIBILIDAD));
    }

    public function testSinPrecioEnElMarcadoNoAplica(): void
    {
        self::assertSame('no_aplicable', self::ejecutar('', 'InStock', '<p>20 €</p>')->estado(self::PRECIO));
    }

    public function testUnProductoAgotadoQueLoDiceNoEsUnError(): void
    {
        self::assertSame('correcto', self::ejecutar('20', 'OutOfStock', '<p>20 €</p><p>Agotado</p>')->estado(self::DISPONIBILIDAD));
    }

    public function testMarcadoDisponiblePeroAgotadoEnLaPaginaEsUnProblema(): void
    {
        $r = self::ejecutar('20', 'InStock', '<p>20 €</p><p class="stock">Sin existencias</p>')->resultado(self::DISPONIBILIDAD);

        self::assertSame('problema', $r->estado->value);
        self::assertSame('medio', $r->gravedad?->value);
    }

    public function testMarcadoAgotadoPeroALaVentaEsUnProblema(): void
    {
        self::assertSame('problema', self::ejecutar('20', 'OutOfStock', '<p>20 €</p><button>Añadir al carrito</button>')->estado(self::DISPONIBILIDAD));
    }

    public function testNoDisponibleNoCuentaComoDisponible(): void
    {
        self::assertSame('correcto', self::ejecutar('20', 'OutOfStock', '<p>20 €</p><p>Producto no disponible</p>')->estado(self::DISPONIBILIDAD));
    }

    public function testSinAvisosDeExistenciasNoSePuedeSaber(): void
    {
        self::assertSame('no_verificable', self::ejecutar('20', 'InStock', '<p>20 €</p>')->estado(self::DISPONIBILIDAD));
    }

    public function testLasPaginasDeEjemploDeTiendas(): void
    {
        $woo = Ejecucion::de('woocommerce-producto');
        self::assertSame('correcto', $woo->estado(self::PRECIO));
        self::assertSame('correcto', $woo->estado(self::DISPONIBILIDAD));

        // PrestaShop de ejemplo no marca el precio: eso lo señala SEO-14.producto-marcado.
        self::assertSame('no_aplicable', Ejecucion::de('prestashop-producto')->estado(self::PRECIO));
    }

    public function testConLaPaginaBloqueadaNoSePuedeComprobar(): void
    {
        $e = Ejecucion::de('bloqueada-403');

        self::assertSame('no_verificable', $e->estado(self::PRECIO));
        self::assertSame('no_verificable', $e->estado(self::DISPONIBILIDAD));
    }

    /** @return iterable<string, array{string, ?float}> */
    public static function numeros(): iterable
    {
        yield 'punto decimal' => ['42.50', 42.5];
        yield 'coma decimal' => ['42,50', 42.5];
        yield 'miles con punto' => ['1.299,00', 1299.0];
        yield 'miles con coma' => ['1,299.00', 1299.0];
        yield 'miles sin decimales' => ['1.299', 1299.0];
        yield 'entero' => ['20', 20.0];
        yield 'un decimal' => ['9,9', 9.9];
        yield 'nada' => ['gratis', null];
    }

    #[DataProvider('numeros')]
    public function testLeeLosPrecios(string $texto, ?float $esperado): void
    {
        self::assertSame($esperado, ProductoCoherente::numero($texto));
    }

    public function testSoloCuentanLasCifrasConMoneda(): void
    {
        $precios = array_map(static fn (array $p): float => $p['valor'], ProductoCoherente::preciosVisibles('Garrafa de 5 L, envío en 24 h. Antes 49,90 € ahora €42.50 (IVA incl.)'));

        self::assertSame([49.9, 42.5], $precios);
    }
}
