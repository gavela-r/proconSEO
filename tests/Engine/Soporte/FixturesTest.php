<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Engine\Soporte;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\EstadoResultado;
use ProconSEO\Shared\Enum\Gravedad;
use ProconSEO\Shared\Enum\ResultadoDescarga;
use ProconSEO\Shared\Enum\Sector;
use ProconSEO\Shared\Enum\TipoProyecto;

/**
 * Comprueba que las respuestas de ejemplo de tests/Fixtures/ son coherentes, para que los
 * tests de las comprobaciones se apoyen en datos fiables.
 */
final class FixturesTest extends TestCase
{
    private const CAMPOS = [
        'descripcion', 'url_solicitada', 'url_final', 'resultado', 'estado_http', 'redirecciones',
        'cabeceras', 'tipo_contenido', 'cuerpo', 'tls_verificado', 'motivo', 'contexto', 'esperado',
    ];

    /** @return iterable<string, array{string}> */
    public static function respuestas(): iterable
    {
        foreach (Fixtures::nombres() as $nombre) {
            yield $nombre => [$nombre];
        }
    }

    public function testHayRespuestasDeTodosLosCasosQuePideLaEspecificacion(): void
    {
        $nombres = Fixtures::nombres();
        foreach ([
            'cumple', 'no-cumple', 'bloqueada-403', 'sin-respuesta', 'noindex-desarrollo', 'noindex-intencionado',
            'woocommerce-producto', 'prestashop-producto', 'gasolinera', 'chatbot', 'transporte-billetes',
            'video', 'inyeccion-instrucciones', 'aplicacion-js', 'robots-404', 'robots-503', 'sitemap-xxe',
        ] as $caso) {
            self::assertContains($caso, $nombres, "Falta la respuesta de ejemplo $caso");
        }
    }

    #[DataProvider('respuestas')]
    public function testLaRespuestaTieneTodosLosCamposYValoresValidos(string $nombre): void
    {
        $d = Fixtures::datos($nombre);
        foreach (self::CAMPOS as $campo) {
            self::assertArrayHasKey($campo, $d, "$nombre: falta $campo");
        }
        self::assertNotSame('', trim($d['descripcion']));
        self::assertContains($d['resultado'], ResultadoDescarga::valores());

        $c = $d['contexto'];
        if ($c['tipo_proyecto'] !== null) {
            self::assertContains($c['tipo_proyecto'], TipoProyecto::valores());
        }
        if ($c['sector'] !== null) {
            self::assertContains($c['sector'], Sector::valores());
        }

        foreach ($d['cabeceras'] as $cabecera => $_) {
            self::assertSame(strtolower($cabecera), $cabecera, "$nombre: las cabeceras van en minúsculas");
            self::assertNotSame('set-cookie', $cabecera, "$nombre: Set-Cookie no llega a la Parte 2");
        }
    }

    #[DataProvider('respuestas')]
    public function testSoloUsaDominiosReservadosParaEjemplos(string $nombre): void
    {
        // RFC 2606: .example nunca resuelve, así que ningún ejemplo apunta a una web real.
        $d = Fixtures::datos($nombre);
        $urls = [$d['url_solicitada'], $d['url_final']];
        foreach ($d['redirecciones'] as $salto) {
            $urls[] = $salto['url'];
            if ($salto['destino'] !== null) {
                $urls[] = $salto['destino'];
            }
        }
        foreach ($urls as $url) {
            self::assertStringEndsWith('.example', (string) parse_url($url, PHP_URL_HOST), "$nombre: $url");
        }
    }

    #[DataProvider('respuestas')]
    public function testLosResultadosEsperadosUsanEstadosYGravedadesValidos(string $nombre): void
    {
        $d = Fixtures::datos($nombre);
        self::assertIsArray($d['esperado']);
        foreach ($d['esperado'] as $id => $estado) {
            self::assertMatchesRegularExpression('/^(IA|SEO|ACC)-\d{2}\.[a-z0-9]+(-[a-z0-9]+)*$/', $id, $nombre);
            self::assertContains($estado, EstadoResultado::valores(), "$nombre: $id");
        }
        foreach ($d['gravedad_esperada'] ?? [] as $id => $gravedad) {
            self::assertContains($gravedad, Gravedad::valores(), "$nombre: $id");
            self::assertSame('problema', $d['esperado'][$id] ?? null, "$nombre: $id tiene gravedad pero no es problema");
        }
    }

    #[DataProvider('respuestas')]
    public function testSiLaDescargaFallaNadaDependienteSeEsperaComoCorrectoNiProblema(string $nombre): void
    {
        $d = Fixtures::datos($nombre);
        if ($d['resultado'] === 'correcto' || str_contains($d['url_solicitada'], 'robots.txt')) {
            $this->addToAssertionCount(1);
            return;
        }
        foreach ($d['esperado'] as $id => $estado) {
            self::assertSame('no_verificable', $estado, "$nombre: $id depende de una descarga fallida");
        }
    }

    #[DataProvider('respuestas')]
    public function testSeConstruyeElRecursoDescargado(string $nombre): void
    {
        $d = Fixtures::datos($nombre);
        $recurso = Fixtures::recurso($nombre);

        self::assertSame($d['url_final'], $recurso->urlFinal);
        self::assertSame($d['resultado'] === 'correcto', $recurso->esCorrecto());
        if ($d['cuerpo'] === null) {
            self::assertSame('', $recurso->cuerpo);
        } else {
            self::assertNotSame('', $recurso->cuerpo);
            self::assertSame(strlen($recurso->cuerpo), $recurso->bytes);
        }
    }

    public function testTodosLosFicherosDeCuerpoSeUsanEnAlgunaRespuesta(): void
    {
        $usados = [];
        foreach (Fixtures::nombres() as $nombre) {
            $cuerpo = Fixtures::datos($nombre)['cuerpo'];
            if ($cuerpo !== null) {
                $usados[$cuerpo] = true;
            }
        }
        foreach (['paginas', 'robots', 'sitemaps'] as $carpeta) {
            foreach (glob(Fixtures::DIR . "/$carpeta/*") ?: [] as $fichero) {
                $ruta = $carpeta . '/' . basename($fichero);
                self::assertArrayHasKey($ruta, $usados, "$ruta no lo usa ninguna respuesta");
            }
        }
    }

    public function testElContextoLlevaTipoSectorYObjetivo(): void
    {
        $contexto = Fixtures::contexto('gasolinera', Alcance::UrlContexto);

        self::assertSame(TipoProyecto::WebCliente, $contexto->tipoProyecto);
        self::assertSame(Sector::Hidrocarburos, $contexto->sector);
        self::assertSame(['www.gasolinera.example'], $contexto->hostsAutorizados);
        self::assertSame(Alcance::UrlContexto, $contexto->alcance);
        self::assertSame('no_indexar', Fixtures::contexto('noindex-intencionado')->objetivo);
    }

    public function testElProveedorFalsoDevuelveLoAsignadoYRegistraLasPeticiones(): void
    {
        $proveedor = new ProveedorFalso(['https://www.cumple.example/robots.txt' => 'robots-404']);

        $robots = $proveedor->obtener('https://www.cumple.example/robots.txt', 'robots.txt del origen', 'IA-01.robots-recuperado');
        $otra = $proveedor->obtener('https://www.cumple.example/otra/', 'enlace interno', 'SEO-07.enlaces-rotos');

        self::assertSame(404, $robots->estadoHttp);
        self::assertSame(ResultadoDescarga::FueraDeAmbito, $otra->resultado);
        self::assertSame([
            ['url' => 'https://www.cumple.example/robots.txt', 'motivo' => 'robots.txt del origen', 'idComprobacion' => 'IA-01.robots-recuperado'],
            ['url' => 'https://www.cumple.example/otra/', 'motivo' => 'enlace interno', 'idComprobacion' => 'SEO-07.enlaces-rotos'],
        ], $proveedor->peticiones());
    }

    public function testLaCancelacionFalsaSeActivaTrasLasConsultasIndicadas(): void
    {
        $token = new CancelacionFalsa(2);

        self::assertFalse($token->estaCancelado());
        self::assertFalse($token->estaCancelado());
        self::assertTrue($token->estaCancelado());
        self::assertTrue(CancelacionFalsa::yaCancelado()->estaCancelado());
        self::assertFalse(CancelacionFalsa::nunca()->estaCancelado());
    }
}
