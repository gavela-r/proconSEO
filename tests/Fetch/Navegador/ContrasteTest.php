<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Fetch\Navegador;

use PHPUnit\Framework\TestCase;
use ProconSEO\Fetch\Navegador\Contraste;

final class ContrasteTest extends TestCase
{
    /** @param list<list<float|int>> $capas */
    private static function muestra(array $color, array $capas = [], float $tamano = 16, int $peso = 400, float $opacidad = 1.0): array
    {
        return ['color' => $color, 'opacidad' => $opacidad, 'capas' => $capas, 'tamano' => $tamano, 'peso' => $peso];
    }

    public function testValoresDeReferenciaDeWcag(): void
    {
        self::assertSame(21.0, round(Contraste::ratio([0, 0, 0], [255, 255, 255]), 2));
        self::assertSame(1.0, round(Contraste::ratio([120, 30, 200], [120, 30, 200]), 2));
        // #767676 sobre blanco es el gris más claro que cumple 4,5:1.
        self::assertSame(4.54, round(Contraste::ratio([0x76, 0x76, 0x76], [255, 255, 255]), 2));
    }

    public function testCumpleYNoCumpleSobreFondoBlancoPorDefecto(): void
    {
        $bien = Contraste::medir(self::muestra([0x76, 0x76, 0x76, 1]));
        self::assertTrue($bien['cumple']);
        self::assertSame(4.5, $bien['minimo']);

        $mal = Contraste::medir(self::muestra([0x77, 0x77, 0x77, 1]));
        self::assertFalse($mal['cumple'], '4,48:1 no llega aunque redondeado parezca 4,5');
    }

    public function testTextoGrandeExigeTresAUno(): void
    {
        $gris = [0x94, 0x94, 0x94, 1]; // 3,03:1 sobre blanco
        self::assertFalse(Contraste::medir(self::muestra($gris, [], 16))['cumple']);
        self::assertTrue(Contraste::medir(self::muestra($gris, [], 24))['cumple']);
        self::assertTrue(Contraste::medir(self::muestra($gris, [], 19, 700))['cumple'], '14 pt en negrita es texto grande');
        self::assertFalse(Contraste::medir(self::muestra($gris, [], 19, 400))['cumple']);
    }

    public function testMezclaCapasSemitransparentesYOpacidad(): void
    {
        // Negro al 50 % sobre blanco = gris medio; el texto blanco sobre él no llega a 4,5.
        $r = Contraste::medir(self::muestra([255, 255, 255, 1], [[0, 0, 0, 0.5]]));
        self::assertSame([128, 128, 128], $r['fondo']);
        self::assertFalse($r['cumple']);

        // Fondo opaco abajo y velo encima: se mezcla de abajo arriba.
        $r = Contraste::medir(self::muestra([255, 255, 255, 1], [[0, 0, 0, 0.5], [0, 0, 255, 1]]));
        self::assertSame([0, 0, 128], $r['fondo']);
        self::assertTrue($r['cumple']);

        // Texto negro con opacidad 0,3 sobre blanco: casi no se ve.
        self::assertFalse(Contraste::medir(self::muestra([0, 0, 0, 1], [], 16, 400, 0.3))['cumple']);
    }

    public function testHex(): void
    {
        self::assertSame('#e30613', Contraste::hex([227, 6, 19]));
    }
}
