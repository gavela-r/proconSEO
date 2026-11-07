<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Fetch\Navegador;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ProconSEO\Fetch\Ambito;
use ProconSEO\Fetch\ClienteHttpSeguro;
use ProconSEO\Fetch\Http\RespuestaHttp;
use ProconSEO\Fetch\LimitesDescarga;
use ProconSEO\Fetch\Navegador\FiltroPeticiones;
use ProconSEO\Fetch\Navegador\LecturaProvisional;
use ProconSEO\Fetch\Navegador\NavegadorAislado;
use ProconSEO\Fetch\Navegador\PaginaRenderizada;
use ProconSEO\Fetch\Navegador\ProcesoNavegador;
use ProconSEO\Fetch\ValidadorDestino;
use ProconSEO\Fetch\ValidadorUrl;
use ProconSEO\Shared\Enum\EstadoResultado;
use ProconSEO\Tests\Fetch\DnsFalso;
use ProconSEO\Tests\Fetch\TransporteFalso;

/**
 * Prueba de extremo a extremo con un Chrome o Edge real, pero sin red: todas las respuestas
 * salen de TransporteFalso. Si el navegador hiciera una petición por su cuenta, no tendría
 * respuesta (no resuelve nombres y su proxy es un puerto cerrado) y el test lo notaría.
 *
 * Se salta si el equipo no tiene navegador.
 */
#[Group('navegador')]
final class NavegadorAisladoTest extends TestCase
{
    private const PAGINA = <<<'HTML'
        <!doctype html><html lang="es"><head><meta charset="utf-8"><title>Prueba</title>
        <link rel="stylesheet" href="https://cdn.ejemplo.net/estilo.css">
        <style>
          body { margin: 0; background: #ffffff; color: #222222; font: 16px sans-serif; }
          .flojo { color: #aaaaaa; }
          #sin-foco:focus, .menu a:focus { outline: none; }
          a:focus { outline: 3px solid #e30613; }
          .ancho { width: 900px; height: 20px; background: #eeeeee; }
        </style></head>
        <body>
          <p>Texto con buen contraste en la página de prueba.</p>
          <p class="flojo">Texto gris claro que no llega al mínimo.</p>
          <p class="cdn">Texto pintado con el CSS del CDN.</p>
          <nav class="menu"><a href="/menu">Menu sin foco</a></nav>
          <a href="/otra">Enlace con foco</a>
          <button id="sin-foco">Botón sin foco visible</button>
          <div class="ancho">Caja demasiado ancha</div>
          <div id="js"></div>
          <img src="https://ejemplo.es/foto.jpg" alt="Foto">
          <script>
            document.getElementById('js').textContent = 'Añadido con JavaScript';
            fetch('/api/enviar', { method: 'POST', body: 'x' }).catch(() => {});
            fetch('https://interno.ejemplo.es/secreto').catch(() => {});
            fetch('https://analitica.otra.com/hit').catch(() => {});
          </script>
        </body></html>
        HTML;

    private static ?PaginaRenderizada $pagina = null;
    private static ?TransporteFalso $transporte = null;

    public static function setUpBeforeClass(): void
    {
        $ejecutable = ProcesoNavegador::buscarEjecutable(getenv('NAVEGADOR_RUTA') ?: null);
        if ($ejecutable === null) {
            return;
        }
        $t = new TransporteFalso();
        $t->porUrl['https://ejemplo.es/'] = new RespuestaHttp(200, ['content-type' => 'text/html; charset=utf-8'], self::PAGINA);
        $t->porUrl['https://cdn.ejemplo.net/estilo.css'] = new RespuestaHttp(200, ['content-type' => 'text/css'], '.cdn { color: #0000ee; }');
        $dns = new DnsFalso([
            'ejemplo.es' => ['93.184.216.34'],
            'cdn.ejemplo.net' => ['93.184.216.35'],
            'interno.ejemplo.es' => ['10.0.0.5'],
            'analitica.otra.com' => ['93.184.216.36'],
        ]);
        $validadorUrl = new ValidadorUrl([80, 443]);
        $validador = new ValidadorDestino($validadorUrl, $dns);
        $limites = new LimitesDescarga(reintentos: 0, peticionesPorSegundoHost: 100.0);
        $ambito = new Ambito(['ejemplo.es', 'interno.ejemplo.es']);

        $navegador = new NavegadorAislado(
            $ejecutable,
            new ClienteHttpSeguro($t, $validador, $ambito, $limites),
            new ClienteHttpSeguro($t, $validador, Ambito::subrecursos(), $limites),
            new FiltroPeticiones($ambito, $validadorUrl),
            segundos: 40.0,
        );
        $pagina = $navegador->analizar('https://ejemplo.es/');
        // Algunos Linux no permiten la sandbox de Chrome sin privilegios; nunca se desactiva,
        // así que ahí la prueba no se puede hacer (en Windows sí).
        if ($pagina->error !== null && str_starts_with($pagina->error, 'El navegador no arrancó')) {
            self::$salto = 'El navegador no arranca en este equipo con la sandbox activa.';

            return;
        }
        self::$pagina = $pagina;
        self::$transporte = $t;
    }

    private static string $salto = 'No hay Chrome ni Edge en este equipo.';

    protected function setUp(): void
    {
        if (self::$pagina === null) {
            self::markTestSkipped(self::$salto);
        }
    }

    public function testPintaLaPaginaYEjecutaSuJavaScript(): void
    {
        $p = self::pagina();
        self::assertNull($p->error, (string) $p->error);
        self::assertNotNull($p->dom);
        self::assertStringContainsString('Añadido con JavaScript', $p->dom['html']);
    }

    public function testSoloSeDescargaLoPermitidoYPorElClienteSeguro(): void
    {
        $pedidas = array_map(static fn ($pet): string => $pet->destino->url->url(), self::transporte()->peticiones);
        sort($pedidas);
        // La página y el CSS del CDN; nada más llega a la red.
        self::assertSame(['https://cdn.ejemplo.net/estilo.css', 'https://ejemplo.es/'], array_values(array_unique($pedidas)));

        $cortadas = [];
        foreach (self::pagina()->peticiones as $pet) {
            if (!$pet['permitida']) {
                $cortadas[$pet['url']] = $pet['motivo'];
            }
        }
        self::assertStringContainsString('POST', $cortadas['https://ejemplo.es/api/enviar'] ?? '', 'Los envíos nunca se hacen');
        self::assertArrayHasKey('https://interno.ejemplo.es/secreto', $cortadas, 'Una IP interna se bloquea aunque el host esté en el ámbito');
        self::assertArrayHasKey('https://analitica.otra.com/hit', $cortadas, 'Datos a terceros: no');
        self::assertArrayHasKey('https://ejemplo.es/foto.jpg', $cortadas, 'Imágenes: no');
    }

    public function testDetectaElContrasteInsuficienteYUsaElCssDelCdn(): void
    {
        $r = self::resultados();
        self::assertSame(EstadoResultado::Problema, $r['ACC-05.contraste-texto']['estado']);
        $evidencias = implode("\n", $r['ACC-05.contraste-texto']['evidencias']);
        self::assertStringContainsString('#aaaaaa sobre #ffffff', $evidencias);
        self::assertStringNotContainsString('#0000ee', $evidencias, 'El azul del CDN cumple');

        $colores = array_column(array_column(self::pagina()->contraste['muestras'] ?? [], 'color'), 2);
        self::assertContains(238, $colores, 'El CSS del CDN se aplicó (azul #0000ee)');
    }

    public function testFocoSinIndicadorYSinTrampas(): void
    {
        $r = self::resultados();
        self::assertSame(EstadoResultado::Problema, $r['ACC-06.foco-visible']['estado']);
        self::assertStringContainsString('sin-foco', implode(' ', $r['ACC-06.foco-visible']['evidencias']));
        self::assertStringContainsString('Menu sin foco', implode(' ', $r['ACC-06.foco-visible']['evidencias']), 'outline: none sin otro cambio visible');
        self::assertStringNotContainsString('Enlace con foco', implode(' ', $r['ACC-06.foco-visible']['evidencias']));
        self::assertSame(EstadoResultado::Correcto, $r['ACC-06.trampa-teclado']['estado']);
    }

    public function testReflowA320(): void
    {
        $r = self::resultados();
        self::assertSame(EstadoResultado::Problema, $r['ACC-04.reflow-320']['estado']);
        self::assertStringContainsString('div.ancho', implode(' ', $r['ACC-04.reflow-320']['evidencias']));
    }

    public function testPaginaBloqueadaPorSeguridad(): void
    {
        $t = new TransporteFalso();
        $validadorUrl = new ValidadorUrl([80, 443]);
        $validador = new ValidadorDestino($validadorUrl, new DnsFalso(['privada.ejemplo.es' => ['192.168.1.10']]));
        $ambito = new Ambito(['privada.ejemplo.es']);
        $navegador = new NavegadorAislado(
            (string) ProcesoNavegador::buscarEjecutable(getenv('NAVEGADOR_RUTA') ?: null),
            new ClienteHttpSeguro($t, $validador, $ambito),
            new ClienteHttpSeguro($t, $validador, Ambito::subrecursos()),
            new FiltroPeticiones($ambito, $validadorUrl),
            segundos: 20.0,
        );

        $p = $navegador->analizar('https://privada.ejemplo.es/');

        self::assertNotNull($p->error);
        self::assertSame([], $t->peticiones, 'Ni una petición sale hacia la IP privada');
        foreach (LecturaProvisional::leer($p) as $r) {
            self::assertSame(EstadoResultado::NoVerificable, $r['estado'], $r['id']);
        }
    }

    public function testNoDejaProcesosNiPerfiles(): void
    {
        $restos = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'proconseo-nav-*') ?: [];
        self::assertSame([], $restos, 'El perfil temporal se borra al terminar');
    }

    private static function pagina(): PaginaRenderizada
    {
        return self::$pagina ?? throw new \LogicException('sin página');
    }

    private static function transporte(): TransporteFalso
    {
        return self::$transporte ?? throw new \LogicException('sin transporte');
    }

    /** @return array<string, array{id: string, titulo: string, estado: EstadoResultado, gravedad: mixed, detalle: string, evidencias: list<string>}> */
    private static function resultados(): array
    {
        return array_column(LecturaProvisional::leer(self::pagina()), null, 'id');
    }
}
