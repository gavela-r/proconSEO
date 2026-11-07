<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Engine\Catalogo;

use PHPUnit\Framework\TestCase;
use ProconSEO\Engine\Catalogo\CatalogoJson;
use ProconSEO\Shared\Contract\Catalogo;
use ProconSEO\Shared\Enum\Bloque;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\Esfuerzo;
use ProconSEO\Shared\Enum\ModoEvaluacion;
use RuntimeException;

final class CatalogoJsonTest extends TestCase
{
    public function testImplementaElContratoYDevuelveLaVersion(): void
    {
        $catalogo = CatalogoJson::v1();

        self::assertInstanceOf(Catalogo::class, $catalogo);
        self::assertSame('1.4.0', $catalogo->version());
    }

    public function testDevuelveTodasLasSubcomprobacionesSinRepetir(): void
    {
        $ids = array_map(static fn ($f) => $f->id, CatalogoJson::v1()->todas());

        self::assertCount(170, $ids);
        self::assertSame($ids, array_values(array_unique($ids)));
    }

    public function testSinCoberturaDeclaradaTodoEsNoImplementado(): void
    {
        foreach (CatalogoJson::v1()->todas() as $ficha) {
            self::assertSame(Cobertura::NoImplementada, $ficha->cobertura, $ficha->id);
        }
    }

    public function testAplicaLaCoberturaQueDeclaraElMotor(): void
    {
        $catalogo = CatalogoJson::v1(['SEO-03.title-presente' => Cobertura::Implementada]);

        self::assertSame(Cobertura::Implementada, $catalogo->obtener('SEO-03.title-presente')?->cobertura);
        self::assertSame(Cobertura::NoImplementada, $catalogo->obtener('SEO-03.title-unico')?->cobertura);
    }

    public function testRechazaCoberturaDeUnaSubcomprobacionInexistente(): void
    {
        $this->expectException(RuntimeException::class);

        CatalogoJson::v1(['SEO-03.no-existe' => Cobertura::Implementada]);
    }

    public function testFichaDeSeoConSusDatos(): void
    {
        $ficha = CatalogoJson::v1()->obtener('SEO-03.title-presente');

        self::assertNotNull($ficha);
        self::assertSame('SEO-03', $ficha->idCriterio);
        self::assertSame(Bloque::Seo, $ficha->bloque);
        self::assertTrue($ficha->enMvp);
        self::assertSame(Esfuerzo::Bajo, $ficha->esfuerzo);
        self::assertSame(ModoEvaluacion::Automatico, $ficha->modo);
        self::assertSame('O5, O4', $ficha->referenciaInvestigacion);
        self::assertNull($ficha->wcagCriterio);
        self::assertSame(['seo_on_page'], $ficha->extra['kit_digital']);
    }

    public function testFichaDeAccesibilidadConSuCriterioWcag(): void
    {
        $ficha = CatalogoJson::v1()->obtener('ACC-01.img-alt-presente');

        self::assertNotNull($ficha);
        self::assertSame(Bloque::Acc, $ficha->bloque);
        self::assertSame('1.1.1', $ficha->wcagCriterio);
        self::assertSame('Contenido no textual', $ficha->wcagNombre);
        self::assertSame('A', $ficha->wcagNivel);
        self::assertSame('2.1', $ficha->wcagVersion);
    }

    public function testCriterioNuevoDe22LoIndica(): void
    {
        $ficha = CatalogoJson::v1()->obtener('ACC-08.tamano-objetivo');

        self::assertSame('2.5.8', $ficha?->wcagCriterio);
        self::assertSame('2.2', $ficha?->wcagVersion);
        self::assertFalse($ficha?->enMvp);
    }

    public function testUnWcagDeOtroCriterioAccSeResuelve(): void
    {
        // ACC-09.labels-asociados cita 1.3.1, que pertenece a ACC-03.
        $ficha = CatalogoJson::v1()->obtener('ACC-09.labels-asociados');

        self::assertSame('1.3.1', $ficha?->wcagCriterio);
        self::assertSame('Información y relaciones', $ficha?->wcagNombre);
        self::assertCount(3, $ficha?->extra['wcag'] ?? []);
    }

    public function testDevuelveNullSiNoExiste(): void
    {
        self::assertNull(CatalogoJson::v1()->obtener('SEO-99.nada'));
    }

    public function testDaAccesoAlCriterioCompleto(): void
    {
        $criterio = CatalogoJson::v1()->criterio('SEO-11');

        self::assertSame(2.5, $criterio['umbrales']['LCP']['bueno_hasta'] ?? null);
    }

    public function testFallaSiElDirectorioNoExiste(): void
    {
        $this->expectException(RuntimeException::class);

        new CatalogoJson(__DIR__ . '/no-existe');
    }
}
