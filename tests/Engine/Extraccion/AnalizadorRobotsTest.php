<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Engine\Extraccion;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProconSEO\Engine\Extraccion\Robots\AnalizadorRobots;
use ProconSEO\Engine\Extraccion\Robots\ReglasRobots;

final class AnalizadorRobotsTest extends TestCase
{
    private static function reglas(string $texto): ReglasRobots
    {
        return (new AnalizadorRobots())->analizar($texto);
    }

    public function testSinRobotsOVacioPermiteTodo(): void
    {
        $r = self::reglas('');

        self::assertTrue($r->permite('Googlebot', 'https://www.x.example/cualquier/cosa'));
        self::assertSame([], $r->grupos);
    }

    public function testBloqueaTodoATodos(): void
    {
        $r = self::reglas("User-agent: *\nDisallow: /\n");

        self::assertFalse($r->permite('Googlebot', 'https://www.x.example/'));
        self::assertFalse($r->permite('OAI-SearchBot', '/pagina'));
        self::assertSame(['permitido' => false, 'grupo' => '*', 'regla' => 'Disallow: /', 'linea' => 2], $r->evaluar('bingbot', '/'));
    }

    public function testRobotsTxtSiemprePermitido(): void
    {
        self::assertTrue(self::reglas("User-agent: *\nDisallow: /")->permite('Googlebot', '/robots.txt'));
    }

    public function testElGrupoPropioSustituyeAlGenerico(): void
    {
        $r = self::reglas("User-agent: *\nDisallow: /\n\nUser-agent: Googlebot\nAllow: /\n");

        self::assertTrue($r->permite('Googlebot', '/a'));
        self::assertFalse($r->permite('bingbot', '/a'));
        self::assertTrue($r->tieneGrupoPropio('googlebot'));
        self::assertFalse($r->tieneGrupoPropio('bingbot'));
    }

    public function testVariosUserAgentEnUnGrupoYGruposRepetidosSeCombinan(): void
    {
        $r = self::reglas("User-agent: Googlebot\nUser-agent: bingbot\nDisallow: /buscar\n\nUser-agent: googlebot\nDisallow: /privado\n");

        self::assertFalse($r->permite('bingbot', '/buscar?q=1'));
        self::assertFalse($r->permite('Googlebot', '/buscar'));
        self::assertFalse($r->permite('Googlebot', '/privado/x'));
        self::assertTrue($r->permite('bingbot', '/privado/x'));
    }

    public function testUserAgentSinDistinguirMayusculasYConVersion(): void
    {
        $r = self::reglas("user-agent: PERPLEXITYBOT/1.0\ndisallow: /interno/\n");

        self::assertFalse($r->permite('PerplexityBot', '/interno/a'));
        self::assertTrue($r->permite('PerplexityBot', '/publico'));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function coincidencias(): iterable
    {
        // [reglas, ruta, permitido]
        yield 'prefijo' => ["Disallow: /fish", '/fish.html', false];
        yield 'prefijo no coincide' => ["Disallow: /fish", '/Fish.asp', true];
        yield 'comodín' => ["Disallow: /*.php", '/carpeta/index.php?x=1', false];
        yield 'fin con $' => ["Disallow: /*.pdf$", '/docs/a.pdf', false];
        yield 'fin con $ y consulta' => ["Disallow: /*.pdf$", '/docs/a.pdf?v=2', true];
        yield 'consulta' => ["Disallow: /*?sesion=", '/a?sesion=123', false];
        yield 'regla más larga gana' => ["Disallow: /buscar\nAllow: /buscar/ayuda", '/buscar/ayuda/x', true];
        yield 'regla más larga gana (disallow)' => ["Allow: /p\nDisallow: /p/privado", '/p/privado', false];
        yield 'empate: gana allow' => ["Disallow: /pagina\nAllow: /pagina", '/pagina', true];
        yield 'disallow vacío no bloquea' => ["Disallow:", '/', true];
        yield 'codificación de caracteres' => ["Disallow: /año", '/a%C3%B1o', false];
        yield 'hex en minúsculas' => ["Disallow: /a%c3%b1o", '/año', false];
    }

    #[DataProvider('coincidencias')]
    public function testCoincidenciaDeReglasRfc9309(string $reglas, string $ruta, bool $permitido): void
    {
        self::assertSame($permitido, self::reglas("User-agent: *\n" . $reglas)->permite('Googlebot', $ruta));
    }

    public function testComentariosLineasInvalidasEIgnoradasYSitemaps(): void
    {
        $r = self::reglas("# comentario\nDisallow: /antes-de-grupo\nUser-agent: * # todos\nDisallow: /tmp/ # temporal\nCrawl-delay: 10\nEsto no es una regla\nDisallow /sin-dos-puntos\nSitemap: https://www.x.example/sitemap.xml\n");

        self::assertFalse($r->permite('Googlebot', '/tmp/a'));
        self::assertTrue($r->permite('Googlebot', '/antes-de-grupo'));
        self::assertSame(['https://www.x.example/sitemap.xml'], $r->sitemaps);
        self::assertSame([2, 6, 7], array_column($r->lineasInvalidas, 'linea'));
        self::assertSame([5], array_column($r->lineasIgnoradas, 'linea'));
    }

    public function testFinesDeLineaYBom(): void
    {
        $r = self::reglas("\xEF\xBB\xBFUser-agent: *\r\nDisallow: /a\rDisallow: /b\n");

        self::assertFalse($r->permite('Googlebot', '/a'));
        self::assertFalse($r->permite('Googlebot', '/b'));
    }

    public function testDistingueBuscadoresConIaDeAgentesDeEntrenamiento(): void
    {
        $r = self::reglas("User-agent: GPTBot\nDisallow: /\n\nUser-agent: ClaudeBot\nDisallow: /\n\nUser-agent: Google-Extended\nDisallow: /\n\nUser-agent: *\nAllow: /\n");

        foreach (['GPTBot', 'ClaudeBot', 'Google-Extended'] as $entrenamiento) {
            self::assertFalse($r->permite($entrenamiento, '/'), $entrenamiento);
        }
        foreach (['OAI-SearchBot', 'Claude-SearchBot', 'PerplexityBot', 'Googlebot', 'bingbot'] as $buscador) {
            self::assertTrue($r->permite($buscador, '/'), $buscador);
        }
    }

    public function testAceptaUrlAbsolutaYRuta(): void
    {
        $r = self::reglas("User-agent: *\nDisallow: /privado");

        self::assertFalse($r->permite('Googlebot', 'https://www.x.example/privado?a=1#f'));
        self::assertFalse($r->permite('Googlebot', 'privado'));
        self::assertTrue($r->permite('Googlebot', 'https://www.x.example'));
    }

    public function testLeeComoMaximo500KiB(): void
    {
        $relleno = str_repeat("# relleno\n", 60000);
        $r = self::reglas("User-agent: *\n" . $relleno . "Disallow: /\n");

        self::assertTrue($r->truncado);
        self::assertTrue($r->permite('Googlebot', '/'), 'Lo que hay después del límite se ignora');
    }

    public function testUnPatronConMuchosComodinesNoBloqueaElAnalisis(): void
    {
        $patron = '/' . str_repeat('*a', 200) . 'b';
        $r = self::reglas("User-agent: *\nDisallow: " . $patron);

        $inicio = microtime(true);
        self::assertTrue($r->permite('Googlebot', '/' . str_repeat('a', 2000)));
        self::assertLessThan(2.0, microtime(true) - $inicio);
        self::assertFalse($r->permite('Googlebot', '/' . str_repeat('a', 200) . 'b'));
    }
}
