<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProconSEO\Engine\Comprobacion;
use ProconSEO\Engine\Entorno;
use ProconSEO\Engine\Motor;
use ProconSEO\Shared\Contract\BorradorResultado;
use ProconSEO\Shared\Contract\MotorAnalisis;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\Ejecucion as EjecucionEnum;
use ProconSEO\Shared\Enum\EstadoResultado;
use ProconSEO\Shared\Enum\Fase;
use ProconSEO\Tests\Engine\Soporte\CancelacionFalsa;
use ProconSEO\Tests\Engine\Soporte\Ejecucion;
use ProconSEO\Tests\Engine\Soporte\Fixtures;
use ProconSEO\Tests\Engine\Soporte\ProgresoFalso;
use ProconSEO\Tests\Engine\Soporte\ProveedorFalso;
use RuntimeException;

final class MotorTest extends TestCase
{
    public function testImplementaElContrato(): void
    {
        self::assertInstanceOf(MotorAnalisis::class, Motor::v1());
    }

    public function testDevuelveUnResultadoPorSubcomprobacionEnElOrdenDelCatalogo(): void
    {
        $motor = Motor::v1();
        $salida = $motor->ejecutar(Fixtures::contexto('cumple'), new ProveedorFalso(), new ProgresoFalso(), CancelacionFalsa::nunca());

        $esperados = array_map(static fn ($f) => $f->id, $motor->catalogo()->todas());
        self::assertSame($esperados, array_map(static fn (BorradorResultado $r) => $r->idComprobacion, $salida->resultados));
    }

    public function testLoNoImplementadoNuncaEsCorrecto(): void
    {
        $e = Ejecucion::de('cumple');
        $coberturas = Motor::v1()->coberturas();
        foreach ($e->salida->resultados as $r) {
            if ($coberturas[$r->idComprobacion] === Cobertura::NoImplementada) {
                self::assertSame(EstadoResultado::NoVerificable, $r->estado, $r->idComprobacion);
                self::assertSame(Cobertura::NoImplementada, $r->cobertura, $r->idComprobacion);
                self::assertNotEmpty($r->limitacion, $r->idComprobacion);
            }
        }
    }

    public function testLasEvidenciasCitadasExisten(): void
    {
        foreach (['cumple', 'no-cumple', 'noindex-desarrollo', 'redireccion-cadena'] as $fixture) {
            $e = Ejecucion::de($fixture);
            $codigos = array_map(static fn ($ev) => $ev->codigo, $e->salida->evidencias);
            self::assertSame($codigos, array_values(array_unique($codigos)));
            foreach ($e->salida->resultados as $r) {
                foreach ($r->codigosEvidencia as $c) {
                    self::assertContains($c, $codigos, "$fixture: {$r->idComprobacion} cita $c");
                }
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function paginasDeEjemplo(): iterable
    {
        foreach (Fixtures::nombres() as $nombre) {
            $url = Fixtures::datos($nombre)['url_solicitada'];
            if (!str_contains($url, 'robots.txt') && !str_contains($url, 'sitemap')) {
                yield $nombre => [$nombre];
            }
        }
    }

    /**
     * Prueba de aceptación: los resultados esperados de cada página de ejemplo coinciden con
     * los del motor para todo lo que ya está implementado.
     */
    #[DataProvider('paginasDeEjemplo')]
    public function testCumpleLosResultadosEsperadosDeCadaEjemplo(string $fixture): void
    {
        $datos = Fixtures::datos($fixture);
        $e = Ejecucion::de($fixture);
        $coberturas = Motor::v1()->coberturas();

        $comprobados = 0;
        foreach ($datos['esperado'] as $id => $estado) {
            if (($coberturas[$id] ?? Cobertura::NoImplementada) === Cobertura::NoImplementada) {
                continue;
            }
            $r = $e->resultado($id);
            self::assertSame($estado, $r->estado->value, "$fixture: $id — " . ($r->valorObservado ?? $r->limitacion ?? ''));
            $comprobados++;
        }
        foreach ($datos['gravedad_esperada'] ?? [] as $id => $gravedad) {
            if (($coberturas[$id] ?? Cobertura::NoImplementada) !== Cobertura::NoImplementada) {
                self::assertSame($gravedad, $e->resultado($id)->gravedad?->value, "$fixture: gravedad de $id");
            }
        }
        $this->addToAssertionCount($comprobados === 0 ? 1 : 0);
    }

    /**
     * El motor convierte cualquier excepción en no_verificable para no inventar hallazgos;
     * este test evita que eso esconda fallos del código.
     */
    #[DataProvider('paginasDeEjemplo')]
    public function testNingunEjemploProduceErroresInternos(string $fixture): void
    {
        foreach ([\ProconSEO\Shared\Enum\Alcance::UrlIndividual, \ProconSEO\Shared\Enum\Alcance::UrlContexto] as $alcance) {
            foreach (Ejecucion::de($fixture, [], $alcance)->salida->resultados as $r) {
                self::assertStringNotContainsString('Error interno', (string) $r->limitacion, "$fixture ({$alcance->value}): {$r->idComprobacion}");
            }
        }
    }

    #[DataProvider('paginasDeEjemplo')]
    public function testSiLaPaginaFallaNadaDeLoQueDependeDeEllaEsProblemaNiCorrecto(string $fixture): void
    {
        $e = Ejecucion::de($fixture);
        $pagina = Fixtures::recurso($fixture);
        if ($pagina->esCorrecto() && $pagina->estadoHttp < 300) {
            $this->addToAssertionCount(1);
            return;
        }
        // Lo que se basa en el HTML de la página nunca se valora con una página fallida.
        foreach (['SEO-01.contenido-mixto', 'SEO-01.formulario-sin-cifrar', 'SEO-01.indexable', 'SEO-01.soft-404',
            'SEO-01.cms-detectado', 'IA-02.meta-robots', 'IA-02.x-robots-tag', 'IA-02.fragmentos',
            'IA-03.contenido-html-inicial', 'SEO-02.canonical-coherente'] as $id) {
            self::assertSame(EstadoResultado::NoVerificable, $e->resultado($id)->estado, "$fixture: $id");
            self::assertSame(EjecucionEnum::NoEjecutada, $e->resultado($id)->ejecucion, "$fixture: $id");
        }
    }

    public function testSiSeCancelaLasPendientesQuedanNoEjecutadas(): void
    {
        $motor = Motor::v1();
        $salida = $motor->ejecutar(Fixtures::contexto('cumple'), new ProveedorFalso(), new ProgresoFalso(), CancelacionFalsa::yaCancelado());

        self::assertCount(count($motor->catalogo()->todas()), $salida->resultados);
        foreach ($salida->resultados as $r) {
            self::assertSame(EstadoResultado::NoVerificable, $r->estado);
            self::assertSame(EjecucionEnum::NoEjecutada, $r->ejecucion);
        }
    }

    public function testUnErrorEnUnaComprobacionNoSeConvierteEnHallazgo(): void
    {
        $rota = new class () implements Comprobacion {
            public function subcomprobaciones(): array
            {
                return ['SEO-03.title-presente' => Cobertura::Implementada];
            }

            public function evaluar(string $id, Entorno $entorno): BorradorResultado
            {
                throw new RuntimeException('fallo');
            }
        };
        $salida = (new Motor([$rota]))->ejecutar(Fixtures::contexto('cumple'), new ProveedorFalso(), new ProgresoFalso(), CancelacionFalsa::nunca());
        $r = array_values(array_filter($salida->resultados, static fn ($r) => $r->idComprobacion === 'SEO-03.title-presente'))[0];

        self::assertSame(EstadoResultado::NoVerificable, $r->estado);
        self::assertSame(EjecucionEnum::NoEjecutada, $r->ejecucion);
        self::assertStringContainsString('Error interno', (string) $r->limitacion);
    }

    public function testDosComprobacionesNoPuedenEvaluarLaMismaSubcomprobacion(): void
    {
        $this->expectException(RuntimeException::class);

        $c = new \ProconSEO\Engine\Comprobaciones\EstadoTecnico();
        new Motor([$c, $c]);
    }

    public function testInformaDelProgresoPorFases(): void
    {
        $progreso = new ProgresoFalso();
        Motor::v1()->ejecutar(Fixtures::contexto('cumple'), new ProveedorFalso(), $progreso, CancelacionFalsa::nunca());

        $fases = array_unique(array_map(static fn (array $a) => $a['fase'], $progreso->avisos), SORT_REGULAR);
        self::assertContains(Fase::Extraccion, $fases);
        self::assertContains(Fase::Comprobaciones, $fases);
        $ultimo = end($progreso->avisos);
        self::assertSame($ultimo['total'], $ultimo['hechos']);
    }

    public function testNoPideDosVecesLaMismaUrl(): void
    {
        $e = Ejecucion::de('cumple');
        $urls = $e->proveedor->urlsPedidas();

        self::assertSame($urls, array_values(array_unique($urls)));
        self::assertNotContains('https://www.cumple.example/', $urls, 'La página principal ya viene descargada');
    }

    public function testCadaPeticionIndicaQueComprobacionLaJustifica(): void
    {
        $e = Ejecucion::de('cumple', [], \ProconSEO\Shared\Enum\Alcance::UrlContexto);
        foreach ($e->proveedor->peticiones() as $p) {
            self::assertNotNull($p['idComprobacion'], $p['url']);
            self::assertNotSame('', $p['motivo']);
        }
    }

    public function testElTextoDeLaPaginaNoCambiaNingunResultado(): void
    {
        // SEG-07: la página con instrucciones al analizador se valora igual que si no las tuviera.
        $e = Ejecucion::de('inyeccion-instrucciones');
        self::assertSame('correcto', $e->estado('IA-02.meta-robots'));
        self::assertSame('no_aplicable', $e->estado('SEO-01.formulario-sin-cifrar'));
        foreach ($e->salida->resultados as $r) {
            self::assertStringNotContainsStringIgnoringCase('ignora', (string) $r->explicacionSencilla);
        }
    }
}
