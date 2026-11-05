<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Run;

use PHPUnit\Framework\TestCase;
use ProconSEO\Engine\Extraccion\Robots\AnalizadorRobots;
use ProconSEO\Run\ProveedorAcotado;
use ProconSEO\Shared\Enum\ResultadoDescarga;

final class ProveedorAcotadoTest extends TestCase
{
    public function testNoDescargaDosVecesLoMismo(): void
    {
        $falso = new ProveedorFalso(['https://ejemplo.es/robots.txt' => 'User-agent: *']);
        $proveedor = new ProveedorAcotado($falso);

        $proveedor->obtener('https://ejemplo.es/robots.txt', 'robots');
        $proveedor->obtener('https://ejemplo.es/robots.txt', 'robots otra vez');

        self::assertCount(1, $falso->pedidas);
    }

    public function testLoRecordadoNoCuentaNiSeDescarga(): void
    {
        $falso = new ProveedorFalso();
        $proveedor = new ProveedorAcotado($falso, 1, 'URL con contexto');
        $proveedor->recordar(ProveedorFalso::html('https://ejemplo.es/', '<html></html>'));

        self::assertTrue($proveedor->obtener('https://ejemplo.es/', 'página')->esCorrecto());
        self::assertSame([], $falso->pedidas);
        self::assertSame(0, $proveedor->urlsExtra());
    }

    public function testPasadoElTopeNoSePideNada(): void
    {
        $falso = new ProveedorFalso(['https://ejemplo.es/a' => '', 'https://ejemplo.es/b' => '']);
        $proveedor = new ProveedorAcotado($falso, 1, 'URL con contexto');

        self::assertTrue($proveedor->obtener('https://ejemplo.es/a', 'canonical')->esCorrecto());
        $fuera = $proveedor->obtener('https://ejemplo.es/b', 'hreflang');

        self::assertSame(ResultadoDescarga::FueraDeAmbito, $fuera->resultado);
        self::assertStringContainsString('máximo de 1 URLs', (string) $fuera->motivo);
        self::assertSame(['https://ejemplo.es/a'], $falso->pedidas);
    }

    public function testNoPideLoQueRobotsTxtProhibe(): void
    {
        $falso = new ProveedorFalso(['https://ejemplo.es/publico' => '', 'http://ejemplo.es/privado/a' => '', 'https://otra.ejemplo.es/privado/a' => '']);
        $proveedor = new ProveedorAcotado($falso);
        $proveedor->respetarRobots((new AnalizadorRobots())->analizar("User-agent: *\nDisallow: /privado/"), 'https://ejemplo.es', 'proconSEO');

        $bloqueada = $proveedor->obtener('https://ejemplo.es/privado/a', 'enlace interno');
        $proveedor->obtener('https://ejemplo.es/publico', 'enlace interno');
        $proveedor->obtener('https://ejemplo.es/robots.txt', 'robots');
        $proveedor->obtener('http://ejemplo.es/privado/a', 'variante http');
        $proveedor->obtener('https://otra.ejemplo.es/privado/a', 'otro host');

        self::assertSame(ResultadoDescarga::FueraDeAmbito, $bloqueada->resultado);
        self::assertStringContainsString('robots.txt', (string) $bloqueada->motivo);
        self::assertSame(['https://ejemplo.es/publico', 'https://ejemplo.es/robots.txt', 'http://ejemplo.es/privado/a', 'https://otra.ejemplo.es/privado/a'], $falso->pedidas,
            'Solo se bloquea en el origen de ese robots.txt; otros orígenes tienen el suyo');

        $bloqueadas = $proveedor->bloqueadas();
        self::assertCount(1, $bloqueadas, 'Lo bloqueado queda apuntado para la lista de recursos');
        self::assertSame('https://ejemplo.es/privado/a', $bloqueadas[0]['recurso']->urlSolicitada);
        self::assertSame('enlace interno', $bloqueadas[0]['motivo']);
    }

    public function testEnCadaPaginaEmpiezaElTopeYSeOlvidaElHtmlDeLaAnterior(): void
    {
        $robots = new \ProconSEO\Shared\Contract\RecursoDescargado('https://ejemplo.es/sitemap.xml', 'https://ejemplo.es/sitemap.xml', ResultadoDescarga::Correcto, 200, tipoContenido: 'application/xml', cuerpo: '<urlset/>');
        $falso = new ProveedorFalso(['https://ejemplo.es/sitemap.xml' => $robots, 'http://ejemplo.es/a' => '']);
        $proveedor = new ProveedorAcotado($falso, 2, 'Sitio completo');

        $proveedor->nuevaPagina(ProveedorFalso::html('https://ejemplo.es/a', ''));
        $proveedor->obtener('https://ejemplo.es/sitemap.xml', 'sitemap');
        $proveedor->obtener('http://ejemplo.es/a', 'variante http');
        self::assertSame(ResultadoDescarga::FueraDeAmbito, $proveedor->obtener('http://otra/', 'x')->resultado, 'Tope de 2 en la página a');

        $proveedor->nuevaPagina(ProveedorFalso::html('https://ejemplo.es/b', ''));
        $proveedor->obtener('https://ejemplo.es/sitemap.xml', 'sitemap');
        $proveedor->obtener('http://ejemplo.es/a', 'variante http');

        self::assertSame(['https://ejemplo.es/sitemap.xml', 'http://ejemplo.es/a', 'http://ejemplo.es/a'], $falso->pedidas,
            'El sitemap se recuerda; la página HTML de la anterior se olvida y el tope vuelve a empezar');
        self::assertSame(3, $proveedor->urlsExtra());
    }
}
