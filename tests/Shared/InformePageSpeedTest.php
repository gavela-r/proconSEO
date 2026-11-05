<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Shared;

use PHPUnit\Framework\TestCase;
use ProconSEO\Shared\Contract\ContextoAnalisis;
use ProconSEO\Shared\Contract\InformePageSpeed;
use ProconSEO\Shared\Contract\RecursoDescargado;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\ResultadoDescarga;

final class InformePageSpeedTest extends TestCase
{
    private static function contexto(array $informes): ContextoAnalisis
    {
        $pagina = new RecursoDescargado('https://ejemplo.es/', 'https://ejemplo.es/', ResultadoDescarga::Correcto, 200);

        return new ContextoAnalisis(1, Alcance::UrlIndividual, $pagina, ['ejemplo.es'], pageSpeed: $informes);
    }

    public function testElContextoDevuelveElInformeDeCadaDispositivo(): void
    {
        $movil = new InformePageSpeed(InformePageSpeed::MOVIL, 'https://ejemplo.es/', campo: ['lcp_ms' => 2100.0]);
        $ordenador = InformePageSpeed::sinDatos(InformePageSpeed::ORDENADOR, 'https://ejemplo.es/', 'Cuota agotada');

        $contexto = self::contexto([$movil, $ordenador]);

        self::assertSame($movil, $contexto->pageSpeed(InformePageSpeed::MOVIL));
        self::assertTrue($contexto->pageSpeed(InformePageSpeed::MOVIL)->disponible());
        self::assertFalse($contexto->pageSpeed(InformePageSpeed::ORDENADOR)->disponible());
        self::assertSame('Cuota agotada', $contexto->pageSpeed(InformePageSpeed::ORDENADOR)->error);
    }

    public function testSinInformesNoHayNada(): void
    {
        self::assertNull(self::contexto([])->pageSpeed(InformePageSpeed::MOVIL));
    }
}
