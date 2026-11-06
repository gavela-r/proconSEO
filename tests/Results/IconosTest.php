<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Results;

use PHPUnit\Framework\TestCase;
use ProconSEO\Results\Iconos;
use ProconSEO\Shared\Enum\EstadoResultado;
use ProconSEO\Shared\Vista;

/**
 * Iconos de estado del diseño Proconsi: uno por estado, decorativos y sin recursos externos.
 */
final class IconosTest extends TestCase
{
    public function testUnIconoPorCadaEstado(): void
    {
        foreach (EstadoResultado::valores() as $estado) {
            $svg = Iconos::estado($estado);
            self::assertStringStartsWith('<svg class="icono"', $svg, "Falta el icono de {$estado}");
            self::assertStringContainsString('aria-hidden="true"', $svg);
            self::assertStringNotContainsString('http', str_replace('http://www.w3.org', '', $svg), 'Sin recursos externos');
        }
        self::assertSame('', Iconos::estado('inventado'));
        self::assertSame('', Iconos::estado(null));
    }

    public function testLasTarjetasLlevanIconoYTexto(): void
    {
        Vista::configurar(dirname(__DIR__, 2) . '/templates');
        $html = Vista::render('results/_resultado', ['r' => Filas::resultado(['estado' => 'problema', 'gravedad' => 'alto', 'titulo' => 'T'])], false);

        self::assertMatchesRegularExpression('#<span class="estado estado--problema"><svg[^>]*aria-hidden="true".*?</svg>Problema detectado</span>#s', $html);
    }
}
