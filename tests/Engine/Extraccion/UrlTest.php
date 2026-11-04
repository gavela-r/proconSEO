<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Engine\Extraccion;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProconSEO\Engine\Extraccion\Url;

final class UrlTest extends TestCase
{
    /**
     * Ejemplos de la RFC 3986, sección 5.4 (con base http://a/b/c/d;p?q).
     *
     * @return iterable<string, array{string, string}>
     */
    public static function ejemplosRfc(): iterable
    {
        $casos = [
            'g' => 'http://a/b/c/g', './g' => 'http://a/b/c/g', 'g/' => 'http://a/b/c/g/',
            '/g' => 'http://a/g', '//g' => 'http://g/', '?y' => 'http://a/b/c/d;p?y',
            'g?y' => 'http://a/b/c/g?y', '#s' => 'http://a/b/c/d;p?q#s', 'g#s' => 'http://a/b/c/g#s',
            'g?y#s' => 'http://a/b/c/g?y#s', ';x' => 'http://a/b/c/;x', 'g;x' => 'http://a/b/c/g;x',
            '.' => 'http://a/b/c/', './' => 'http://a/b/c/', '..' => 'http://a/b/', '../' => 'http://a/b/',
            '../g' => 'http://a/b/g', '../..' => 'http://a/', '../../' => 'http://a/', '../../g' => 'http://a/g',
            '../../../g' => 'http://a/g', '/./g' => 'http://a/g', '/../g' => 'http://a/g',
            'g.' => 'http://a/b/c/g.', '.g' => 'http://a/b/c/.g', 'g..' => 'http://a/b/c/g..',
            './../g' => 'http://a/b/g', './g/.' => 'http://a/b/c/g/', 'g/./h' => 'http://a/b/c/g/h',
            'g/../h' => 'http://a/b/c/h', 'http://otro/x/../y' => 'http://otro/y',
        ];
        foreach ($casos as $ref => $esperado) {
            yield $ref => [$ref, $esperado];
        }
    }

    #[DataProvider('ejemplosRfc')]
    public function testResuelveComoLaRfc3986(string $referencia, string $esperado): void
    {
        self::assertSame($esperado, Url::resolver('http://a/b/c/d;p?q', $referencia));
    }

    public function testReferenciaVaciaOSoloEspaciosEsNull(): void
    {
        self::assertNull(Url::resolver('https://www.x.example/', ''));
        self::assertNull(Url::resolver('https://www.x.example/', '   '));
    }

    public function testEsquemasNoHttpNoSeResuelven(): void
    {
        foreach (['javascript:void(0)', 'mailto:a@x.example', 'tel:+34987000111', 'data:text/html,hola', 'file:///etc/passwd'] as $ref) {
            self::assertNull(Url::resolver('https://www.x.example/', $ref), $ref);
            self::assertTrue(Url::esNoNavegable($ref), $ref);
        }
        self::assertFalse(Url::esNoNavegable('/pagina'));
        self::assertFalse(Url::esNoNavegable('https://www.x.example/'));
    }

    public function testNormalizaHostYPuertoPorDefecto(): void
    {
        self::assertSame('https://www.x.example/a', Url::resolver('https://base.example/', 'HTTPS://WWW.X.EXAMPLE:443/a'));
        self::assertSame('http://www.x.example/', Url::resolver('https://base.example/', 'http://www.x.example:80'));
        self::assertSame('https://www.x.example:8443/', Url::resolver('https://base.example/', 'https://www.x.example:8443'));
    }

    public function testBaseNoHttpNoResuelveRelativas(): void
    {
        self::assertNull(Url::resolver('file:///tmp/', 'a.html'));
    }

    public function testHostYFragmento(): void
    {
        self::assertSame('www.x.example', Url::host('https://WWW.X.example/a'));
        self::assertNull(Url::host('/relativa'));
        self::assertSame('https://x.example/a?b', Url::sinFragmento('https://x.example/a?b#c'));
    }
}
