<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Engine\Comprobaciones;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProconSEO\Engine\Comprobaciones\AccesibilidadMeta;
use ProconSEO\Shared\Contract\ContextoAnalisis;
use ProconSEO\Shared\Contract\RecursoDescargado;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\ResultadoDescarga;
use ProconSEO\Tests\Engine\Soporte\Ejecucion;

/**
 * ACC-04.zoom-bloqueado (ACT b4f0c3) y ACC-07.recarga-automatica (ACT bc659a).
 */
final class AccesibilidadMetaTest extends TestCase
{
    private const URL = 'https://www.x.example/';

    private static function ejecutar(string $head): Ejecucion
    {
        $html = '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>T</title>' . $head . '</head><body><main><h1>Hola</h1><p>Texto.</p></main></body></html>';
        $pagina = new RecursoDescargado(self::URL, self::URL, ResultadoDescarga::Correcto, 200, [], ['content-type' => 'text/html'], 'text/html', $html, bytes: strlen($html), tlsVerificado: true);
        return Ejecucion::de('cumple', contexto: new ContextoAnalisis(1, Alcance::UrlIndividual, $pagina, ['www.x.example']));
    }

    public function testUnaViewportNormalDejaAmpliar(): void
    {
        $e = self::ejecutar('<meta name="viewport" content="width=device-width, initial-scale=1">');

        self::assertSame('correcto', $e->estado('ACC-04.zoom-bloqueado'));
        self::assertSame('1.4.4', $e->resultado('ACC-04.zoom-bloqueado')->wcagCriterio);
    }

    public function testUserScalableNoEsUnProblemaAlto(): void
    {
        $r = self::ejecutar('<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no">')->resultado('ACC-04.zoom-bloqueado');

        self::assertSame('problema', $r->estado->value);
        self::assertSame('alto', $r->gravedad?->value);
        self::assertStringContainsString('user-scalable=no', (string) $r->consecuencia);
        self::assertNotEmpty($r->codigosEvidencia);
    }

    public function testSinViewportNoAplica(): void
    {
        self::assertSame('no_aplicable', self::ejecutar('')->estado('ACC-04.zoom-bloqueado'));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function viewports(): iterable
    {
        yield 'normal' => ['width=device-width, initial-scale=1', null];
        yield 'user-scalable=no' => ['width=device-width, user-scalable=no', 'user-scalable=no'];
        yield 'mayúsculas y punto y coma' => ['width=device-width; USER-SCALABLE = NO', 'user-scalable=no'];
        yield 'user-scalable=0' => ['user-scalable=0', 'user-scalable=0'];
        yield 'user-scalable=yes' => ['user-scalable=yes', null];
        yield 'user-scalable=1' => ['user-scalable=1', null];
        yield 'maximum-scale=1' => ['width=device-width, maximum-scale=1', 'maximum-scale=1'];
        yield 'maximum-scale=1.5' => ['maximum-scale=1.5', 'maximum-scale=1.5'];
        yield 'maximum-scale=2' => ['maximum-scale=2', null];
        yield 'maximum-scale=5' => ['maximum-scale=5.0', null];
        yield 'maximum-scale sin número' => ['maximum-scale=mucho', null];
    }

    #[DataProvider('viewports')]
    public function testReglaActDelZoom(string $contenido, ?string $esperado): void
    {
        self::assertSame($esperado, AccesibilidadMeta::bloqueoZoom($contenido));
    }

    public function testSinMetaRefreshEsCorrecto(): void
    {
        $r = self::ejecutar('')->resultado('ACC-07.recarga-automatica');

        self::assertSame('correcto', $r->estado->value);
        self::assertSame('2.2.1', $r->wcagCriterio);
        self::assertStringContainsString('JavaScript', (string) $r->limitacion);
    }

    public function testUnaRecargaALos30SegundosEsUnProblema(): void
    {
        $r = self::ejecutar('<meta http-equiv="refresh" content="30">')->resultado('ACC-07.recarga-automatica');

        self::assertSame('problema', $r->estado->value);
        self::assertSame('medio', $r->gravedad?->value);
        self::assertStringContainsString('se recarga', (string) $r->consecuencia);
    }

    public function testUnaRedireccionTemporizadaEsUnProblema(): void
    {
        $r = self::ejecutar('<meta http-equiv="Refresh" content="5; url=https://www.x.example/nueva">')->resultado('ACC-07.recarga-automatica');

        self::assertSame('problema', $r->estado->value);
        self::assertStringContainsString('lleva a otra página', (string) $r->consecuencia);
    }

    public function testUnaRedireccionInmediataNoEsUnLimiteDeTiempo(): void
    {
        self::assertSame('correcto', self::ejecutar('<meta http-equiv="refresh" content="0; url=/nueva">')->estado('ACC-07.recarga-automatica'));
    }

    public function testMasDeVeinteHorasNoEsUnLimiteDeTiempo(): void
    {
        self::assertSame('correcto', self::ejecutar('<meta http-equiv="refresh" content="72001">')->estado('ACC-07.recarga-automatica'));
        self::assertSame('problema', self::ejecutar('<meta http-equiv="refresh" content="72000">')->estado('ACC-07.recarga-automatica'));
    }

    public function testCuentaElPrimerMetaRefreshValido(): void
    {
        $e = self::ejecutar('<meta http-equiv="refresh" content="no vale"><meta http-equiv="refresh" content="10">');

        self::assertSame('problema', $e->estado('ACC-07.recarga-automatica'));
        self::assertSame('10', $e->resultado('ACC-07.recarga-automatica')->valorObservado);
    }

    /** @return iterable<string, array{string, ?int}> */
    public static function refrescos(): iterable
    {
        yield 'solo segundos' => ['30', 30];
        yield 'con url' => ['5; url=/otra', 5];
        yield 'con coma' => ['5,url=/otra', 5];
        yield 'decimales' => ['2.5', 2];
        yield 'espacios' => ['  0 ', 0];
        yield 'texto' => ['pronto', null];
        yield 'negativo' => ['-5', null];
        yield 'enorme' => ['99999999999999999999', PHP_INT_MAX];
    }

    #[DataProvider('refrescos')]
    public function testLecturaDelContenidoDelRefresh(string $contenido, ?int $esperado): void
    {
        self::assertSame($esperado, AccesibilidadMeta::esperaRefresh($contenido));
    }

    public function testConLaPaginaBloqueadaNoSeAfirmaNada(): void
    {
        $e = Ejecucion::de('bloqueada-403');

        self::assertSame('no_verificable', $e->estado('ACC-04.zoom-bloqueado'));
        self::assertSame('no_verificable', $e->estado('ACC-07.recarga-automatica'));
    }
}
