<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Engine\Comprobaciones;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProconSEO\Engine\Comprobaciones\AccesibilidadEntrada;
use ProconSEO\Shared\Contract\ContextoAnalisis;
use ProconSEO\Shared\Contract\RecursoDescargado;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\ResultadoDescarga;
use ProconSEO\Tests\Engine\Soporte\Ejecucion;

/**
 * ACC-08.etiqueta-en-nombre (WCAG 2.5.3, regla ACT 2ee8b8).
 */
final class AccesibilidadEntradaTest extends TestCase
{
    private const URL = 'https://www.x.example/';
    private const ID = 'ACC-08.etiqueta-en-nombre';

    private static function ejecutar(string $body): Ejecucion
    {
        $html = '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>T</title></head><body><main><h1>Hola</h1>' . $body . '</main></body></html>';
        $pagina = new RecursoDescargado(self::URL, self::URL, ResultadoDescarga::Correcto, 200, [], ['content-type' => 'text/html'], 'text/html', $html, bytes: strlen($html), tlsVerificado: true);
        return Ejecucion::de('cumple', contexto: new ContextoAnalisis(1, Alcance::UrlIndividual, $pagina, ['www.x.example']));
    }

    public function testUnNombreQueEmpiezaPorElTextoVisibleCumple(): void
    {
        $e = self::ejecutar('<a href="/carrito" aria-label="Comprar ahora, abre el carrito">Comprar ahora</a>');

        self::assertSame('correcto', $e->estado(self::ID));
        self::assertSame('2.5.3', $e->resultado(self::ID)->wcagCriterio);
    }

    public function testUnNombreDistintoDelTextoVisibleEsUnProblemaMedio(): void
    {
        $r = self::ejecutar('<button aria-label="Enviar formulario">Solicitar presupuesto</button>')->resultado(self::ID);

        self::assertSame('problema', $r->estado->value);
        self::assertSame('medio', $r->gravedad?->value);
        self::assertStringContainsString('Solicitar presupuesto', (string) $r->consecuencia);
        self::assertStringContainsString('Enviar formulario', (string) $r->consecuencia);
        self::assertNotEmpty($r->codigosEvidencia);
        self::assertNotNull($r->limitacion);
    }

    public function testAriaLabelledbyTambienCuenta(): void
    {
        $e = self::ejecutar('<span id="n">Ver ofertas</span><a href="/o" aria-labelledby="n">Catálogo</a>');

        self::assertSame('problema', $e->estado(self::ID));
    }

    public function testSinNombrePuestoAManoNoAplica(): void
    {
        $e = self::ejecutar('<a href="/a">Contacto</a><button>Enviar</button>');

        self::assertSame('no_aplicable', $e->estado(self::ID));
    }

    public function testUnIconoSinTextoVisibleNoSeRevisa(): void
    {
        $e = self::ejecutar('<button aria-label="Cerrar">×</button><a href="/c" aria-label="Carrito"><svg><title>c</title></svg></a>');

        self::assertSame('no_aplicable', $e->estado(self::ID));
    }

    public function testLoOcultoNoCuenta(): void
    {
        $e = self::ejecutar('<a href="/x" aria-hidden="true" aria-label="Otra cosa">Inicio</a><button aria-label="Buscar">Buscar<span style="display:none">ahora mismo</span></button>');

        self::assertSame('correcto', $e->estado(self::ID));
    }

    public function testConLaPaginaBloqueadaNoSePuedeComprobar(): void
    {
        self::assertSame('no_verificable', Ejecucion::de('bloqueada-403')->estado(self::ID));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function nombres(): iterable
    {
        yield 'igual' => ['Comprar', 'Comprar', true];
        yield 'mayúsculas y signos' => ['comprar ya', '¡Comprar YA!', true];
        yield 'al principio' => ['Leer más sobre el horario', 'Leer más', true];
        yield 'en medio, palabras completas' => ['Ir a: leer más del horario', 'Leer más', true];
        yield 'distinto' => ['Enviar', 'Solicitar', false];
        yield 'solo parte de una palabra' => ['Compraventa', 'Compra', false];
        yield 'vacío' => ['', 'Comprar', false];
    }

    #[DataProvider('nombres')]
    public function testComparacion(string $nombre, string $visible, bool $esperado): void
    {
        self::assertSame($esperado, AccesibilidadEntrada::contiene($nombre, $visible));
    }
}
