<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Engine\Comprobaciones;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProconSEO\Engine\Comprobaciones\Idiomas;
use ProconSEO\Shared\Contract\ContextoAnalisis;
use ProconSEO\Shared\Contract\RecursoDescargado;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\Ejecucion as EjecucionEnum;
use ProconSEO\Shared\Enum\ResultadoDescarga;
use ProconSEO\Shared\Enum\Sector;
use ProconSEO\Shared\Enum\TipoProyecto;
use ProconSEO\Tests\Engine\Soporte\Ejecucion;

/**
 * Comprobaciones del contenido de la página (paso 4b): SEO-03/04/05/07/08/10/12/14/15,
 * IA-07/08/10/11. Para cada una, un caso que cumple, uno que no y uno con la página bloqueada.
 */
final class ContenidoPaginaTest extends TestCase
{
    private const URL = 'https://www.x.example/';

    private static function pagina(string $cuerpo, string $url = self::URL, int $estado = 200): RecursoDescargado
    {
        return new RecursoDescargado($url, $url, $estado >= 400 ? ResultadoDescarga::ErrorHttp : ResultadoDescarga::Correcto, $estado, [],
            ['content-type' => 'text/html; charset=utf-8'], 'text/html', $cuerpo, bytes: strlen($cuerpo), tlsVerificado: true);
    }

    private static function html(string $head = '', string $body = '', string $lang = 'es'): string
    {
        return '<!DOCTYPE html><html lang="' . $lang . '"><head><meta charset="utf-8">' . $head . '</head><body>' . $body . '</body></html>';
    }

    /** @param array<string, string|RecursoDescargado> $otras */
    private static function ejecutar(string $cuerpo, array $otras = [], Alcance $alcance = Alcance::UrlIndividual, ?TipoProyecto $tipo = null, ?Sector $sector = null, string $url = self::URL): Ejecucion
    {
        $contexto = new ContextoAnalisis(1, $alcance, self::pagina($cuerpo, $url), [(string) parse_url($url, PHP_URL_HOST)], null, [], $tipo, $sector);
        return Ejecucion::de('cumple', $otras, contexto: $contexto);
    }

    // ------------------------------------------------------------ página bloqueada

    /** @return iterable<string, array{string}> */
    public static function subcomprobacionesDelPaso4b(): iterable
    {
        foreach ([
            'SEO-03.title-presente', 'SEO-03.title-unico', 'SEO-03.title-longitud', 'SEO-04.description-presente',
            'SEO-04.description-unica', 'SEO-05.h1-presente', 'SEO-05.estructura-encabezados', 'SEO-05.encabezados-vacios',
            'SEO-07.enlaces-rastreables', 'SEO-07.textos-ambiguos', 'SEO-08.contradiccion-tecnica', 'SEO-10.viewport',
            'SEO-12.imagenes-rastreables', 'SEO-12.imagenes-alt', 'SEO-12.imagenes-formato', 'SEO-12.video-detectado',
            'SEO-12.video-miniatura', 'SEO-12.video-datos', 'SEO-14.elegibilidad-enriquecidos', 'SEO-14.producto-marcado',
            'SEO-15.hreflang-codigos', 'SEO-15.hreflang-autorreferencia', 'IA-07.jsonld-sintaxis', 'IA-07.microdata-rdfa',
            'IA-07.tipos-schema', 'IA-07.negocio-local', 'IA-08.canonical-declarado', 'IA-10.contexto-imagenes',
            'IA-10.contexto-video', 'IA-10.horarios-texto', 'IA-11.acciones-nombre-rol',
        ] as $id) {
            yield $id => [$id];
        }
    }

    #[DataProvider('subcomprobacionesDelPaso4b')]
    public function testConLaPaginaBloqueadaNadaSeValora(string $id): void
    {
        foreach (['bloqueada-403', 'sin-respuesta', 'certificado-invalido'] as $fixture) {
            $r = Ejecucion::de($fixture)->resultado($id);
            self::assertSame('no_verificable', $r->estado->value, "$fixture: $id");
            self::assertSame(EjecucionEnum::NoEjecutada, $r->ejecucion, "$fixture: $id");
        }
    }

    // ------------------------------------------------------------ SEO-03 / SEO-04

    public function testTitle(): void
    {
        $bien = self::ejecutar(self::html('<title>Clínica dental en León</title>'));
        self::assertSame('correcto', $bien->estado('SEO-03.title-presente'));
        self::assertStringContainsString('title-descriptivo', (string) $bien->resultado('SEO-03.title-presente')->limitacion, 'La presencia no es calidad');
        self::assertSame('no_verificable', $bien->estado('SEO-03.title-descriptivo'));

        $vacio = self::ejecutar(self::html('<title>   </title>'));
        self::assertSame('problema', $vacio->estado('SEO-03.title-presente'));

        $doble = self::ejecutar(self::html('<title>Uno</title><title>Dos</title>'));
        self::assertSame('mejorable', $doble->estado('SEO-03.title-unico'));

        $largo = self::ejecutar(self::html('<title>' . str_repeat('Muy largo ', 20) . '</title>'));
        self::assertSame('correcto', $largo->estado('SEO-03.title-longitud'), 'La longitud nunca es un problema');
    }

    public function testMetaDescription(): void
    {
        self::assertSame('correcto', self::ejecutar(self::html('<meta name="description" content="Resumen">'))->estado('SEO-04.description-presente'));
        self::assertSame('mejorable', self::ejecutar(self::html())->estado('SEO-04.description-presente'));
        self::assertSame('no_aplicable', self::ejecutar(self::html())->estado('SEO-04.description-unica'));
        self::assertSame('mejorable', self::ejecutar(self::html('<meta name="description" content="A"><meta name="description" content="B">'))->estado('SEO-04.description-unica'));
    }

    // ------------------------------------------------------------ SEO-05 / SEO-10

    public function testEncabezados(): void
    {
        $bien = self::ejecutar(self::html('', '<h1>Inicio</h1><h2>Servicios</h2><h3>Fugas</h3>'));
        self::assertSame('correcto', $bien->estado('SEO-05.h1-presente'));
        self::assertNull($bien->resultado('SEO-05.estructura-encabezados')->limitacion);

        $saltos = self::ejecutar(self::html('', '<h1>A</h1><h1>B</h1><h4>C</h4>'));
        self::assertSame('correcto', $saltos->estado('SEO-05.estructura-encabezados'), 'Varios H1 o saltos no son errores automáticos');
        self::assertStringContainsString('salto de h1 a h4', (string) $saltos->resultado('SEO-05.estructura-encabezados')->valorObservado);

        $sinH1 = self::ejecutar(self::html('', '<h2>Servicios</h2><h3></h3>'));
        self::assertSame('problema', $sinH1->estado('SEO-05.h1-presente'));
        self::assertSame('bajo', $sinH1->resultado('SEO-05.h1-presente')->gravedad?->value);
        self::assertSame('problema', $sinH1->estado('SEO-05.encabezados-vacios'));

        $ninguno = self::ejecutar(self::html('', '<p>Texto</p>'));
        self::assertSame('mejorable', $ninguno->estado('SEO-05.estructura-encabezados'));
        self::assertSame('no_aplicable', $ninguno->estado('SEO-05.encabezados-vacios'));
    }

    public function testViewport(): void
    {
        self::assertSame('correcto', self::ejecutar(self::html('<meta name="viewport" content="width=device-width, initial-scale=1">'))->estado('SEO-10.viewport'));
        self::assertSame('problema', self::ejecutar(self::html('<meta name="viewport" content="width=1024">'))->estado('SEO-10.viewport'));
        $sin = self::ejecutar(self::html());
        self::assertSame('problema', $sin->estado('SEO-10.viewport'));
        self::assertSame('alto', $sin->resultado('SEO-10.viewport')->gravedad?->value);
    }

    public function testEnUnaAplicacionJavascriptNoSeAfirmaQueFalteNada(): void
    {
        $e = Ejecucion::de('aplicacion-js');
        foreach (['SEO-05.h1-presente', 'SEO-05.estructura-encabezados', 'SEO-04.description-presente'] as $id) {
            self::assertSame('no_verificable', $e->estado($id), $id);
            self::assertStringContainsString('JavaScript', (string) $e->resultado($id)->limitacion, $id);
        }
    }

    // ------------------------------------------------------------ SEO-07 / SEO-08

    public function testEnlacesRastreablesYAmbiguos(): void
    {
        $bien = self::ejecutar(self::html('', '<a href="/servicios/">Nuestros servicios</a><a href="mailto:a@x.example">Escríbenos</a>'));
        self::assertSame('correcto', $bien->estado('SEO-07.enlaces-rastreables'), 'mailto: no es un enlace no rastreable');
        self::assertSame('correcto', $bien->estado('SEO-07.textos-ambiguos'));

        $mal = self::ejecutar(self::html('', '<a href="javascript:ir()">Servicios</a><a href="/blog/">Leer más…</a><a href="/b/" aria-label="Leer más sobre calderas">Leer más</a>'));
        self::assertSame('problema', $mal->estado('SEO-07.enlaces-rastreables'));
        self::assertSame('mejorable', $mal->estado('SEO-07.textos-ambiguos'));
        self::assertStringContainsString('1 enlace', (string) $mal->resultado('SEO-07.textos-ambiguos')->valorObservado, 'Un aria-label descriptivo da contexto');

        self::assertSame('no_aplicable', self::ejecutar(self::html('', '<p>Sin enlaces</p>'))->estado('SEO-07.enlaces-rastreables'));
    }

    public function testEnlacesRotosConContexto(): void
    {
        $cuerpo = self::html('', '<a href="/a/">A</a><a href="/b/">B</a><a href="https://otro.example/">Fuera</a><a href="/doc.pdf">PDF</a>');
        self::assertSame('no_verificable', self::ejecutar($cuerpo)->estado('SEO-07.enlaces-rotos'));

        $bien = self::ejecutar($cuerpo, ['https://www.x.example/a/' => self::pagina('<html></html>', 'https://www.x.example/a/'), 'https://www.x.example/b/' => self::pagina('<html></html>', 'https://www.x.example/b/')], Alcance::UrlContexto);
        self::assertSame('correcto', $bien->estado('SEO-07.enlaces-rotos'));
        self::assertNotContains('https://otro.example/', $bien->proveedor->urlsPedidas(), 'Los enlaces externos no se piden');
        self::assertNotContains('https://www.x.example/doc.pdf', $bien->proveedor->urlsPedidas(), 'Los ficheros no se piden');

        $roto = self::ejecutar($cuerpo, ['https://www.x.example/a/' => self::pagina('<html></html>', 'https://www.x.example/a/'), 'https://www.x.example/b/' => self::pagina('', 'https://www.x.example/b/', 404)], Alcance::UrlContexto);
        self::assertSame('problema', $roto->estado('SEO-07.enlaces-rotos'));
        self::assertSame('medio', $roto->resultado('SEO-07.enlaces-rotos')->gravedad?->value);
    }

    public function testPaginasMinimasDelKitDigital(): void
    {
        $cuerpo = self::html('', '<a href="/servicios/">Servicios</a><a href="/contacto/">Contacto</a>');
        $ok = static fn (string $u): RecursoDescargado => self::pagina('<html></html>', $u);

        self::assertSame('no_aplicable', self::ejecutar($cuerpo, [], Alcance::UrlContexto)->estado('SEO-07.paginas-minimas'));

        $bien = self::ejecutar($cuerpo, ['https://www.x.example/servicios/' => $ok('https://www.x.example/servicios/'), 'https://www.x.example/contacto/' => $ok('https://www.x.example/contacto/')], Alcance::UrlContexto, TipoProyecto::WebKitDigital);
        self::assertSame('correcto', $bien->estado('SEO-07.paginas-minimas'));

        $pocas = self::ejecutar(self::html('', '<a href="/contacto/">Contacto</a>'), ['https://www.x.example/contacto/' => $ok('https://www.x.example/contacto/')], Alcance::UrlContexto, TipoProyecto::WebKitDigital);
        self::assertSame('problema', $pocas->estado('SEO-07.paginas-minimas'));
    }

    public function testElLimiteDeUrlsDelPerfilSeRespeta(): void
    {
        $enlaces = '';
        $respuestas = [];
        for ($i = 1; $i <= 40; $i++) {
            $enlaces .= '<a href="/p' . $i . '/">Página ' . $i . '</a>';
            $respuestas['https://www.x.example/p' . $i . '/'] = self::pagina('<html></html>', 'https://www.x.example/p' . $i . '/');
        }
        $e = self::ejecutar(self::html('', $enlaces), $respuestas, Alcance::UrlContexto);
        $extra = array_filter($e->proveedor->urlsPedidas(), static fn (string $u): bool => !str_ends_with($u, '/robots.txt'));

        self::assertLessThanOrEqual(20, count($extra));
        self::assertStringContainsString('límite', (string) $e->resultado('SEO-07.enlaces-rotos')->limitacion);
    }

    public function testContradiccionEntreSenales(): void
    {
        $bien = self::ejecutar(self::html('<link rel="canonical" href="/"><meta property="og:url" content="https://www.x.example/">'));
        self::assertSame('correcto', $bien->estado('SEO-08.contradiccion-tecnica'));

        $dos = self::ejecutar(self::html('<link rel="canonical" href="/a"><link rel="canonical" href="/b">'));
        self::assertSame('problema', $dos->estado('SEO-08.contradiccion-tecnica'));
        self::assertSame('problema', $dos->estado('IA-08.canonical-declarado'));

        $og = self::ejecutar(self::html('<link rel="canonical" href="/"><meta property="og:url" content="https://www.x.example/otra">'));
        self::assertSame('mejorable', $og->estado('SEO-08.contradiccion-tecnica'));
    }

    // ------------------------------------------------------------ SEO-12 / IA-10

    public function testImagenes(): void
    {
        $bien = self::ejecutar(self::html('', '<img src="a.webp" alt="Caldera" width="800" height="600"><img src="b.png" alt="" width="1" height="1">'));
        foreach (['SEO-12.imagenes-rastreables', 'SEO-12.imagenes-alt', 'SEO-12.imagenes-formato', 'IA-10.contexto-imagenes'] as $id) {
            self::assertSame('correcto', $bien->estado($id), $id);
        }

        $mal = self::ejecutar(self::html('', '<img data-src="perezosa.jpg"><img src="c.jpg">'));
        self::assertSame('mejorable', $mal->estado('SEO-12.imagenes-rastreables'));
        self::assertSame('mejorable', $mal->estado('SEO-12.imagenes-alt'));
        self::assertSame('mejorable', $mal->estado('SEO-12.imagenes-formato'));
        self::assertSame('mejorable', $mal->estado('IA-10.contexto-imagenes'));

        $sin = self::ejecutar(self::html('', '<p>Texto</p>'));
        self::assertSame('no_aplicable', $sin->estado('SEO-12.imagenes-alt'));
        self::assertSame('no_aplicable', $sin->estado('IA-10.contexto-imagenes'));
    }

    public function testVideoSoloSiLaPaginaLoTiene(): void
    {
        $e = Ejecucion::de('video');
        self::assertSame('correcto', $e->estado('SEO-12.video-detectado'));
        self::assertSame('correcto', $e->estado('SEO-12.video-miniatura'));
        self::assertSame('correcto', $e->estado('SEO-12.video-datos'));
        self::assertSame('correcto', $e->estado('IA-10.contexto-video'));

        $sinPoster = self::ejecutar(self::html('', '<video src="v.mp4"></video>'));
        self::assertSame('mejorable', $sinPoster->estado('SEO-12.video-miniatura'));
        self::assertSame('no_aplicable', $sinPoster->estado('SEO-12.video-datos'), 'Sin VideoObject no es un problema');
        self::assertSame('mejorable', $sinPoster->estado('IA-10.contexto-video'));

        $youtube = self::ejecutar(self::html('<script type="application/ld+json">{"@context":"https://schema.org","@type":"VideoObject","name":"V"}</script>', '<iframe src="https://www.youtube.com/embed/x" title="V"></iframe>'));
        self::assertSame('correcto', $youtube->estado('SEO-12.video-miniatura'));
        self::assertSame('mejorable', $youtube->estado('SEO-12.video-datos'));

        $sin = self::ejecutar(self::html('', '<p>Sin vídeo</p>'));
        foreach (['SEO-12.video-detectado', 'SEO-12.video-miniatura', 'SEO-12.video-datos', 'IA-10.contexto-video'] as $id) {
            self::assertSame('no_aplicable', $sin->estado($id), $id);
        }
    }

    public function testHorariosComoTexto(): void
    {
        $e = Ejecucion::de('transporte-billetes');
        self::assertSame('mejorable', $e->estado('IA-10.horarios-texto'));

        $conTexto = self::ejecutar(self::html('', '<a href="/horarios.pdf">Horarios (PDF)</a><table><tr><td>León 07:30</td><td>Astorga 08:15</td><td>Ponferrada 09:00</td></tr></table>'), sector: Sector::Transporte);
        self::assertSame('correcto', $conTexto->estado('IA-10.horarios-texto'));

        self::assertSame('no_aplicable', self::ejecutar(self::html('', '<p>Hola</p>'))->estado('IA-10.horarios-texto'));
    }

    // ------------------------------------------------------------ IA-07 / SEO-14

    public function testDatosEstructurados(): void
    {
        $bien = self::ejecutar(self::html('<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"Organization","name":"X"},{"@type":"BreadcrumbList","itemListElement":[]}]}</script>'));
        self::assertSame('correcto', $bien->estado('IA-07.jsonld-sintaxis'));
        self::assertSame('correcto', $bien->estado('IA-07.tipos-schema'));
        self::assertStringContainsString('Organization', (string) $bien->resultado('IA-07.tipos-schema')->valorObservado);

        $sinContexto = self::ejecutar(self::html('<script type="application/ld+json">{"@type":"Organization","name":"X"}</script>'));
        self::assertSame('mejorable', $sinContexto->estado('IA-07.tipos-schema'));

        $incompleto = self::ejecutar(self::html('<script type="application/ld+json">{"@context":"https://schema.org","@type":"Event","name":"Feria"}</script>'));
        self::assertSame('mejorable', $incompleto->estado('SEO-14.elegibilidad-enriquecidos'));

        $nada = self::ejecutar(self::html());
        foreach (['IA-07.jsonld-sintaxis', 'IA-07.microdata-rdfa', 'IA-07.tipos-schema', 'SEO-14.elegibilidad-enriquecidos', 'SEO-14.producto-marcado'] as $id) {
            self::assertSame('no_aplicable', $nada->estado($id), "$id: la ausencia de datos estructurados no es un problema");
        }
    }

    public function testMarcadoDeProducto(): void
    {
        self::assertSame('correcto', Ejecucion::de('woocommerce-producto')->estado('SEO-14.producto-marcado'));

        $ps = Ejecucion::de('prestashop-producto');
        self::assertSame('problema', $ps->estado('SEO-14.producto-marcado'));
        self::assertStringContainsString('offers.price', (string) $ps->resultado('SEO-14.producto-marcado')->valorObservado);
        self::assertStringStartsWith('PrestaShop:', (string) $ps->resultado('SEO-14.producto-marcado')->correccionCms);

        $sinOferta = self::ejecutar(self::html('<script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"P"}</script>'));
        self::assertSame('problema', $sinOferta->estado('SEO-14.producto-marcado'));
    }

    public function testNegocioLocal(): void
    {
        self::assertSame('correcto', Ejecucion::de('gasolinera')->estado('IA-07.negocio-local'));

        $sinMarcado = self::ejecutar(self::html('', '<p>Gasolinera</p>'), sector: Sector::Hidrocarburos);
        self::assertSame('mejorable', $sinMarcado->estado('IA-07.negocio-local'));
        self::assertStringContainsString('GasStation', (string) $sinMarcado->resultado('IA-07.negocio-local')->correccion);

        $telefonoDistinto = self::ejecutar(self::html('<script type="application/ld+json">{"@context":"https://schema.org","@type":"Dentist","name":"D","telephone":"987 111 222","address":{"@type":"PostalAddress","streetAddress":"C/ A 1","addressLocality":"León"}}</script>', '<a href="tel:987333444">Llamar</a>'));
        self::assertSame('problema', $telefonoDistinto->estado('IA-07.negocio-local'));

        self::assertSame('no_aplicable', self::ejecutar(self::html())->estado('IA-07.negocio-local'));
    }

    // ------------------------------------------------------------ IA-08

    public function testCanonicalDeclarado(): void
    {
        self::assertSame('correcto', self::ejecutar(self::html('<link rel="canonical" href="https://www.x.example/">'))->estado('IA-08.canonical-declarado'));
        self::assertSame('mejorable', self::ejecutar(self::html())->estado('IA-08.canonical-declarado'));
        self::assertSame('decision_revisar', self::ejecutar(self::html('<link rel="canonical" href="https://otra.example/">'))->estado('IA-08.canonical-declarado'));
    }

    public function testPresenciaEnElSitemap(): void
    {
        $robots = static fn (string $t): RecursoDescargado => new RecursoDescargado('https://www.cumple.example/robots.txt', 'https://www.cumple.example/robots.txt', ResultadoDescarga::Correcto, 200, [], [], 'text/plain', $t, bytes: strlen($t), tlsVerificado: true);
        $sitemap = static fn (string $u, string $x): RecursoDescargado => new RecursoDescargado($u, $u, ResultadoDescarga::Correcto, 200, [], [], 'application/xml', $x, bytes: strlen($x), tlsVerificado: true);
        $urlset = static fn (string ...$locs): string => '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . implode('', array_map(static fn ($l) => "<url><loc>$l</loc></url>", $locs)) . '</urlset>';

        $esta = Ejecucion::de('cumple', ['https://www.cumple.example/robots.txt' => 'robots-permite-todo', 'https://www.cumple.example/sitemap.xml' => 'sitemap-basico'], Alcance::UrlContexto);
        self::assertSame('correcto', $esta->estado('IA-08.presencia-sitemap'));

        $indice = Ejecucion::de('cumple', [
            'https://www.cumple.example/robots.txt' => $robots("Sitemap: https://www.cumple.example/indice.xml\n"),
            'https://www.cumple.example/indice.xml' => $sitemap('https://www.cumple.example/indice.xml', '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://www.cumple.example/s1.xml</loc></sitemap></sitemapindex>'),
            'https://www.cumple.example/s1.xml' => $sitemap('https://www.cumple.example/s1.xml', $urlset('https://www.cumple.example/')),
        ], Alcance::UrlContexto);
        self::assertSame('correcto', $indice->estado('IA-08.presencia-sitemap'));

        $falta = Ejecucion::de('cumple', ['https://www.cumple.example/sitemap.xml' => $sitemap('https://www.cumple.example/sitemap.xml', $urlset('https://www.cumple.example/otra/'))], Alcance::UrlContexto);
        self::assertSame('mejorable', $falta->estado('IA-08.presencia-sitemap'));

        $noHay = Ejecucion::de('cumple', ['https://www.cumple.example/sitemap.xml' => 'robots-404'], Alcance::UrlContexto);
        self::assertSame('mejorable', $noHay->estado('IA-08.presencia-sitemap'));

        $xxe = Ejecucion::de('cumple', ['https://www.cumple.example/sitemap.xml' => 'sitemap-xxe'], Alcance::UrlContexto);
        self::assertSame('no_verificable', $xxe->estado('IA-08.presencia-sitemap'), 'Un sitemap peligroso no se lee');
    }

    public function testDatosDelNegocioEntrePaginas(): void
    {
        $e = Ejecucion::de('gasolinera', ['https://www.gasolinera.example/contacto/' => 'gasolinera-contacto'], Alcance::UrlContexto);
        self::assertSame('problema', $e->estado('IA-08.datos-negocio-coherentes'));
        self::assertStringContainsString('987999000', (string) $e->resultado('IA-08.datos-negocio-coherentes')->valorObservado);

        self::assertSame('no_verificable', Ejecucion::de('gasolinera')->estado('IA-08.datos-negocio-coherentes'));
        self::assertSame('no_aplicable', Ejecucion::de('no-cumple', [], Alcance::UrlContexto)->estado('IA-08.datos-negocio-coherentes'));
    }

    // ------------------------------------------------------------ SEO-15

    public function testCodigosHreflang(): void
    {
        foreach (['es', 'en', 'en-GB', 'pt-BR', 'zh-Hant', 'x-default', 'ES-es'] as $valido) {
            self::assertNull(Idiomas::errorCodigo($valido), $valido);
        }
        foreach (['espanol', 'en-UK', 'es_ES', 'spa', 'es-LA', ''] as $invalido) {
            self::assertNotNull(Idiomas::errorCodigo($invalido), $invalido);
        }
    }

    public function testHreflang(): void
    {
        $alternativas = '<link rel="alternate" hreflang="es" href="https://www.x.example/"><link rel="alternate" hreflang="en" href="https://www.x.example/en/">';
        $bien = self::ejecutar(self::html($alternativas));
        self::assertSame('correcto', $bien->estado('SEO-15.hreflang-codigos'));
        self::assertSame('correcto', $bien->estado('SEO-15.hreflang-autorreferencia'));

        $sinPropia = self::ejecutar(self::html('<link rel="alternate" hreflang="en" href="https://www.x.example/en/">'));
        self::assertSame('problema', $sinPropia->estado('SEO-15.hreflang-autorreferencia'));

        $duplicado = self::ejecutar(self::html('<link rel="alternate" hreflang="en" href="/en/"><link rel="alternate" hreflang="en" href="/english/">'));
        self::assertSame('problema', $duplicado->estado('SEO-15.hreflang-codigos'));

        $en = static fn (string $vuelta): RecursoDescargado => self::pagina(self::html($vuelta, '', 'en'), 'https://www.x.example/en/');
        $reciproco = self::ejecutar(self::html($alternativas), ['https://www.x.example/en/' => $en($alternativas)], Alcance::UrlContexto);
        self::assertSame('correcto', $reciproco->estado('SEO-15.hreflang-destinos'));
        self::assertSame('correcto', $reciproco->estado('SEO-15.hreflang-reciprocidad'));

        $sinVuelta = self::ejecutar(self::html($alternativas), ['https://www.x.example/en/' => $en('')], Alcance::UrlContexto);
        self::assertSame('problema', $sinVuelta->estado('SEO-15.hreflang-reciprocidad'));

        $roto = self::ejecutar(self::html($alternativas), ['https://www.x.example/en/' => self::pagina('', 'https://www.x.example/en/', 404)], Alcance::UrlContexto);
        self::assertSame('problema', $roto->estado('SEO-15.hreflang-destinos'));

        $monolingue = self::ejecutar(self::html());
        foreach (['SEO-15.hreflang-codigos', 'SEO-15.hreflang-autorreferencia', 'SEO-15.hreflang-destinos', 'SEO-15.hreflang-reciprocidad'] as $id) {
            self::assertSame('no_aplicable', $monolingue->estado($id), $id);
        }
    }

    // ------------------------------------------------------------ IA-11

    public function testAccionesConNombre(): void
    {
        self::assertSame('correcto', self::ejecutar(self::html('', '<button>Pedir cita</button><button aria-label="Abrir chat"><svg></svg></button>'))->estado('IA-11.acciones-nombre-rol'));
        self::assertSame('problema', Ejecucion::de('chatbot')->estado('IA-11.acciones-nombre-rol'));
        self::assertSame('no_aplicable', self::ejecutar(self::html('', '<p>Hola</p>'))->estado('IA-11.acciones-nombre-rol'));
    }
}
