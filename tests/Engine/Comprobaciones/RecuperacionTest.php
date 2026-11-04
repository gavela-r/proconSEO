<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Engine\Comprobaciones;

use PHPUnit\Framework\TestCase;
use ProconSEO\Engine\Objetivo;
use ProconSEO\Shared\Contract\ContextoAnalisis;
use ProconSEO\Shared\Contract\RecursoDescargado;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\Ejecucion as EjecucionEnum;
use ProconSEO\Shared\Enum\ResultadoDescarga;
use ProconSEO\Shared\Enum\TipoProyecto;
use ProconSEO\Tests\Engine\Soporte\Ejecucion;
use ProconSEO\Tests\Engine\Soporte\Fixtures;

/**
 * Casos de las comprobaciones de recuperación (IA-01, IA-02, IA-03, SEO-01 y SEO-02) que no
 * cubren las páginas de ejemplo: para cada una, un caso que cumple, uno que no y uno bloqueado.
 */
final class RecuperacionTest extends TestCase
{
    private const URL = 'https://www.cumple.example/';
    private const ROBOTS = 'https://www.cumple.example/robots.txt';

    private static function recurso(string $url, int $estado = 200, string $cuerpo = '', array $cabeceras = [], array $redirecciones = [], ?string $urlFinal = null, ResultadoDescarga $resultado = ResultadoDescarga::Correcto): RecursoDescargado
    {
        return new RecursoDescargado(
            urlSolicitada: $url,
            urlFinal: $urlFinal ?? $url,
            resultado: $estado >= 400 && $resultado === ResultadoDescarga::Correcto ? ResultadoDescarga::ErrorHttp : $resultado,
            estadoHttp: $estado,
            redirecciones: $redirecciones,
            cabeceras: $cabeceras + ['content-type' => 'text/html; charset=utf-8'],
            tipoContenido: 'text/html',
            cuerpo: $cuerpo,
            bytes: strlen($cuerpo),
            tlsVerificado: str_starts_with($urlFinal ?? $url, 'https://'),
        );
    }

    private static function robots(string $texto, int $estado = 200): RecursoDescargado
    {
        return self::recurso(self::ROBOTS, $estado, $texto, ['content-type' => 'text/plain']);
    }

    private static function contexto(RecursoDescargado $pagina, Alcance $alcance = Alcance::UrlIndividual, ?TipoProyecto $tipo = null, ?string $objetivo = null): ContextoAnalisis
    {
        return new ContextoAnalisis(1, $alcance, $pagina, [(string) parse_url($pagina->urlFinal, PHP_URL_HOST)], $objetivo, [], $tipo);
    }

    private static function cumple(Alcance $alcance = Alcance::UrlIndividual, ?TipoProyecto $tipo = null, ?string $objetivo = null): ContextoAnalisis
    {
        return self::contexto(Fixtures::recurso('cumple'), $alcance, $tipo, $objetivo);
    }

    // ---------------------------------------------------------------- IA-01

    public function testRobotsQueBloqueaABuscadoresEsDecisionARevisarNoProblema(): void
    {
        $e = Ejecucion::de('cumple', [self::ROBOTS => self::robots("User-agent: Googlebot\nDisallow: /\n\nUser-agent: OAI-SearchBot\nDisallow: /\n")]);

        self::assertSame('decision_revisar', $e->estado('IA-01.permiso-buscadores'));
        self::assertSame('decision_revisar', $e->estado('IA-01.permiso-buscadores-ia'));
        self::assertStringContainsString('Googlebot: bloqueado', (string) $e->resultado('IA-01.permiso-buscadores')->valorObservado);
        self::assertTrue($e->resultado('IA-01.permiso-buscadores')->requiereRevisionHumana);
        self::assertSame('decision_revisar', $e->estado('SEO-01.rastreable'));
    }

    public function testBloqueoConObjetivoNoIndexarEsCorrecto(): void
    {
        $e = Ejecucion::de('cumple', [self::ROBOTS => self::robots("User-agent: *\nDisallow: /")], contexto: self::cumple(objetivo: 'Es una web de pruebas: no indexar'));

        self::assertSame('correcto', $e->estado('IA-01.permiso-buscadores'));
        self::assertSame('correcto', $e->estado('SEO-01.rastreable'));
    }

    public function testRobotsConErrorTemporal(): void
    {
        foreach (['robots-503', 'robots-429'] as $fixture) {
            $e = Ejecucion::de('cumple', [self::ROBOTS => $fixture]);

            self::assertSame('problema', $e->estado('IA-01.robots-recuperado'), $fixture);
            self::assertSame('medio', $e->resultado('IA-01.robots-recuperado')->gravedad?->value, $fixture);
            foreach (['IA-01.permiso-buscadores', 'IA-01.permiso-buscadores-ia', 'SEO-01.rastreable'] as $id) {
                self::assertSame('no_verificable', $e->estado($id), "$fixture $id");
                self::assertSame(EjecucionEnum::NoEjecutada, $e->resultado($id)->ejecucion);
            }
        }
    }

    public function testRobotsSinRespuestaNoGeneraProblemas(): void
    {
        $e = Ejecucion::de('cumple', [self::ROBOTS => self::recurso(self::ROBOTS, 0, '', [], [], null, ResultadoDescarga::TiempoAgotado)]);

        foreach (['IA-01.robots-recuperado', 'IA-01.robots-sintaxis', 'IA-01.permiso-buscadores', 'IA-01.agentes-entrenamiento'] as $id) {
            self::assertSame('no_verificable', $e->estado($id), $id);
        }
    }

    public function testRobotsConLineasNoValidasEsMejorable(): void
    {
        $e = Ejecucion::de('cumple', [self::ROBOTS => 'robots-complejo']);

        self::assertSame('mejorable', $e->estado('IA-01.robots-sintaxis'));
        self::assertNotEmpty($e->resultado('IA-01.robots-sintaxis')->codigosEvidencia);
    }

    public function testLlmsTxtSoloConContextoYSiempreInformativo(): void
    {
        $individual = Ejecucion::de('cumple');
        self::assertSame('no_verificable', $individual->estado('IA-01.llms-txt'));
        self::assertNotContains('https://www.cumple.example/llms.txt', $individual->proveedor->urlsPedidas());

        $existe = Ejecucion::de('cumple', ['https://www.cumple.example/llms.txt' => self::recurso('https://www.cumple.example/llms.txt', 200, '# Fontanería')], Alcance::UrlContexto);
        self::assertSame('correcto', $existe->estado('IA-01.llms-txt'));

        $noExiste = Ejecucion::de('cumple', ['https://www.cumple.example/llms.txt' => self::recurso('https://www.cumple.example/llms.txt', 404)], Alcance::UrlContexto);
        self::assertSame('no_aplicable', $noExiste->estado('IA-01.llms-txt'));
    }

    // ---------------------------------------------------------------- IA-02 / SEO-01 indexación

    public function testNoindexEnCabeceraYPorRobot(): void
    {
        $e = Ejecucion::de('noindex-cabecera');
        self::assertSame('decision_revisar', $e->estado('IA-02.x-robots-tag'));
        self::assertSame('correcto', $e->estado('IA-02.meta-robots'));

        $porRobot = self::recurso(self::URL, 200, Fixtures::cuerpo('paginas/cumple.html'), ['x-robots-tag' => 'googlebot: noindex, otrobot: noarchive']);
        $e2 = Ejecucion::de('cumple', contexto: self::contexto($porRobot));
        self::assertSame('decision_revisar', $e2->estado('SEO-01.indexable'));
        self::assertStringContainsString('googlebot', (string) $e2->resultado('SEO-01.indexable')->valorObservado);
    }

    public function testNoindexMasBloqueoEnRobotsEsUnaContradiccion(): void
    {
        $e = Ejecucion::de('noindex-desarrollo', ['https://www.panaderia.example/robots.txt' => 'robots-bloquea-todo']);

        self::assertSame('problema', $e->estado('IA-02.coherencia-directivas'));
        // En un Kit Digital, el noindex y el bloqueo juntos siguen siendo el noindex de desarrollo.
        self::assertSame('problema', $e->estado('SEO-01.noindex-desarrollo'));
        self::assertSame('critico', $e->resultado('SEO-01.noindex-desarrollo')->gravedad?->value);
        self::assertStringContainsString('Disallow', (string) $e->resultado('SEO-01.noindex-desarrollo')->correccion);
    }

    public function testNoindexDesarrolloSoloPorRobotsTxt(): void
    {
        $e = Ejecucion::de('cumple', [self::ROBOTS => 'robots-bloquea-todo'], contexto: self::cumple(tipo: TipoProyecto::WebKitDigital));

        self::assertSame('problema', $e->estado('SEO-01.noindex-desarrollo'));
        self::assertStringContainsString('robots.txt', (string) $e->resultado('SEO-01.noindex-desarrollo')->valorObservado);
    }

    public function testNoindexDesarrolloSinRobotsNoSePuedeDarPorBueno(): void
    {
        $e = Ejecucion::de('cumple', [self::ROBOTS => 'robots-503'], contexto: self::cumple(tipo: TipoProyecto::WebKitDigital));

        self::assertSame('no_verificable', $e->estado('SEO-01.noindex-desarrollo'));
    }

    public function testFragmentosRestringidos(): void
    {
        $html = '<html lang="es"><head><title>T</title><meta name="robots" content="max-snippet:0"></head><body><p data-nosnippet>Precio</p></body></html>';
        $e = Ejecucion::de('cumple', contexto: self::contexto(self::recurso(self::URL, 200, $html)));

        self::assertSame('decision_revisar', $e->estado('IA-02.fragmentos'));
        self::assertStringContainsString('data-nosnippet', (string) $e->resultado('IA-02.fragmentos')->valorObservado);
        self::assertSame('correcto', $e->estado('IA-02.meta-robots'), 'max-snippet no es noindex');
    }

    public function testObjetivoNoIndexar(): void
    {
        self::assertTrue(Objetivo::pideNoIndexar('no_indexar'));
        self::assertTrue(Objetivo::pideNoIndexar('Web en pruebas, que NO aparezca en Google'));
        self::assertTrue(Objetivo::pideNoIndexar('Intranet: no indexar'));
        self::assertFalse(Objetivo::pideNoIndexar('Aparecer en búsquedas locales de León'));
        self::assertFalse(Objetivo::pideNoIndexar(null));
    }

    // ---------------------------------------------------------------- IA-03 / SEO-01 estado

    public function testErrorDelServidor(): void
    {
        $e = Ejecucion::de('cumple', contexto: self::contexto(self::recurso(self::URL, 500, '<html><title>Error</title></html>')));

        self::assertSame('problema', $e->estado('SEO-01.estado-http'));
        self::assertSame('alto', $e->resultado('SEO-01.estado-http')->gravedad?->value);
        self::assertSame('problema', $e->estado('IA-03.respuesta-http'));
        self::assertSame('no_verificable', $e->estado('SEO-03.title-presente'));
        self::assertSame('no_verificable', $e->estado('SEO-01.indexable'));
    }

    public function testMuroAntiRobotsConRespuesta200(): void
    {
        $html = '<html><head><title>Just a moment...</title></head><body><div id="cf-challenge"></div></body></html>';
        $e = Ejecucion::de('cumple', contexto: self::contexto(self::recurso(self::URL, 200, $html)));

        self::assertSame('decision_revisar', $e->estado('IA-03.bloqueo-acceso'));
        self::assertSame('inferencia', $e->resultado('IA-03.bloqueo-acceso')->tipoConclusion->value);
    }

    public function testUnRecaptchaEnUnFormularioNoEsUnMuro(): void
    {
        $html = '<html lang="es"><body><form action="/enviar"><div class="g-recaptcha"></div></form></body></html>';
        $e = Ejecucion::de('cumple', contexto: self::contexto(self::recurso(self::URL, 200, $html)));

        self::assertSame('correcto', $e->estado('IA-03.bloqueo-acceso'));
    }

    public function testRespuestaQueNoEsHtml(): void
    {
        $pdf = new RecursoDescargado(self::URL . 'tarifas.pdf', self::URL . 'tarifas.pdf', ResultadoDescarga::Correcto, 200, [], ['content-type' => 'application/pdf'], 'application/pdf', '%PDF-1.7', bytes: 8, tlsVerificado: true);
        $e = Ejecucion::de('cumple', contexto: self::contexto($pdf));

        self::assertSame('no_aplicable', $e->estado('IA-03.respuesta-http'));
    }

    public function testAplicacionJavascriptNoSeDaPorVacia(): void
    {
        $e = Ejecucion::de('aplicacion-js');

        self::assertSame('no_verificable', $e->estado('IA-03.contenido-html-inicial'));
        self::assertStringContainsString('JavaScript', (string) $e->resultado('IA-03.contenido-html-inicial')->limitacion);
    }

    public function testSoft404(): void
    {
        $e = Ejecucion::de('soft-404');

        self::assertSame('decision_revisar', $e->estado('SEO-01.soft-404'));
        self::assertSame('inferencia', $e->resultado('SEO-01.soft-404')->tipoConclusion->value);
        self::assertNull($e->resultado('SEO-01.soft-404')->gravedad);
    }

    // ---------------------------------------------------------------- SEO-01 HTTPS, mixto, formularios

    public function testPaginaServidaPorHttp(): void
    {
        $http = self::recurso('http://www.cumple.example/', 200, Fixtures::cuerpo('paginas/cumple.html'));
        $e = Ejecucion::de('cumple', contexto: self::contexto($http));

        self::assertSame('problema', $e->estado('SEO-01.https'));
        self::assertSame('no_aplicable', $e->estado('SEO-01.contenido-mixto'));
    }

    public function testVersionHttpQueNoRedirige(): void
    {
        $e = Ejecucion::de('cumple', ['http://www.cumple.example/' => self::recurso('http://www.cumple.example/', 200, '<html></html>')], Alcance::UrlContexto);

        self::assertSame('problema', $e->estado('SEO-01.https'));
        self::assertSame('medio', $e->resultado('SEO-01.https')->gravedad?->value);
    }

    public function testVersionHttpQueRedirige(): void
    {
        $redirigida = self::recurso('http://www.cumple.example/', 200, '', [], [['url' => 'http://www.cumple.example/', 'estado' => 301, 'destino' => self::URL]], self::URL);
        $e = Ejecucion::de('cumple', ['http://www.cumple.example/' => $redirigida], Alcance::UrlContexto);

        self::assertSame('correcto', $e->estado('SEO-01.https'));
        self::assertNull($e->resultado('SEO-01.https')->limitacion);
    }

    public function testHttpsSinContextoEsCorrectoConLimitacion(): void
    {
        $e = Ejecucion::de('cumple');

        self::assertSame('correcto', $e->estado('SEO-01.https'));
        self::assertStringContainsString('URL con contexto', (string) $e->resultado('SEO-01.https')->limitacion);
    }

    public function testSoloImagenesPorHttpEsGravedadMedia(): void
    {
        $html = '<html lang="es"><body><img src="http://www.cumple.example/a.jpg" alt="A"></body></html>';
        $e = Ejecucion::de('cumple', contexto: self::contexto(self::recurso(self::URL, 200, $html)));

        self::assertSame('medio', $e->resultado('SEO-01.contenido-mixto')->gravedad?->value);
    }

    public function testFormularioConActionRelativoEnPaginaHttp(): void
    {
        $html = '<html><body><form action="/enviar" method="post"><input name="a"></form></body></html>';
        $e = Ejecucion::de('cumple', contexto: self::contexto(self::recurso('http://www.cumple.example/', 200, $html)));

        self::assertSame('problema', $e->estado('SEO-01.formulario-sin-cifrar'));
    }

    // ---------------------------------------------------------------- CMS

    public function testDeteccionDeCms(): void
    {
        $woo = Ejecucion::de('woocommerce-producto');
        self::assertSame('correcto', $woo->estado('SEO-01.cms-detectado'));
        self::assertStringContainsString('WooCommerce', (string) $woo->resultado('SEO-01.cms-detectado')->valorObservado);
        self::assertStringContainsString('WordPress', (string) $woo->resultado('SEO-01.cms-detectado')->valorObservado);

        $ps = Ejecucion::de('prestashop-producto');
        self::assertStringContainsString('PrestaShop', (string) $ps->resultado('SEO-01.cms-detectado')->valorObservado);

        self::assertSame('no_aplicable', Ejecucion::de('gasolinera')->estado('SEO-01.cms-detectado'));
    }

    public function testLaCorreccionEnElCmsSoloAparecesiSeDetecta(): void
    {
        $wp = Ejecucion::de('noindex-desarrollo');
        self::assertStringStartsWith('WordPress:', (string) $wp->resultado('SEO-01.noindex-desarrollo')->correccionCms);

        $sinCms = Ejecucion::de('no-cumple');
        self::assertNull($sinCms->resultado('SEO-01.contenido-mixto')->correccionCms);
    }

    // ---------------------------------------------------------------- SEO-02

    public function testBucleDeRedirecciones(): void
    {
        $bucle = self::recurso('https://www.cumple.example/a', 301, '', [], [
            ['url' => 'https://www.cumple.example/a', 'estado' => 301, 'destino' => 'https://www.cumple.example/b'],
            ['url' => 'https://www.cumple.example/b', 'estado' => 301, 'destino' => 'https://www.cumple.example/a'],
        ], null, ResultadoDescarga::ErrorHttp);
        $e = Ejecucion::de('cumple', contexto: self::contexto($bucle));

        self::assertSame('problema', $e->estado('SEO-02.bucle-redirecciones'));
        self::assertSame('critico', $e->resultado('SEO-02.bucle-redirecciones')->gravedad?->value);
    }

    public function testRedireccionTemporalEsDecisionARevisar(): void
    {
        $temporal = self::recurso('https://www.cumple.example/oferta', 200, Fixtures::cuerpo('paginas/cumple.html'), [], [
            ['url' => 'https://www.cumple.example/oferta', 'estado' => 302, 'destino' => self::URL],
        ], self::URL);
        $e = Ejecucion::de('cumple', contexto: self::contexto($temporal));

        self::assertSame('decision_revisar', $e->estado('SEO-02.cadena-redirecciones'));
    }

    public function testRedireccionFueraDelAmbito(): void
    {
        $e = Ejecucion::de('redireccion-fuera-ambito');

        self::assertSame('decision_revisar', $e->estado('SEO-02.url-final'));
    }

    public function testCanonicalAOtraUrl(): void
    {
        $html = '<html lang="es"><head><link rel="canonical" href="https://www.cumple.example/principal/"></head><body></body></html>';
        $pagina = self::contexto(self::recurso('https://www.cumple.example/copia/', 200, $html), Alcance::UrlContexto);

        $sinContexto = Ejecucion::de('cumple', contexto: self::contexto(self::recurso('https://www.cumple.example/copia/', 200, $html)));
        self::assertSame('no_verificable', $sinContexto->estado('SEO-02.canonical-coherente'));

        $redirige = Ejecucion::de('cumple', ['https://www.cumple.example/principal/' => self::recurso('https://www.cumple.example/principal/', 200, '', [], [
            ['url' => 'https://www.cumple.example/principal/', 'estado' => 301, 'destino' => self::URL],
        ], self::URL)], contexto: $pagina);
        self::assertSame('problema', $redirige->estado('SEO-02.canonical-coherente'));

        $roto = Ejecucion::de('cumple', ['https://www.cumple.example/principal/' => self::recurso('https://www.cumple.example/principal/', 404)], contexto: $pagina);
        self::assertSame('problema', $roto->estado('SEO-02.canonical-coherente'));

        $bien = Ejecucion::de('cumple', ['https://www.cumple.example/principal/' => self::recurso('https://www.cumple.example/principal/', 200, '<html></html>')], contexto: $pagina);
        self::assertSame('correcto', $bien->estado('SEO-02.canonical-coherente'));
    }

    public function testVariantesDeLaUrl(): void
    {
        $redirige = static fn (string $u): RecursoDescargado => self::recurso($u, 200, '', [], [['url' => $u, 'estado' => 301, 'destino' => self::URL]], self::URL);

        $bien = Ejecucion::de('cumple', [
            'http://www.cumple.example/' => $redirige('http://www.cumple.example/'),
            'http://cumple.example/' => $redirige('http://cumple.example/'),
            'https://cumple.example/' => $redirige('https://cumple.example/'),
        ], Alcance::UrlContexto);
        self::assertSame('correcto', $bien->estado('SEO-02.variantes-host'));

        $duplicada = Ejecucion::de('cumple', [
            'http://www.cumple.example/' => $redirige('http://www.cumple.example/'),
            'https://cumple.example/' => self::recurso('https://cumple.example/', 200, '<html></html>'),
        ], Alcance::UrlContexto);
        self::assertSame('mejorable', $duplicada->estado('SEO-02.variantes-host'));

        // Sin ninguna variante consultable (otro host fuera del ámbito), no se puede afirmar nada.
        self::assertSame('no_verificable', Ejecucion::de('cumple', [], Alcance::UrlContexto)->estado('SEO-02.variantes-host'));
    }
}
