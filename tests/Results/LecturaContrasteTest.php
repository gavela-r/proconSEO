<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Results;

use PHPUnit\Framework\TestCase;
use ProconSEO\Results\LecturaContraste;

/**
 * La evidencia de contraste de PageSpeed (en inglés) convertida en filas legibles.
 */
final class LecturaContrasteTest extends TestCase
{
    private const GRANDE = 'div > p.titulo — <p class="titulo"> — Fix any of the following:  Element has insufficient color contrast of 1.19 (foreground color: #ebebeb, background color: #ffffff, font size: 90.0pt (120px), font weight: bold). Expected contrast rati…';
    private const NORMAL = 'article > p — <p> — Fix any of the following:  Element has insufficient color contrast of 4.47 (foreground color: #777777, background color: #FFFFFF, font size: 10.5pt (14px), font weight: normal). Expected contrast rat…';

    public function testExtraeColoresContrasteYLoExigidoJuntandoRepetidos(): void
    {
        $filas = LecturaContraste::filas(implode("\n", [self::GRANDE, self::GRANDE, self::NORMAL, self::GRANDE]));

        self::assertCount(2, $filas);
        self::assertSame('div > p.titulo', $filas[0]['selector']);
        self::assertSame(1.19, $filas[0]['contraste']);
        self::assertSame('#ebebeb', $filas[0]['texto']);
        self::assertSame(3.0, $filas[0]['exigido'], 'Texto grande: 3:1');
        self::assertSame(3, $filas[0]['veces']);
        self::assertSame('#ffffff', $filas[1]['fondo'], 'En minúsculas');
        self::assertSame(4.5, $filas[1]['exigido'], 'Texto normal: 4,5:1');
        self::assertFalse($filas[1]['negrita']);
    }

    public function testOtroFormatoNoSeInterpreta(): void
    {
        self::assertSame([], LecturaContraste::filas('<img src="x"> — sin explicación'));
        self::assertSame([], LecturaContraste::filas(''));
    }

    public function testSoloColoresHexadecimalesParaLaMuestra(): void
    {
        self::assertSame('#abc', LecturaContraste::colorSeguro('#abc'));
        self::assertNull(LecturaContraste::colorSeguro('red" onload="x'));
        self::assertNull(LecturaContraste::colorSeguro('#12345678'), 'Con transparencia no se pinta');
        self::assertSame('4,47', LecturaContraste::numero(4.47));
        self::assertSame('3', LecturaContraste::numero(3.0));
    }
}
