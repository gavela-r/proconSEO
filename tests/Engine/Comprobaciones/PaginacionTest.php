<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Engine\Comprobaciones;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProconSEO\Engine\Comprobaciones\Paginacion;
use ProconSEO\Shared\Contract\ContextoAnalisis;
use ProconSEO\Shared\Contract\RecursoDescargado;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\ResultadoDescarga;
use ProconSEO\Tests\Engine\Soporte\Ejecucion;

/**
 * SEO-16.paginacion: listados paginados rastreables y cada página con su URL.
 */
final class PaginacionTest extends TestCase
{
    private const ID = 'SEO-16.paginacion';

    private static function ejecutar(string $body, string $url = 'https://www.x.example/tienda/', string $head = ''): Ejecucion
    {
        $html = '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>Tienda</title>' . $head . '</head><body><main><h1>Tienda</h1><ul><li>Producto</li></ul>' . $body . '</main></body></html>';
        $pagina = new RecursoDescargado($url, $url, ResultadoDescarga::Correcto, 200, [], ['content-type' => 'text/html'], 'text/html', $html, bytes: strlen($html), tlsVerificado: true);
        return Ejecucion::de('cumple', contexto: new ContextoAnalisis(1, Alcance::UrlIndividual, $pagina, ['www.x.example']));
    }

    public function testUnaPaginacionConEnlacesCumple(): void
    {
        $e = self::ejecutar('<nav class="woocommerce-pagination"><ul class="page-numbers"><li><span aria-current="page" class="page-numbers current">1</span></li><li><a class="page-numbers" href="/tienda/page/2/">2</a></li><li><a class="next page-numbers" href="/tienda/page/2/">→</a></li></ul></nav>');

        self::assertSame('correcto', $e->estado(self::ID));
        self::assertStringContainsString('2 enlaces de paginación rastreables', (string) $e->resultado(self::ID)->valorObservado);
    }

    public function testBotonesYFragmentosSonUnProblemaMedio(): void
    {
        $r = self::ejecutar('<div class="pagination"><button onclick="cargar(2)">2</button><a href="#pagina-3">3</a><a href="javascript:cargar(4)">Siguiente</a></div>')->resultado(self::ID);

        self::assertSame('problema', $r->estado->value);
        self::assertSame('medio', $r->gravedad?->value);
        self::assertStringContainsString('3 que Google no puede seguir', (string) $r->valorObservado);
        self::assertNotEmpty($r->codigosEvidencia);
    }

    public function testLaPagina2ConCanonicalALaPrimeraEsMejorable(): void
    {
        $e = self::ejecutar(
            '<nav class="pagination"><a href="/tienda/">1</a><a href="/tienda/?page=3">3</a></nav>',
            'https://www.x.example/tienda/?page=2',
            '<link rel="canonical" href="https://www.x.example/tienda/">',
        );

        self::assertSame('mejorable', $e->estado(self::ID));
    }

    public function testLaPagina2CanonicaDeSiMismaCumple(): void
    {
        $e = self::ejecutar(
            '<nav class="pagination"><a href="/tienda/">1</a><a href="/tienda/page/3/">3</a></nav>',
            'https://www.x.example/tienda/page/2/',
            '<link rel="canonical" href="https://www.x.example/tienda/page/2/">',
        );

        self::assertSame('correcto', $e->estado(self::ID));
    }

    public function testSinListadoPaginadoNoAplica(): void
    {
        self::assertSame('no_aplicable', Ejecucion::de('cumple')->estado(self::ID));
        // Números sueltos fuera de un bloque de paginación no cuentan.
        self::assertSame('no_aplicable', self::ejecutar('<p><a href="/tel">2</a> <button>3</button></p>')->estado(self::ID));
    }

    public function testConLaPaginaBloqueadaNoSePuedeComprobar(): void
    {
        self::assertSame('no_verificable', Ejecucion::de('bloqueada-403')->estado(self::ID));
    }

    /** @return iterable<string, array{string, ?int, string}> */
    public static function urls(): iterable
    {
        yield 'WordPress' => ['https://x.example/blog/page/3/', 3, 'https://x.example/blog/'];
        yield 'parámetro' => ['https://x.example/tienda?page=2', 2, 'https://x.example/tienda'];
        yield 'parámetro y otros' => ['https://x.example/tienda?orden=precio&pagina=4', 4, 'https://x.example/tienda?orden=precio'];
        yield 'PrestaShop' => ['https://x.example/12-calzado?p=2', 2, 'https://x.example/12-calzado'];
        yield 'producto /p/' => ['https://x.example/p/12345', null, 'https://x.example/p/12345'];
        yield 'sin número' => ['https://x.example/tienda/', null, 'https://x.example/tienda/'];
    }

    #[DataProvider('urls')]
    public function testNumeroDePaginaEnLaUrl(string $url, ?int $numero, string $primera): void
    {
        self::assertSame($numero, Paginacion::numeroPagina($url));
        self::assertSame($primera, Paginacion::primeraPagina($url));
    }
}
