<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Shared;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ProconSEO\Shared\Contract\BorradorEvidencia;
use ProconSEO\Shared\Contract\BorradorResultado;
use ProconSEO\Shared\Enum\Bloque;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\Ejecucion;
use ProconSEO\Shared\Enum\EstadoResultado;
use ProconSEO\Shared\Enum\Gravedad;
use ProconSEO\Shared\Enum\TipoConclusion;
use ProconSEO\Shared\Enum\TipoEvidencia;

/**
 * Las reglas de la especificación que el contrato hace cumplir a cualquier comprobación.
 */
final class BorradorResultadoTest extends TestCase
{
    private function crear(array $cambios = []): BorradorResultado
    {
        return new BorradorResultado(...$cambios + [
            'idComprobacion' => 'SEO-03.title-presente',
            'idCriterio' => 'SEO-03',
            'bloque' => Bloque::Seo,
            'estado' => EstadoResultado::Problema,
            'ejecucion' => Ejecucion::Ejecutada,
            'cobertura' => Cobertura::Implementada,
            'tipoConclusion' => TipoConclusion::Hecho,
            'explicacionSencilla' => 'La página no tiene título.',
            'versionRegla' => 'v1',
        ]);
    }

    public function testProblemaConGravedadEsValido(): void
    {
        self::assertSame(Gravedad::Alto, $this->crear(['gravedad' => Gravedad::Alto])->gravedad);
    }

    public function testGravedadSoloEnProblemas(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->crear(['estado' => EstadoResultado::Mejorable, 'gravedad' => Gravedad::Bajo]);
    }

    public function testInferenciasSinGravedad(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->crear(['tipoConclusion' => TipoConclusion::Inferencia, 'gravedad' => Gravedad::Medio]);
    }

    public function testNoImplementadaNuncaEsCorrecta(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->crear(['estado' => EstadoResultado::Correcto, 'cobertura' => Cobertura::NoImplementada]);
    }

    public function testNoEjecutadaNuncaEsProblema(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->crear(['ejecucion' => Ejecucion::NoEjecutada]);
    }

    public function testRevisionHumanaNecesitaMotivo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->crear(['estado' => EstadoResultado::NoVerificable, 'requiereRevisionHumana' => true]);
    }

    public function testAccesibilidadIndicaCriterioWcag(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->crear(['bloque' => Bloque::Acc, 'idComprobacion' => 'ACC-01.img-alt-presente', 'idCriterio' => 'ACC-01']);
    }

    public function testAtajoNoImplementada(): void
    {
        $r = BorradorResultado::noImplementada('IA-05.autoria', 'IA-05', Bloque::Ia, 'Necesita IA semántica');

        self::assertSame(EstadoResultado::NoVerificable, $r->estado);
        self::assertSame(Ejecucion::NoEjecutada, $r->ejecucion);
        self::assertSame(Cobertura::NoImplementada, $r->cobertura);
    }

    public function testEvidenciaLargaSeTrunca(): void
    {
        $e = new BorradorEvidencia('E-001', TipoEvidencia::FragmentoHtml, str_repeat('a', 5000));

        self::assertTrue($e->truncado);
        self::assertSame(BorradorEvidencia::MAXIMO_CARACTERES, mb_strlen($e->contenido));
    }
}
