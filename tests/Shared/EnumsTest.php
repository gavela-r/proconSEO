<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Shared;

use PHPUnit\Framework\TestCase;
use ProconSEO\Shared\Enum\EstadoResultado;
use ProconSEO\Shared\Enum\Fase;
use ProconSEO\Shared\Enum\Gravedad;

final class EnumsTest extends TestCase
{
    public function testEstadosSonLosSeisDeLaGuia(): void
    {
        self::assertSame(
            ['correcto', 'mejorable', 'problema', 'decision_revisar', 'no_verificable', 'no_aplicable'],
            EstadoResultado::valores(),
        );
    }

    public function testGravedadTieneCuatroNiveles(): void
    {
        self::assertSame(['critico', 'alto', 'medio', 'bajo'], Gravedad::valores());
    }

    public function testFasesEnOrden(): void
    {
        self::assertSame('validacion', Fase::cases()[0]->value);
        self::assertSame('cierre', Fase::cases()[count(Fase::cases()) - 1]->value);
    }

    public function testCadaValorTieneEtiquetaEnEspanol(): void
    {
        self::assertSame('Problema detectado', EstadoResultado::Problema->etiqueta());
        self::assertCount(6, EstadoResultado::opciones());
    }
}
