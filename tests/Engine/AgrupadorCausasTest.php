<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Engine;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProconSEO\Engine\AgrupadorCausas;
use ProconSEO\Engine\Causas;
use ProconSEO\Engine\Motor;
use ProconSEO\Shared\Contract\BorradorResultado;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Tests\Engine\Soporte\Ejecucion;
use ProconSEO\Tests\Engine\Soporte\Fixtures;

/**
 * Fase 7: agrupación de resultados por causa (RF-21 y RF-24).
 */
final class AgrupadorCausasTest extends TestCase
{
    /** @return array<string, list<string>> causa => ids de sus resultados */
    private static function grupos(Ejecucion $e): array
    {
        $grupos = [];
        foreach ($e->salida->resultados as $r) {
            if ($r->codigoGrupoCausa !== null) {
                $grupos[$r->codigoGrupoCausa][] = $r->idComprobacion;
            }
        }
        return $grupos;
    }

    public function testUnaMismaCausaReuneResultadosDeVariosBloques(): void
    {
        $grupos = self::grupos(Ejecucion::de('no-cumple'));

        self::assertEqualsCanonicalizing(['SEO-03.title-presente', 'ACC-03.title-pagina'], $grupos[Causas::TITLE_AUSENTE]);
        self::assertEqualsCanonicalizing(
            ['SEO-12.imagenes-alt', 'ACC-01.img-alt-presente', 'ACC-01.input-image-alt', 'IA-10.contexto-imagenes'],
            $grupos[Causas::IMAGENES_SIN_ALT],
        );
        self::assertEqualsCanonicalizing(['IA-11.acciones-nombre-rol', 'ACC-12.nombres-controles'], $grupos[Causas::CONTROLES_SIN_NOMBRE]);
    }

    public function testCadaCausaSeDescribeUnaSolaVez(): void
    {
        $salida = Ejecucion::de('no-cumple')->salida;
        $codigos = array_map(static fn ($g) => $g->codigo, $salida->gruposCausa);

        self::assertSame(array_values(array_unique($codigos)), $codigos);
        foreach ($salida->gruposCausa as $g) {
            self::assertSame(Causas::descripcion($g->codigo), $g->descripcion);
        }
    }

    public function testUnaPaginaQueCumpleNoTieneIncidenciasAgrupadas(): void
    {
        $grupos = self::grupos(Ejecucion::de('cumple'));

        // Solo quedan límites del análisis, que no son incidencias de la web: el alcance (URL
        // individual) y que el ejemplo no trae datos de PageSpeed.
        self::assertEqualsCanonicalizing([Causas::ALCANCE_INSUFICIENTE, Causas::PAGESPEED_SIN_DATOS], array_keys($grupos));
    }

    public function testSiNoSePuedeRecuperarLaPaginaTodoLoQueDependeDeEllaVaJunto(): void
    {
        $e = Ejecucion::de('bloqueada-403');
        $grupos = self::grupos($e);

        self::assertContains('IA-03.bloqueo-acceso', $grupos[Causas::PAGINA_NO_RECUPERADA]);
        self::assertContains('SEO-01.estado-http', $grupos[Causas::PAGINA_NO_RECUPERADA]);
        self::assertContains('ACC-01.img-alt-presente', $grupos[Causas::PAGINA_NO_RECUPERADA]);
        self::assertGreaterThan(50, count($grupos[Causas::PAGINA_NO_RECUPERADA]));
    }

    public function testConLaPaginaDisponibleUnaRedireccionNoSeAgrupaComoPaginaNoRecuperada(): void
    {
        $e = Ejecucion::de('redireccion-cadena');

        self::assertSame('problema', $e->estado('SEO-02.cadena-redirecciones'));
        self::assertNull($e->resultado('SEO-02.cadena-redirecciones')->codigoGrupoCausa);
    }

    public function testRobotsQueBloqueaTodoEsUnaSolaCausaParaIaYSeo(): void
    {
        $e = Ejecucion::de('cumple', ['https://www.cumple.example/robots.txt' => 'robots-bloquea-todo']);
        $grupos = self::grupos($e);

        self::assertEqualsCanonicalizing(
            ['IA-01.permiso-buscadores', 'IA-01.permiso-buscadores-ia', 'SEO-01.rastreable', 'SEO-01.noindex-desarrollo'],
            $grupos[Causas::ROBOTS_BLOQUEA],
        );
    }

    public function testUnNoindexDeDesarrolloSeAgrupaConLasDirectivasNoConRobots(): void
    {
        $grupos = self::grupos(Ejecucion::de('noindex-desarrollo'));

        self::assertContains('SEO-01.noindex-desarrollo', $grupos[Causas::DIRECTIVAS_NOINDEX]);
        self::assertArrayNotHasKey(Causas::ROBOTS_BLOQUEA, $grupos);
    }

    public function testUnErrorTemporalDeRobotsReuneLoQueNoSePudoComprobar(): void
    {
        $e = Ejecucion::de('cumple', ['https://www.cumple.example/robots.txt' => 'robots-503']);
        $grupos = self::grupos($e);

        self::assertContains('IA-01.robots-recuperado', $grupos[Causas::ROBOTS_ERROR_TEMPORAL]);
        self::assertContains('SEO-01.rastreable', $grupos[Causas::ROBOTS_ERROR_TEMPORAL]);
    }

    public function testUnaCausaConUnSoloResultadoNoFormaGrupo(): void
    {
        // Bloquear solo a los robots de IA afecta a un único resultado.
        $e = Ejecucion::de('cumple', ['https://www.cumple.example/robots.txt' => 'robots-bloquea-ia']);

        self::assertNotContains(Causas::ROBOTS_BLOQUEA, array_map(static fn ($g) => $g->codigo, $e->salida->gruposCausa));
        foreach ($e->salida->resultados as $r) {
            self::assertNotSame(Causas::ROBOTS_BLOQUEA, $r->codigoGrupoCausa, $r->idComprobacion);
        }
    }

    public function testAgruparNoCambiaNadaMasQueLaCausa(): void
    {
        $resultados = Ejecucion::de('no-cumple')->salida->resultados;
        $sinCausa = array_map(static fn (BorradorResultado $r) => AgrupadorCausas::conCausa($r, null), $resultados);

        [$agrupados] = (new AgrupadorCausas())->agrupar($sinCausa, true);

        foreach ($agrupados as $i => $r) {
            $antes = get_object_vars($resultados[$i]);
            $despues = get_object_vars($r);
            unset($antes['codigoGrupoCausa'], $despues['codigoGrupoCausa']);
            self::assertEquals($antes, $despues, $r->idComprobacion);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function paginasDeEjemplo(): iterable
    {
        foreach (Fixtures::nombres() as $nombre) {
            if (!str_starts_with($nombre, 'robots-') && !str_starts_with($nombre, 'sitemap-')) {
                yield $nombre => [$nombre];
            }
        }
    }

    #[DataProvider('paginasDeEjemplo')]
    public function testCadaGrupoTieneAlMenosDosResultadosYCadaResultadoSuGrupo(string $fixture): void
    {
        $salida = Ejecucion::de($fixture)->salida;
        $grupos = array_map(static fn ($g) => $g->codigo, $salida->gruposCausa);
        $cuenta = [];
        foreach ($salida->resultados as $r) {
            if ($r->codigoGrupoCausa !== null) {
                self::assertContains($r->codigoGrupoCausa, $grupos, $r->idComprobacion);
                $cuenta[$r->codigoGrupoCausa] = ($cuenta[$r->codigoGrupoCausa] ?? 0) + 1;
            }
        }
        foreach ($grupos as $codigo) {
            self::assertGreaterThanOrEqual(AgrupadorCausas::MINIMO, $cuenta[$codigo] ?? 0, $codigo);
        }
    }

    public function testLasReglasSoloNombranSubcomprobacionesDelCatalogo(): void
    {
        $catalogo = Motor::v1()->catalogo();
        foreach (Causas::REGLAS as $codigo => $regla) {
            self::assertTrue(Causas::existe($codigo), $codigo);
            foreach ($regla['miembros'] as $id) {
                self::assertNotNull($catalogo->obtener($id), "$codigo: $id no está en el catálogo");
            }
        }
    }

    public function testLosCodigosCabenEnLaBaseDeDatos(): void
    {
        foreach (Causas::DESCRIPCIONES as $codigo => $descripcion) {
            self::assertLessThanOrEqual(50, strlen($codigo), $codigo);
            self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', $codigo);
            self::assertNotSame('', trim($descripcion));
        }
    }

    public function testUnaCausaDesconocidaEsUnErrorDelCodigo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Causas::descripcion('inventada');
    }

    public function testEnElSitioCompletoUnaCausaDeUnSoloResultadoSeConserva(): void
    {
        // En «video», IA-08.canonical-declarado es el único resultado de la causa «canonical».
        $individual = Ejecucion::de('video');
        self::assertSame('mejorable', $individual->estado('IA-08.canonical-declarado'));
        self::assertNull($individual->resultado('IA-08.canonical-declarado')->codigoGrupoCausa);

        $sitio = Ejecucion::de('video', alcance: Alcance::SitioCompleto, contexto: Fixtures::contexto('video', Alcance::SitioCompleto));
        self::assertSame(Causas::CANONICAL, $sitio->resultado('IA-08.canonical-declarado')->codigoGrupoCausa);
        self::assertContains(Causas::CANONICAL, array_map(static fn ($g) => $g->codigo, $sitio->salida->gruposCausa));
        // Solo cambia la causa: el estado es el mismo.
        self::assertSame('mejorable', $sitio->estado('IA-08.canonical-declarado'));
    }

    public function testElMinimoNuncaBajaDeUno(): void
    {
        [$resultados, $grupos] = (new AgrupadorCausas())->agrupar(Ejecucion::de('no-cumple')->salida->resultados, true, 0);

        foreach ($resultados as $r) {
            if ($r->codigoGrupoCausa === null) {
                continue;
            }
            self::assertContains($r->codigoGrupoCausa, array_map(static fn ($g) => $g->codigo, $grupos));
        }
    }
}
