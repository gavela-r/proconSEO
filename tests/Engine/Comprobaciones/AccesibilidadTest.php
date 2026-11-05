<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Engine\Comprobaciones;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProconSEO\Engine\Comprobaciones\AccesibilidadEstructura;
use ProconSEO\Engine\Motor;
use ProconSEO\Shared\Contract\ContextoAnalisis;
use ProconSEO\Shared\Contract\RecursoDescargado;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\Bloque;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\Ejecucion as EjecucionEnum;
use ProconSEO\Shared\Enum\ResultadoDescarga;
use ProconSEO\Shared\Enum\Sector;
use ProconSEO\Tests\Engine\Soporte\Ejecucion;

/**
 * Comprobaciones de accesibilidad sin navegador (paso 4c): ACC-01, 02, 03, 06, 09, 10 y 12.
 */
final class AccesibilidadTest extends TestCase
{
    private const URL = 'https://www.x.example/';

    private static function ejecutar(string $body, string $head = '<title>T</title>', string $lang = 'es', ?Sector $sector = null): Ejecucion
    {
        $html = '<!DOCTYPE html><html' . ($lang === '' ? '' : ' lang="' . $lang . '"') . '><head><meta charset="utf-8">' . $head . '</head><body>' . $body . '</body></html>';
        $pagina = new RecursoDescargado(self::URL, self::URL, ResultadoDescarga::Correcto, 200, [], ['content-type' => 'text/html'], 'text/html', $html, bytes: strlen($html), tlsVerificado: true);
        return Ejecucion::de('cumple', contexto: new ContextoAnalisis(1, Alcance::UrlIndividual, $pagina, ['www.x.example'], null, [], null, $sector));
    }

    /** @return iterable<string, array{string}> */
    public static function accesibilidadImplementada(): iterable
    {
        foreach (Motor::v1()->coberturas() as $id => $cobertura) {
            if (str_starts_with($id, 'ACC-') && $cobertura !== Cobertura::NoImplementada) {
                yield $id => [$id];
            }
        }
    }

    #[DataProvider('accesibilidadImplementada')]
    public function testConLaPaginaBloqueadaNadaSeValora(string $id): void
    {
        foreach (['bloqueada-403', 'sin-respuesta'] as $fixture) {
            $r = Ejecucion::de($fixture)->resultado($id);
            self::assertSame('no_verificable', $r->estado->value, "$fixture: $id");
            self::assertSame(EjecucionEnum::NoEjecutada, $r->ejecucion, "$fixture: $id");
        }
    }

    #[DataProvider('accesibilidadImplementada')]
    public function testCadaResultadoIndicaSuCriterioWcagYVersion(string $id): void
    {
        $r = Ejecucion::de('no-cumple')->resultado($id);
        self::assertSame(Bloque::Acc, $r->bloque);
        self::assertMatchesRegularExpression('/^\d\.\d\.\d+$/', (string) $r->wcagCriterio, $id);
        self::assertContains($r->wcagVersion, ['2.1', '2.2'], $id);
        self::assertContains($r->wcagNivel, ['A', 'AA'], $id);
        self::assertNotNull($r->modo, $id);
    }

    public function testLaRevisionHumanaPendienteEsNoVerificable(): void
    {
        // RF-44: mientras haya revisión pendiente, la subcomprobación sigue no verificable.
        foreach (Ejecucion::de('no-cumple')->salida->resultados as $r) {
            if ($r->bloque === Bloque::Acc && $r->requiereRevisionHumana) {
                self::assertSame('no_verificable', $r->estado->value, $r->idComprobacion);
            }
        }
    }

    // ------------------------------------------------------------ ACC-01

    public function testTextoAlternativo(): void
    {
        $bien = self::ejecutar('<img src="a.jpg" alt="Técnico revisando una caldera"><img src="b.png" alt=""><img src="c.png" role="presentation">');
        self::assertSame('correcto', $bien->estado('ACC-01.img-alt-presente'));
        self::assertTrue($bien->resultado('ACC-01.alt-adecuado')->requiereRevisionHumana);
        self::assertSame('no_verificable', $bien->estado('ACC-01.alt-adecuado'), 'La presencia no es calidad');

        $mal = self::ejecutar('<img src="a.jpg"><img src="b.jpg" alt="b">');
        self::assertSame('problema', $mal->estado('ACC-01.img-alt-presente'));
        self::assertSame('alto', $mal->resultado('ACC-01.img-alt-presente')->gravedad?->value);
        self::assertStringContainsString('nombre del fichero', (string) self::evidencia($mal, 'ACC-01.alt-adecuado'));

        $oculta = self::ejecutar('<div aria-hidden="true"><img src="a.jpg"></div>');
        self::assertSame('no_aplicable', $oculta->estado('ACC-01.img-alt-presente'));
    }

    public function testImagenComoUnicoContenidoDeEnlaceOBoton(): void
    {
        self::assertSame('correcto', self::ejecutar('<a href="/"><img src="logo.png" alt="Inicio"></a><a href="/c" aria-label="Carrito"><img src="c.svg" alt=""></a>')->estado('ACC-01.img-enlace-sin-nombre'));
        self::assertSame('problema', self::ejecutar('<a href="/"><img src="logo.png" alt=""></a>')->estado('ACC-01.img-enlace-sin-nombre'));
        self::assertSame('no_aplicable', self::ejecutar('<a href="/">Inicio</a>')->estado('ACC-01.img-enlace-sin-nombre'));
    }

    public function testBotonesDeImagenSvgYAreas(): void
    {
        self::assertSame('correcto', self::ejecutar('<form><input type="image" src="e.png" alt="Enviar"></form>')->estado('ACC-01.input-image-alt'));
        self::assertSame('problema', self::ejecutar('<form><input type="IMAGE" src="e.png"></form>')->estado('ACC-01.input-image-alt'));

        self::assertSame('correcto', self::ejecutar('<svg role="img" aria-label="Gráfico de ventas"></svg><map><area href="/a" alt="Zona A"></map>')->estado('ACC-01.svg-area-nombre'));
        self::assertSame('correcto', self::ejecutar('<svg role="img"><title>Logo de la empresa</title></svg>')->estado('ACC-01.svg-area-nombre'));
        self::assertSame('problema', self::ejecutar('<svg role="img"></svg>')->estado('ACC-01.svg-area-nombre'));
    }

    // ------------------------------------------------------------ ACC-02

    public function testMultimedia(): void
    {
        $video = Ejecucion::de('video');
        self::assertSame('no_verificable', $video->estado('ACC-02.medios-detectados'));
        self::assertTrue($video->resultado('ACC-02.medios-detectados')->requiereRevisionHumana);
        self::assertSame('correcto', $video->estado('ACC-02.pistas-subtitulos'));
        self::assertSame('no_verificable', $video->estado('ACC-02.audio-automatico'));

        $sinPista = self::ejecutar('<video src="v.mp4" controls></video>');
        self::assertSame('no_verificable', $sinPista->estado('ACC-02.pistas-subtitulos'), 'Sin saber si tiene audio no se afirma el fallo');
        self::assertSame('correcto', $sinPista->estado('ACC-02.audio-automatico'));

        self::assertSame('correcto', self::ejecutar('<video src="v.mp4" autoplay muted loop></video>')->estado('ACC-02.audio-automatico'));

        $nada = self::ejecutar('<p>Sin medios</p>');
        foreach (['ACC-02.medios-detectados', 'ACC-02.pistas-subtitulos', 'ACC-02.audio-automatico'] as $id) {
            self::assertSame('no_aplicable', $nada->estado($id), $id);
        }
    }

    // ------------------------------------------------------------ ACC-03

    public function testTituloYEstructura(): void
    {
        $bien = self::ejecutar('<nav><a href="/">Inicio</a></nav><main><h1>Clínica</h1><ul><li>A</li></ul></main>');
        self::assertSame('correcto', $bien->estado('ACC-03.title-pagina'));
        self::assertSame('correcto', $bien->estado('ACC-03.estructura-semantica'));

        $mal = self::ejecutar('<div>Texto sin estructura</div>', '');
        self::assertSame('problema', $mal->estado('ACC-03.title-pagina'));
        self::assertSame('mejorable', $mal->estado('ACC-03.estructura-semantica'));
    }

    public function testTablas(): void
    {
        self::assertSame('correcto', self::ejecutar('<table><tr><th scope="col">Servicio</th><th scope="col">Precio</th></tr><tr><td>A</td><td>1</td></tr></table>')->estado('ACC-03.tablas-encabezados'));

        $sinTh = self::ejecutar('<table><tr><td>Servicio</td><td>Precio</td></tr><tr><td>A</td><td>1</td></tr></table>');
        self::assertSame('no_verificable', $sinTh->estado('ACC-03.tablas-encabezados'));
        self::assertTrue($sinTh->resultado('ACC-03.tablas-encabezados')->requiereRevisionHumana);

        self::assertSame('no_aplicable', self::ejecutar('<table role="presentation"><tr><td>A</td><td>B</td></tr><tr><td>C</td><td>D</td></tr></table>')->estado('ACC-03.tablas-encabezados'));
        self::assertSame('no_aplicable', self::ejecutar('<table><tr><td>Una sola celda</td></tr></table>')->estado('ACC-03.tablas-encabezados'));
    }

    // ------------------------------------------------------------ ACC-06

    public function testEnlacesConNombreYProposito(): void
    {
        $bien = self::ejecutar('<a href="/servicios/">Ver nuestros servicios</a>');
        self::assertSame('correcto', $bien->estado('ACC-06.enlaces-nombre'));
        self::assertSame('correcto', $bien->estado('ACC-06.enlaces-proposito'));

        $mal = self::ejecutar('<a href="/ofertas/"></a><a href="/blog/">Leer más</a><a href="/x/"><i class="icono"></i></a>');
        self::assertSame('problema', $mal->estado('ACC-06.enlaces-nombre'));
        self::assertStringContainsString('2 de 3', (string) $mal->resultado('ACC-06.enlaces-nombre')->valorObservado);
        self::assertSame('no_verificable', $mal->estado('ACC-06.enlaces-proposito'), 'El contexto puede aclarar el propósito');
    }

    public function testSaltarBloquesYTabindex(): void
    {
        self::assertSame('correcto', self::ejecutar('<a href="#c">Saltar al contenido</a><nav>…</nav><div id="c">Hola</div>')->estado('ACC-06.saltar-bloques'));
        self::assertSame('problema', self::ejecutar('<nav><a href="/">A</a><a href="/b">B</a></nav><div>Contenido</div>')->estado('ACC-06.saltar-bloques'));

        self::assertSame('correcto', self::ejecutar('<div tabindex="0">A</div><div tabindex="-1">B</div>')->estado('ACC-06.tabindex-positivo'));
        $positivo = self::ejecutar('<div tabindex="3">A</div>');
        self::assertSame('no_verificable', $positivo->estado('ACC-06.tabindex-positivo'));
        self::assertTrue($positivo->resultado('ACC-06.tabindex-positivo')->requiereRevisionHumana);
    }

    // ------------------------------------------------------------ ACC-09

    public function testEtiquetasYPlaceholder(): void
    {
        $bien = self::ejecutar('<form><label for="n">Nombre</label><input id="n" name="nombre" autocomplete="name"><label>Correo <input type="email" name="correo" autocomplete="email"></label><input type="hidden" name="t"><button>Enviar</button></form>');
        self::assertSame('correcto', $bien->estado('ACC-09.labels-asociados'));
        self::assertSame('no_aplicable', $bien->estado('ACC-09.placeholder-como-etiqueta'));
        self::assertSame('correcto', $bien->estado('ACC-09.autocomplete'));

        $mal = self::ejecutar('<form><input name="nombre" placeholder="Nombre"><input type="email" name="email" placeholder="Correo" autocomplete="off"><select name="provincia"><option>León</option></select></form>');
        self::assertSame('problema', $mal->estado('ACC-09.labels-asociados'));
        self::assertSame('alto', $mal->resultado('ACC-09.labels-asociados')->gravedad?->value);
        self::assertSame('problema', $mal->estado('ACC-09.placeholder-como-etiqueta'));
        self::assertSame('problema', $mal->estado('ACC-09.autocomplete'), 'autocomplete="off" en un dato personal no indica su propósito');

        $nada = self::ejecutar('<p>Sin formularios</p>');
        foreach (['ACC-09.labels-asociados', 'ACC-09.placeholder-como-etiqueta', 'ACC-09.autocomplete', 'ACC-09.campos-obligatorios'] as $id) {
            self::assertSame('no_aplicable', $nada->estado($id), $id);
        }
    }

    public function testAriaLabelYTitleCuentanComoEtiqueta(): void
    {
        $e = self::ejecutar('<input type="search" name="q" aria-label="Buscar en la web"><span id="et">Teléfono</span><input name="x" aria-labelledby="et">');
        self::assertSame('correcto', $e->estado('ACC-09.labels-asociados'));

        $roto = self::ejecutar('<input name="x" aria-labelledby="no-existe">');
        self::assertSame('problema', $roto->estado('ACC-09.labels-asociados'));
    }

    public function testCamposObligatorios(): void
    {
        self::assertSame('correcto', self::ejecutar('<form><label for="a">Nombre</label><input id="a" required></form>')->estado('ACC-09.campos-obligatorios'));
        self::assertSame('mejorable', self::ejecutar('<form><label for="a">Nombre *</label><input id="a"></form>')->estado('ACC-09.campos-obligatorios'));
    }

    public function testFormularioDeBilletes(): void
    {
        $e = Ejecucion::de('transporte-billetes');
        self::assertSame('problema', $e->estado('ACC-09.formulario-billetes'));
        self::assertSame('alto', $e->resultado('ACC-09.formulario-billetes')->gravedad?->value);

        $bien = self::ejecutar('<form><label for="o">Origen</label><select id="o" name="origen"></select><label for="d">Destino</label><select id="d" name="destino"></select></form>', sector: Sector::Transporte);
        self::assertSame('correcto', $bien->estado('ACC-09.formulario-billetes'));

        self::assertSame('no_aplicable', self::ejecutar('<form><label for="n">Nombre</label><input id="n"></form>')->estado('ACC-09.formulario-billetes'));
    }

    // ------------------------------------------------------------ ACC-10

    public function testIdiomaDeLaPagina(): void
    {
        self::assertSame('correcto', self::ejecutar('<p>Hola</p>')->estado('ACC-10.lang-presente'));
        $sin = self::ejecutar('<p>Hola</p>', lang: '');
        self::assertSame('problema', $sin->estado('ACC-10.lang-presente'));
        self::assertSame('no_aplicable', $sin->estado('ACC-10.lang-valido'));

        self::assertSame('problema', self::ejecutar('<p>Hola</p>', lang: 'es_ES')->estado('ACC-10.lang-valido'));
        self::assertSame('problema', self::ejecutar('<p>Hola</p>', lang: 'espanol')->estado('ACC-10.lang-valido'));
        self::assertSame('correcto', self::ejecutar('<p>Hola</p>', lang: 'es-ES')->estado('ACC-10.lang-valido'));
    }

    public function testCodigosDeIdioma(): void
    {
        foreach (['es', 'es-ES', 'en-GB', 'zh-Hant', 'es-419', 'ca-ES-valencia', 'gl'] as $valido) {
            self::assertTrue(AccesibilidadEstructura::codigoIdiomaValido($valido), $valido);
        }
        foreach (['es_ES', 'espanol', 'e', 'es-', '', 'en-GB-'] as $invalido) {
            self::assertFalse(AccesibilidadEstructura::codigoIdiomaValido($invalido), $invalido);
        }
    }

    public function testIdiomaDeLasPartes(): void
    {
        self::assertSame('problema', self::ejecutar('<p lang="ingles">Hello</p>')->estado('ACC-10.idioma-partes'));
        $bien = self::ejecutar('<p lang="en">Hello</p>');
        self::assertSame('no_verificable', $bien->estado('ACC-10.idioma-partes'), 'Siempre puede haber frases sin marcar');
        self::assertTrue($bien->resultado('ACC-10.idioma-partes')->requiereRevisionHumana);
    }

    // ------------------------------------------------------------ ACC-12

    public function testNombresDeControles(): void
    {
        self::assertSame('correcto', self::ejecutar('<button>Enviar</button><div role="button" aria-label="Cerrar">×</div><div role="switch" aria-labelledby="l"></div><span id="l">Modo oscuro</span>')->estado('ACC-12.nombres-controles'));

        $mal = self::ejecutar('<button><i class="icono-buscar"></i></button><div role="checkbox"></div><button aria-hidden="true"></button>');
        self::assertSame('problema', $mal->estado('ACC-12.nombres-controles'));
        self::assertStringContainsString('2 de 2', (string) $mal->resultado('ACC-12.nombres-controles')->valorObservado, 'Lo oculto con aria-hidden no cuenta');
    }

    public function testRolesYAtributosAria(): void
    {
        self::assertSame('correcto', self::ejecutar('<nav role="navigation" aria-label="Principal"></nav><div role="presentation none"></div>')->estado('ACC-12.roles-aria-validos'));
        self::assertSame('problema', self::ejecutar('<div role="botonazo">A</div>')->estado('ACC-12.roles-aria-validos'));
        self::assertSame('problema', self::ejecutar('<button aria-lable="Enviar">E</button>')->estado('ACC-12.roles-aria-validos'));
        self::assertSame('correcto', self::ejecutar('<div role="botonazo button">A</div>')->estado('ACC-12.roles-aria-validos'), 'Se usa el primer rol válido de la lista');
        self::assertSame('no_aplicable', self::ejecutar('<p>Hola</p>')->estado('ACC-12.roles-aria-validos'));
    }

    public function testIframes(): void
    {
        self::assertSame('correcto', self::ejecutar('<iframe src="/mapa" title="Mapa"></iframe>')->estado('ACC-12.iframe-title'));
        self::assertSame('problema', self::ejecutar('<iframe src="/mapa"></iframe>')->estado('ACC-12.iframe-title'));
        self::assertSame('no_aplicable', self::ejecutar('<iframe src="/x" hidden></iframe>')->estado('ACC-12.iframe-title'));
    }

    public function testIdsDuplicados(): void
    {
        self::assertSame('correcto', self::ejecutar('<span id="a"></span><span id="a"></span>')->estado('ACC-12.ids-referenciados-duplicados'));
        self::assertSame('problema', self::ejecutar('<label for="n">Nombre</label><input id="n"><input id="n">')->estado('ACC-12.ids-referenciados-duplicados'));
    }

    public function testChatbot(): void
    {
        $e = Ejecucion::de('chatbot');
        self::assertSame('problema', $e->estado('ACC-12.chatbot-detectado'));
        self::assertStringContainsString('Abrir chat', (string) $e->resultado('ACC-12.chatbot-detectado')->correccion);

        $bien = self::ejecutar('<div id="chat-widget"><button aria-label="Abrir chat de ayuda"><img src="i.svg" alt=""></button><iframe title="Chat de ayuda" src="/c" hidden></iframe></div>');
        self::assertSame('correcto', $bien->estado('ACC-12.chatbot-detectado'));

        $soloScript = self::ejecutar('<p>Hola</p><script src="https://embed.tawk.to/abc/default"></script>');
        self::assertSame('no_verificable', $soloScript->estado('ACC-12.chatbot-detectado'));

        self::assertSame('no_aplicable', self::ejecutar('<p>Sin chat</p>')->estado('ACC-12.chatbot-detectado'));
    }

    private static function evidencia(Ejecucion $e, string $id): string
    {
        $codigos = $e->resultado($id)->codigosEvidencia;
        $textos = [];
        foreach ($e->salida->evidencias as $ev) {
            if (in_array($ev->codigo, $codigos, true)) {
                $textos[] = $ev->contenido;
            }
        }
        return implode("\n", $textos);
    }
}
