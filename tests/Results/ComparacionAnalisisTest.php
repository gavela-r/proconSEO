<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Results;

use PHPUnit\Framework\TestCase;
use ProconSEO\Results\ComparacionAnalisis as C;

/**
 * Qué ha cambiado entre dos análisis de la misma dirección.
 */
final class ComparacionAnalisisTest extends TestCase
{
    private static function r(string $id, string $estado, ?string $gravedad = null, string $url = 'https://ejemplo.es/'): array
    {
        return Filas::resultado(['id_comprobacion' => $id, 'estado' => $estado, 'gravedad' => $gravedad, 'url_recurso' => $url]);
    }

    /** @return array<string, list<string>> grupo => ids */
    private static function ids(C $c): array
    {
        return array_map(static fn (array $l): array => array_map(static fn (array $p): string => (string) $p['ahora']['id_comprobacion'], $l), $c->grupos);
    }

    public function testClasificaCadaCambio(): void
    {
        $c = new C(
            [
                self::r('A', 'problema', 'alto'),
                self::r('B', 'correcto'),
                self::r('C', 'mejorable'),
                self::r('D', 'problema', 'bajo'),
                self::r('E', 'problema', 'alto'),
                self::r('F', 'problema', 'medio'),
                self::r('G', 'correcto'),
            ],
            [
                self::r('A', 'correcto'),           // arreglado
                self::r('B', 'problema', 'critico'), // nuevo
                self::r('C', 'problema', 'medio'),  // empeorado
                self::r('D', 'problema', 'alto'),   // empeorado
                self::r('E', 'mejorable'),          // mejorado
                self::r('F', 'problema', 'medio'),  // sigue igual
                self::r('G', 'correcto'),           // sin cambios: no aparece
            ],
        );

        self::assertSame([
            C::NUEVOS => ['B'],
            C::EMPEORADOS => ['D', 'C'],
            C::ARREGLADOS => ['A'],
            C::MEJORADOS => ['E'],
            C::SIGUEN => ['F'],
        ], self::ids($c), 'Dentro de cada grupo, lo más grave primero');
        self::assertTrue($c->hayCambios());
        self::assertSame(0, $c->sinComparar);
    }

    public function testUsaElEstadoDespuesDeLaRevisionHumana(): void
    {
        $antes = Filas::resultado(['id_comprobacion' => 'A', 'estado' => 'problema', 'estado_vigente' => 'correcto', 'gravedad' => 'alto']);

        $c = new C([$antes], [self::r('A', 'problema', 'alto')]);

        self::assertSame(['A'], self::ids($c)[C::NUEVOS], 'Antes una persona lo dio por bueno, así que ahora es nuevo');
    }

    public function testLoQueNoSePudoComprobarNoSeCompara(): void
    {
        $c = new C(
            [self::r('A', 'problema', 'alto'), self::r('B', 'no_verificable'), self::r('C', 'correcto')],
            [self::r('A', 'no_verificable'), self::r('B', 'problema', 'medio'), self::r('C', 'no_verificable')],
        );

        self::assertFalse($c->hayCambios(), 'No se puede decir que A se arregló ni que B es nuevo');
        self::assertSame(2, $c->sinComparar, 'C estaba bien y no se pudo comprobar: no cuenta');
        self::assertSame(0, $c->comparadas, 'Nada se ha podido comparar de verdad');
    }

    public function testMismaComprobacionEnPaginasDistintas(): void
    {
        $c = new C(
            [self::r('A', 'problema', 'medio', 'https://ejemplo.es/a'), self::r('A', 'correcto', null, 'https://ejemplo.es/b')],
            [self::r('A', 'correcto', null, 'https://ejemplo.es/a/'), self::r('A', 'problema', 'medio', 'https://ejemplo.es/b')],
        );

        self::assertCount(1, $c->grupos[C::ARREGLADOS], 'La barra final no hace distinta la página');
        self::assertCount(1, $c->grupos[C::NUEVOS]);
    }

    public function testLaMismaPaginaAnalizadaDosVecesCuentaUnaVez(): void
    {
        $c = new C(
            [self::r('A', 'correcto')],
            [self::r('A', 'no_verificable'), self::r('A', 'problema', 'bajo'), self::r('A', 'problema', 'alto')],
        );

        self::assertCount(1, $c->grupos[C::NUEVOS], 'Una sola vez, aunque la página saliera repetida');
        self::assertSame('alto', $c->grupos[C::NUEVOS][0]['ahora']['gravedad'], 'Con el resultado más grave');
        self::assertNotNull($c->grupos[C::NUEVOS][0]['antes']);
    }

    public function testPaginaQueAhoraNoSeAnalizoNoCuentaComoArreglada(): void
    {
        $c = new C([self::r('A', 'problema', 'medio', 'https://ejemplo.es/vieja')], [self::r('A', 'correcto')]);

        self::assertSame([], $c->grupos[C::ARREGLADOS]);
        self::assertSame(1, $c->sinComparar);
    }

    public function testLoQueAntesNoSeComprobabaEsNuevoSinAntes(): void
    {
        $c = new C([], [self::r('A', 'mejorable')]);

        self::assertNull($c->grupos[C::NUEVOS][0]['antes']);
    }
}
