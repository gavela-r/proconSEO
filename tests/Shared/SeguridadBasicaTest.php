<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Shared;

use PHPUnit\Framework\TestCase;
use ProconSEO\Shared\Error\ErrorDominio;
use ProconSEO\Shared\Http\Csrf;
use ProconSEO\Shared\Http\Peticion;

final class SeguridadBasicaTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testEscapaHtmlDeLaWebAnalizada(): void
    {
        self::assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', e('<script>alert("x")</script>'));
        self::assertSame('&apos; onmouseover=&apos;x', e("' onmouseover='x"));
    }

    public function testCsrfPermiteLecturasSinToken(): void
    {
        Csrf::verificar(new Peticion('GET', '/proyectos'));
        $this->addToAssertionCount(1);
    }

    public function testCsrfRechazaEscrituraSinToken(): void
    {
        Csrf::token();
        $this->expectException(ErrorDominio::class);
        Csrf::verificar(new Peticion('POST', '/proyectos', cuerpo: ['nombre' => 'x']));
    }

    public function testCsrfAceptaTokenCorrecto(): void
    {
        $token = Csrf::token();
        Csrf::verificar(new Peticion('POST', '/proyectos', cuerpo: ['_csrf' => $token]));
        Csrf::verificar(new Peticion('DELETE', '/api/analisis/1', cabeceras: ['x-csrf-token' => $token]));
        $this->addToAssertionCount(2);
    }
}
