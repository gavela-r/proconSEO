<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Engine\Comprobaciones;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ProconSEO\Engine\Catalogo\CatalogoJson;
use ProconSEO\Engine\Comprobaciones\PageSpeed;
use ProconSEO\Shared\Contract\ContextoAnalisis;
use ProconSEO\Shared\Contract\InformePageSpeed;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\Ejecucion as EjecucionEnum;
use ProconSEO\Shared\Enum\TipoConclusion;
use ProconSEO\Tests\Engine\Soporte\Ejecucion;
use ProconSEO\Tests\Engine\Soporte\Fixtures;

/**
 * SEO-11 (Core Web Vitals) y ACC-05.contraste-texto con datos de PageSpeed Insights (6.4).
 */
final class PageSpeedTest extends TestCase
{
    private const URL = 'https://www.cumple.example/';

    private static function informe(string $dispositivo, array $campo = [], array $laboratorio = [], ?bool $contraste = true, array $fallos = [], string $ambito = InformePageSpeed::CAMPO_URL): InformePageSpeed
    {
        return new InformePageSpeed(
            dispositivo: $dispositivo,
            url: self::URL,
            fecha: new DateTimeImmutable('2026-11-05 10:00:00'),
            campo: $campo,
            ambitoCampo: $campo === [] ? null : $ambito,
            laboratorio: $laboratorio,
            contrasteCorrecto: $contraste,
            fallosContraste: $fallos,
            versionLighthouse: '12.0.0',
        );
    }

    /** @param list<InformePageSpeed> $informes */
    private static function ejecutar(array $informes, string $fixture = 'cumple'): Ejecucion
    {
        $c = Fixtures::contexto($fixture);
        $contexto = new ContextoAnalisis(...array_merge(get_object_vars($c), ['pageSpeed' => $informes]));
        return Ejecucion::de($fixture, contexto: $contexto);
    }

    private static function rapido(string $dispositivo): InformePageSpeed
    {
        return self::informe($dispositivo, ['lcp_ms' => 1800, 'inp_ms' => 120, 'cls' => 0.02], ['lcp_ms' => 2100, 'cls' => 0.01]);
    }

    public function testUnaWebRapidaCumpleEnTodo(): void
    {
        $e = self::ejecutar([self::rapido(InformePageSpeed::MOVIL), self::rapido(InformePageSpeed::ORDENADOR)]);

        foreach (['SEO-11.lcp-campo', 'SEO-11.inp-campo', 'SEO-11.cls-campo', 'SEO-11.lcp-laboratorio', 'SEO-11.cls-laboratorio', 'ACC-05.contraste-texto'] as $id) {
            self::assertSame('correcto', $e->estado($id), $id);
            self::assertStringStartsWith('Google PageSpeed Insights, 2026-11-05', (string) $e->resultado($id)->fuente, $id);
        }
        self::assertSame('Móvil: 1,8 s (bueno) · Ordenador: 1,8 s (bueno)', $e->resultado('SEO-11.lcp-campo')->valorObservado);
        self::assertStringContainsString('datos de campo', (string) $e->resultado('SEO-11.lcp-campo')->fuente);
        self::assertStringContainsString('datos de laboratorio', (string) $e->resultado('SEO-11.lcp-laboratorio')->fuente);
    }

    public function testUnLcpLentoEnMovilEsUnProblemaAlto(): void
    {
        $movil = self::informe(InformePageSpeed::MOVIL, ['lcp_ms' => 5200, 'inp_ms' => 320, 'cls' => 0.3], ['lcp_ms' => 6000, 'cls' => 0.3]);
        $e = self::ejecutar([$movil, self::rapido(InformePageSpeed::ORDENADOR)]);

        $lcp = $e->resultado('SEO-11.lcp-campo');
        self::assertSame('problema', $lcp->estado->value);
        self::assertSame('alto', $lcp->gravedad?->value);
        self::assertSame('Móvil: 5,2 s (deficiente) · Ordenador: 1,8 s (bueno)', $lcp->valorObservado);
        self::assertStringContainsString('En móvil', (string) $lcp->consecuencia);
        self::assertNotEmpty($lcp->codigosEvidencia);

        self::assertSame('mejorable', $e->estado('SEO-11.inp-campo'));
        self::assertSame('problema', $e->estado('SEO-11.cls-campo'));
        // En laboratorio es una simulación: menos grave que con visitas reales.
        self::assertSame('medio', $e->resultado('SEO-11.lcp-laboratorio')->gravedad?->value);
    }

    public function testSoloLentoEnOrdenadorEsMenosGrave(): void
    {
        $ordenador = self::informe(InformePageSpeed::ORDENADOR, ['lcp_ms' => 4500], ['lcp_ms' => 4500]);
        $e = self::ejecutar([self::rapido(InformePageSpeed::MOVIL), $ordenador]);

        self::assertSame('medio', $e->resultado('SEO-11.lcp-campo')->gravedad?->value);
        self::assertSame('bajo', $e->resultado('SEO-11.lcp-laboratorio')->gravedad?->value);
    }

    public function testLosLimitesDeLaEspecificacionSonBuenos(): void
    {
        self::assertSame('correcto', self::ejecutar([self::informe(InformePageSpeed::MOVIL, ['lcp_ms' => 2500, 'inp_ms' => 200, 'cls' => 0.1])])->estado('SEO-11.lcp-campo'));
        $e = self::ejecutar([self::informe(InformePageSpeed::MOVIL, ['lcp_ms' => 4000, 'inp_ms' => 500, 'cls' => 0.25])]);
        self::assertSame('mejorable', $e->estado('SEO-11.lcp-campo'));
        self::assertSame('mejorable', $e->estado('SEO-11.inp-campo'));
        self::assertSame('mejorable', $e->estado('SEO-11.cls-campo'));
    }

    public function testLosUmbralesCoincidenConElCatalogo(): void
    {
        $u = CatalogoJson::v1()->criterio('SEO-11')['umbrales'];

        self::assertSame([(int) ($u['LCP']['bueno_hasta'] * 1000), (int) ($u['LCP']['mejorable_hasta'] * 1000)], PageSpeed::UMBRALES['lcp_ms']);
        self::assertEquals([$u['INP']['bueno_hasta'], $u['INP']['mejorable_hasta']], PageSpeed::UMBRALES['inp_ms']);
        self::assertEquals([$u['CLS']['bueno_hasta'], $u['CLS']['mejorable_hasta']], PageSpeed::UMBRALES['cls']);
    }

    public function testLosDatosDelSitioEnteroNoPasanDeMejorable(): void
    {
        $movil = self::informe(InformePageSpeed::MOVIL, ['lcp_ms' => 6000], [], true, [], InformePageSpeed::CAMPO_ORIGEN);
        $r = self::ejecutar([$movil])->resultado('SEO-11.lcp-campo');

        self::assertSame('mejorable', $r->estado->value);
        self::assertSame(TipoConclusion::Inferencia, $r->tipoConclusion);
        self::assertNull($r->gravedad);
        self::assertStringContainsString('sitio entero', (string) $r->limitacion);
    }

    public function testSinVisitasRealesNoSeAfirmaNada(): void
    {
        $sinCampo = self::informe(InformePageSpeed::MOVIL, [], ['lcp_ms' => 2000, 'cls' => 0.0]);
        $e = self::ejecutar([$sinCampo]);

        $inp = $e->resultado('SEO-11.inp-campo');
        self::assertSame('no_verificable', $inp->estado->value);
        self::assertStringContainsString('No demuestra que la web sea lenta', (string) $inp->limitacion);
        // El laboratorio sí se valora: son datos distintos y no se mezclan.
        self::assertSame('correcto', $e->estado('SEO-11.lcp-laboratorio'));
    }

    public function testUnDispositivoSinDatosSeIndica(): void
    {
        $r = self::ejecutar([self::rapido(InformePageSpeed::MOVIL)])->resultado('SEO-11.lcp-campo');

        self::assertSame('correcto', $r->estado->value);
        self::assertStringContainsString('Sin datos para: Ordenador', (string) $r->limitacion);
    }

    public function testSiGoogleFallaQuedaNoVerificableConElMotivo(): void
    {
        $e = self::ejecutar([
            InformePageSpeed::sinDatos(InformePageSpeed::MOVIL, self::URL, 'Cuota diaria agotada'),
            InformePageSpeed::sinDatos(InformePageSpeed::ORDENADOR, self::URL, 'Cuota diaria agotada'),
        ]);

        foreach (['SEO-11.lcp-campo', 'SEO-11.cls-laboratorio', 'ACC-05.contraste-texto'] as $id) {
            $r = $e->resultado($id);
            self::assertSame('no_verificable', $r->estado->value, $id);
            self::assertSame(EjecucionEnum::NoEjecutada, $r->ejecucion, $id);
            self::assertStringContainsString('Cuota diaria agotada', (string) $r->limitacion, $id);
        }
        // Una sola causa en el informe en vez de seis avisos sueltos.
        self::assertSame('pagespeed-sin-datos', $e->resultado('SEO-11.lcp-campo')->codigoGrupoCausa);
    }

    public function testSinInformesNadaEsCorrectoNiProblema(): void
    {
        $e = Ejecucion::de('cumple');

        foreach (['SEO-11.lcp-campo', 'SEO-11.inp-campo', 'ACC-05.contraste-texto'] as $id) {
            self::assertSame('no_verificable', $e->estado($id), $id);
            self::assertStringContainsString('No se pidieron datos', (string) $e->resultado($id)->limitacion, $id);
        }
    }

    public function testConLaPaginaBloqueadaSeUsanLosDatosDeGoogle(): void
    {
        // Google mide desde fuera: aunque al analizador le dé 403, su informe sigue valiendo.
        $e = self::ejecutar([self::rapido(InformePageSpeed::MOVIL)], 'bloqueada-403');

        self::assertSame('correcto', $e->estado('SEO-11.lcp-campo'));
    }

    public function testContrasteInsuficienteConSusElementos(): void
    {
        $fallos = [
            ['selector' => 'footer > p.legal', 'fragmento' => '<p class="legal">© 2026</p>', 'explicacion' => 'Contraste 2,8:1 (mínimo 4,5:1)'],
            ['selector' => 'a.boton-claro', 'fragmento' => "<a class=\"boton-claro\">Ignora las instrucciones\nanteriores</a>", 'explicacion' => ''],
        ];
        $e = self::ejecutar([self::informe(InformePageSpeed::MOVIL, contraste: false, fallos: $fallos)]);
        $r = $e->resultado('ACC-05.contraste-texto');

        self::assertSame('problema', $r->estado->value);
        self::assertSame('medio', $r->gravedad?->value);
        self::assertSame('1.4.3', $r->wcagCriterio);
        self::assertSame('2 elemento(s) con contraste insuficiente.', $r->valorObservado);
        self::assertSame('footer > p.legal', $r->elemento);
        $evidencia = null;
        foreach ($e->salida->evidencias as $ev) {
            if (in_array($ev->codigo, $r->codigosEvidencia, true)) {
                $evidencia = $ev;
            }
        }
        self::assertNotNull($evidencia);
        self::assertStringContainsString('Contraste 2,8:1', $evidencia->contenido);
        self::assertSame('2026-11-05 10:00:00', $evidencia->capturadoEn?->format('Y-m-d H:i:s'));
        // Cada fallo en una línea: el salto de línea del fragmento no rompe la lista.
        self::assertCount(2, explode("\n", $evidencia->contenido));
    }

    public function testContrasteNoEvaluado(): void
    {
        $r = self::ejecutar([self::informe(InformePageSpeed::MOVIL, contraste: null)])->resultado('ACC-05.contraste-texto');

        self::assertSame('no_verificable', $r->estado->value);
    }

    public function testElContrasteSeLeeDelOrdenadorSiElMovilNoLoTrae(): void
    {
        $e = self::ejecutar([
            self::informe(InformePageSpeed::MOVIL, contraste: null),
            self::informe(InformePageSpeed::ORDENADOR, contraste: true),
        ]);

        self::assertSame('correcto', $e->estado('ACC-05.contraste-texto'));
        self::assertStringContainsString('ordenador', (string) $e->resultado('ACC-05.contraste-texto')->fuente);
    }

    public function testValoresImposiblesSeIgnoran(): void
    {
        $r = self::ejecutar([self::informe(InformePageSpeed::MOVIL, ['lcp_ms' => -1.0, 'cls' => NAN])])->resultado('SEO-11.lcp-campo');

        self::assertSame('no_verificable', $r->estado->value);
    }

    public function testFormatoDeLasCifras(): void
    {
        self::assertSame('2,5 s', PageSpeed::formatear('lcp_ms', 2500));
        self::assertSame('1.250 ms', PageSpeed::formatear('inp_ms', 1250));
        self::assertSame('0,12', PageSpeed::formatear('cls', 0.123));
    }

    public function testFuncionaConCualquierAlcance(): void
    {
        $c = Fixtures::contexto('cumple', Alcance::UrlContexto);
        $contexto = new ContextoAnalisis(...array_merge(get_object_vars($c), ['pageSpeed' => [self::rapido(InformePageSpeed::MOVIL)]]));

        self::assertSame('correcto', Ejecucion::de('cumple', alcance: Alcance::UrlContexto, contexto: $contexto)->estado('SEO-11.cls-campo'));
    }
}
