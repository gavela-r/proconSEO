<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Engine\Comprobaciones;

use PHPUnit\Framework\TestCase;
use ProconSEO\Engine\Causas;
use ProconSEO\Engine\Comprobaciones\ComprobacionesSitio;
use ProconSEO\Engine\Motor;
use ProconSEO\Shared\Contract\BorradorResultado;
use ProconSEO\Shared\Contract\ConsolidadorSitio;
use ProconSEO\Shared\Contract\ResumenPagina;
use ProconSEO\Shared\Contract\SalidaMotor;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Tests\Engine\Soporte\CancelacionFalsa;
use ProconSEO\Tests\Engine\Soporte\Ejecucion;
use ProconSEO\Tests\Engine\Soporte\Fixtures;
use ProconSEO\Tests\Engine\Soporte\ProgresoFalso;

/**
 * Comprobaciones que comparan las páginas de un sitio completo (ConsolidadorSitio).
 */
final class ComprobacionesSitioTest extends TestCase
{
    private const BASE = 'https://www.cumple.example';

    private static function pagina(string $ruta, string $titulo, ?string $descripcion = null, array $enlaces = [], ?string $canonical = null, bool $indexable = true, int $estado = 200): ResumenPagina
    {
        return new ResumenPagina(
            self::BASE . $ruta,
            $estado,
            $indexable,
            [$titulo],
            $descripcion === null ? [] : [$descripcion],
            $canonical === null ? null : self::BASE . $canonical,
            array_map(static fn (string $r): string => self::BASE . $r, $enlaces),
        );
    }

    /** @param list<ResumenPagina> $resumenes */
    private static function consolidar(array $resumenes, ?CancelacionFalsa $cancelacion = null): SalidaMotor
    {
        return Motor::v1()->consolidarSitio(Fixtures::contexto('cumple', Alcance::SitioCompleto), $resumenes, new ProgresoFalso(), $cancelacion ?? CancelacionFalsa::nunca());
    }

    private static function resultado(SalidaMotor $s, string $id): BorradorResultado
    {
        foreach ($s->resultados as $r) {
            if ($r->idComprobacion === $id) {
                return $r;
            }
        }
        self::fail("Sin resultado para $id");
    }

    /** @return list<ResumenPagina> Un sitio pequeño y bien hecho */
    private static function sitioCorrecto(): array
    {
        return [
            self::pagina('/', 'Inicio · Cumple', 'Portada', ['/servicios', '/contacto']),
            self::pagina('/servicios', 'Servicios · Cumple', 'Lo que hacemos', ['/', '/contacto']),
            self::pagina('/contacto', 'Contacto · Cumple', 'Cómo llegar', ['/']),
        ];
    }

    public function testElMotorEsConsolidadorYDeclaraSusSubcomprobaciones(): void
    {
        $motor = Motor::v1();

        self::assertInstanceOf(ConsolidadorSitio::class, $motor);
        self::assertSame(['SEO-03.title-duplicado', 'SEO-04.description-duplicada', 'IA-08.enlazado-interno', 'SEO-08.parametros-variantes'], $motor->subcomprobacionesDeSitio());
        self::assertSame(Cobertura::Implementada, $motor->coberturas()['SEO-03.title-duplicado']);
        self::assertSame(Cobertura::ParcialmenteImplementada, $motor->coberturas()['SEO-08.parametros-variantes']);
    }

    public function testDevuelveUnResultadoPorSubcomprobacionDeSitioEnOrden(): void
    {
        $s = self::consolidar(self::sitioCorrecto());

        $ids = array_map(static fn (BorradorResultado $r): string => $r->idComprobacion, $s->resultados);
        $esperados = Motor::v1()->subcomprobacionesDeSitio();
        sort($esperados);
        $ordenados = $ids;
        sort($ordenados);
        self::assertSame($esperados, $ordenados);
        foreach ($s->resultados as $r) {
            self::assertStringNotContainsString('Error interno', (string) $r->limitacion, $r->idComprobacion);
        }
    }

    public function testUnSitioBienHechoCumple(): void
    {
        $s = self::consolidar(self::sitioCorrecto());

        self::assertSame('correcto', self::resultado($s, 'SEO-03.title-duplicado')->estado->value);
        self::assertSame('correcto', self::resultado($s, 'SEO-04.description-duplicada')->estado->value);
        self::assertSame('correcto', self::resultado($s, 'IA-08.enlazado-interno')->estado->value);
        self::assertSame('no_aplicable', self::resultado($s, 'SEO-08.parametros-variantes')->estado->value);
    }

    public function testTitleRepetido(): void
    {
        $s = self::consolidar([
            self::pagina('/', 'Inicio', null, ['/a', '/b', '/c']),
            self::pagina('/a', 'Mi tienda', null, ['/']),
            self::pagina('/b', '  mi   TIENDA ', null, ['/']),
            self::pagina('/c', 'Mi tienda', null, ['/']),
        ]);
        $r = self::resultado($s, 'SEO-03.title-duplicado');

        self::assertSame('mejorable', $r->estado->value);
        self::assertSame('1 title repetido(s) en 3 de 4 páginas.', $r->valorObservado);
        self::assertSame('Mi tienda', $r->elemento);
        $evidencia = $s->evidencias[0]->contenido;
        self::assertStringContainsString('«Mi tienda» (3 páginas)', $evidencia);
        self::assertStringContainsString(self::BASE . '/b', $evidencia);
    }

    public function testDescriptionRepetidaYPaginasSinDescription(): void
    {
        $r = self::resultado(self::consolidar([
            self::pagina('/', 'A', 'Igual', ['/b', '/c']),
            self::pagina('/b', 'B', 'Igual', ['/']),
            self::pagina('/c', 'C', null, ['/']),
        ]), 'SEO-04.description-duplicada');

        self::assertSame('mejorable', $r->estado->value);
        self::assertStringContainsString('1 página(s) sin description', (string) $r->limitacion);
    }

    public function testNoSeComparanCopiasDeclaradasNiPaginasNoIndexablesNiErrores(): void
    {
        $s = self::consolidar([
            self::pagina('/', 'Igual', null, ['/copia', '/privada', '/rota']),
            self::pagina('/copia', 'Igual', null, ['/'], canonical: '/'),
            self::pagina('/privada', 'Igual', null, ['/'], indexable: false),
            self::pagina('/rota', 'Igual', null, ['/'], estado: 404),
            self::pagina('/otra', 'Distinta', null, ['/']),
        ]);

        self::assertSame('correcto', self::resultado($s, 'SEO-03.title-duplicado')->estado->value);
    }

    public function testConUnaSolaPaginaNoSePuedeComparar(): void
    {
        $r = self::resultado(self::consolidar([self::pagina('/', 'Inicio')]), 'SEO-03.title-duplicado');

        self::assertSame('no_verificable', $r->estado->value);
        self::assertStringContainsString('al menos dos páginas', (string) $r->limitacion);
    }

    public function testPaginasSinEnlacesInternos(): void
    {
        $s = self::consolidar([
            self::pagina('/', 'Inicio', null, ['/servicios/']),
            self::pagina('/servicios', 'Servicios', null, ['/']),
            self::pagina('/solo-en-sitemap', 'Escondida', null, ['/']),
        ]);
        $r = self::resultado($s, 'IA-08.enlazado-interno');

        self::assertSame('mejorable', $r->estado->value);
        self::assertSame(self::BASE . '/solo-en-sitemap', $r->elemento);
        self::assertStringStartsWith('1 de 3', (string) $r->valorObservado);
    }

    public function testLaPaginaDeInicioYLosEnlacesASiMismaNoCuentan(): void
    {
        $s = self::consolidar([
            self::pagina('/', 'Inicio', null, ['/a']),
            self::pagina('/a', 'A', null, ['/a']),
        ]);

        self::assertSame('correcto', self::resultado($s, 'IA-08.enlazado-interno')->estado->value);
        $s = self::consolidar([
            self::pagina('/', 'Inicio', null, []),
            self::pagina('/a', 'A', null, ['/a']),
        ]);
        self::assertSame('mejorable', self::resultado($s, 'IA-08.enlazado-interno')->estado->value);
    }

    public function testVariantesConParametrosSinCanonical(): void
    {
        $s = self::consolidar([
            self::pagina('/', 'Inicio', null, ['/zapatos', '/zapatos?utm_source=x']),
            self::pagina('/zapatos', 'Zapatos', null, ['/']),
            self::pagina('/zapatos?utm_source=x', 'Zapatos', null, ['/']),
            self::pagina('/bolsos?orden=precio', 'Bolsos', null, ['/'], canonical: '/bolsos'),
            self::pagina('/bolsos', 'Bolsos', null, ['/']),
        ]);
        $r = self::resultado($s, 'SEO-08.parametros-variantes');

        self::assertSame('mejorable', $r->estado->value);
        self::assertSame(self::BASE . '/zapatos?utm_source=x', $r->elemento);
        self::assertStringStartsWith('1 URL(s)', (string) $r->valorObservado);
    }

    public function testVariantesConCanonicalCumplen(): void
    {
        $r = self::resultado(self::consolidar([
            self::pagina('/', 'Inicio', null, ['/bolsos']),
            self::pagina('/bolsos', 'Bolsos', null, ['/']),
            self::pagina('/bolsos?orden=precio', 'Bolsos', null, ['/'], canonical: '/bolsos'),
        ]), 'SEO-08.parametros-variantes');

        self::assertSame('correcto', $r->estado->value);
    }

    public function testNormalizaLasUrlsParaCompararlas(): void
    {
        self::assertSame('https://www.x.example/a', ComprobacionesSitio::normalizar('HTTPS://WWW.X.EXAMPLE:443/a/#arriba'));
        self::assertSame('https://www.x.example/', ComprobacionesSitio::normalizar('https://www.x.example'));
        self::assertSame('http://x.example:8080/a?b=1', ComprobacionesSitio::normalizar('http://x.example:8080/a/?b=1'));
    }

    public function testSiSeCancelaQuedanNoEjecutadas(): void
    {
        $s = self::consolidar(self::sitioCorrecto(), CancelacionFalsa::yaCancelado());

        foreach ($s->resultados as $r) {
            self::assertSame('no_verificable', $r->estado->value, $r->idComprobacion);
        }
    }

    public function testEnCadaPaginaSeDejanParaElFinal(): void
    {
        $sitio = Ejecucion::de('cumple', alcance: Alcance::SitioCompleto, contexto: Fixtures::contexto('cumple', Alcance::SitioCompleto));
        $individual = Ejecucion::de('cumple');

        foreach (Motor::v1()->subcomprobacionesDeSitio() as $id) {
            self::assertSame('no_verificable', $sitio->estado($id), $id);
            self::assertStringContainsString('todo el sitio', (string) $sitio->resultado($id)->limitacion, $id);
            self::assertSame('no_verificable', $individual->estado($id), $id);
            self::assertSame(Causas::ALCANCE_INSUFICIENTE, $individual->resultado($id)->codigoGrupoCausa, $id);
        }
    }

    public function testCadaPaginaDevuelveSuResumen(): void
    {
        $resumen = Ejecucion::de('cumple')->salida->resumen;

        self::assertNotNull($resumen);
        self::assertSame(Fixtures::recurso('cumple')->urlFinal, $resumen->url);
        self::assertSame(200, $resumen->estadoHttp);
        self::assertTrue($resumen->indexable);
        self::assertNotSame([], $resumen->titulos);
        self::assertLessThanOrEqual(ResumenPagina::MAXIMO_ENLACES, count($resumen->enlacesInternos));
        foreach ($resumen->enlacesInternos as $url) {
            self::assertStringNotContainsString('#', $url);
        }

        self::assertFalse(Ejecucion::de('noindex-otra')->salida->resumen?->indexable);
        self::assertNull(Ejecucion::de('bloqueada-403')->salida->resumen);
        self::assertNull(Ejecucion::de('sin-respuesta')->salida->resumen);
    }

    public function testDeLasPaginasDeEjemploAlResultadoDelSitio(): void
    {
        $resumenes = [];
        foreach (['cumple', 'no-cumple', 'video', 'gasolinera'] as $f) {
            $resumenes[] = Ejecucion::de($f)->salida->resumen;
        }
        $s = self::consolidar(array_values(array_filter($resumenes)));

        self::assertCount(count(Motor::v1()->subcomprobacionesDeSitio()), $s->resultados);
    }
}
