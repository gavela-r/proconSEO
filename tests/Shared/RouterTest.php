<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Shared;

use PHPUnit\Framework\TestCase;
use ProconSEO\Shared\Error\ErrorDominio;
use ProconSEO\Shared\Http\Peticion;
use ProconSEO\Shared\Http\Respuesta;
use ProconSEO\Shared\Http\Router;

final class RouterTest extends TestCase
{
    public function testDespachaRutaConParametro(): void
    {
        $router = new Router();
        $router->get('/proyectos/{id}', static fn (Peticion $p): Respuesta => Respuesta::html('proyecto ' . $p->parametro('id')));

        $respuesta = $router->despachar(new Peticion('GET', '/proyectos/42'));

        self::assertSame('proyecto 42', $respuesta->cuerpo);
    }

    public function testParametroSoloAdmiteNumeros(): void
    {
        $router = new Router();
        $router->get('/proyectos/{id}', static fn (Peticion $p): Respuesta => Respuesta::html('ok'));

        $this->expectException(ErrorDominio::class);
        $router->despachar(new Peticion('GET', '/proyectos/abc'));
    }

    public function testMetodoIncorrectoDevuelve405(): void
    {
        $router = new Router();
        $router->get('/proyectos', static fn (Peticion $p): Respuesta => Respuesta::html('ok'));

        try {
            $router->despachar(new Peticion('POST', '/proyectos'));
            self::fail('Debería lanzar un error');
        } catch (ErrorDominio $e) {
            self::assertSame(405, $e->estadoHttp);
        }
    }

    public function testRutaInexistenteDevuelve404(): void
    {
        try {
            (new Router())->despachar(new Peticion('GET', '/no-existe'));
            self::fail('Debería lanzar un error');
        } catch (ErrorDominio $e) {
            self::assertSame(404, $e->estadoHttp);
            self::assertSame('no_encontrado', $e->comoArray()['error']['codigo']);
        }
    }

    public function testRedirigirSoloAceptaRutasInternas(): void
    {
        self::assertSame('/', Respuesta::redirigir('https://otro-dominio.test/')->cabeceras['Location']);
        self::assertSame('/', Respuesta::redirigir('//otro-dominio.test/')->cabeceras['Location']);
        self::assertSame('/proyectos', Respuesta::redirigir('/proyectos')->cabeceras['Location']);
    }
}
