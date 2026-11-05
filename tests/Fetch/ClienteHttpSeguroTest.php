<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Fetch;

use PHPUnit\Framework\TestCase;
use ProconSEO\Fetch\Ambito;
use ProconSEO\Fetch\ClienteHttpSeguro;
use ProconSEO\Fetch\Http\PeticionHttp;
use ProconSEO\Fetch\Http\RespuestaHttp;
use ProconSEO\Fetch\Http\TransporteHttp;
use ProconSEO\Fetch\LimitesDescarga;
use ProconSEO\Fetch\Reloj;
use ProconSEO\Fetch\ResolvedorDns;
use ProconSEO\Fetch\ValidadorDestino;
use ProconSEO\Fetch\ValidadorUrl;
use ProconSEO\Shared\Enum\ResultadoDescarga;

/**
 * Cliente HTTP seguro con transporte, DNS y reloj simulados: ninguna petición real.
 */
final class ClienteHttpSeguroTest extends TestCase
{
    private TransporteFalso $transporte;
    private DnsFalso $dns;
    private RelojFalso $reloj;

    protected function setUp(): void
    {
        $this->transporte = new TransporteFalso();
        $this->dns = new DnsFalso([
            'ejemplo.es' => ['93.184.216.34'],
            'www.ejemplo.es' => ['93.184.216.34'],
            'otro.es' => ['93.184.216.35'],
            'interno.ejemplo.es' => ['10.0.0.1'],
        ]);
        $this->reloj = new RelojFalso();
    }

    /** @param list<string> $hosts */
    private function cliente(array $hosts = ['ejemplo.es'], ?LimitesDescarga $limites = null): ClienteHttpSeguro
    {
        $limites ??= new LimitesDescarga();

        return new ClienteHttpSeguro(
            $this->transporte,
            new ValidadorDestino(new ValidadorUrl($limites->puertosPermitidos), $this->dns),
            new Ambito($hosts),
            $limites,
            $this->reloj,
        );
    }

    public function testDescargaCorrectaConIpFijadaYSinCookies(): void
    {
        $this->transporte->responder(new RespuestaHttp(200, ['content-type' => 'text/html', 'set-cookie' => 'sesion=secreta'], '<html>hola</html>'));

        $r = $this->cliente()->obtener('https://ejemplo.es/', 'página principal');

        self::assertSame(ResultadoDescarga::Correcto, $r->resultado);
        self::assertSame('<html>hola</html>', $r->cuerpo);
        self::assertNull($r->cabecera('set-cookie'), 'Las cookies de la web no se guardan');
        self::assertSame(['ejemplo.es:443:93.184.216.34'], $this->transporte->peticiones[0]->destino->opcionCurlResolve());
    }

    public function testSigueRedireccionDentroDelAmbito(): void
    {
        $this->transporte->responder(new RespuestaHttp(301, ['location' => 'https://ejemplo.es/inicio']));
        $this->transporte->responder(new RespuestaHttp(200, [], 'ok'));

        $r = $this->cliente()->obtener('http://ejemplo.es/', 'página principal');

        self::assertSame(ResultadoDescarga::Correcto, $r->resultado);
        self::assertSame('https://ejemplo.es/inicio', $r->urlFinal);
        self::assertCount(1, $r->redirecciones);
        self::assertSame(301, $r->redirecciones[0]['estado']);
    }

    public function testRedireccionRelativa(): void
    {
        $this->transporte->responder(new RespuestaHttp(302, ['location' => 'contacto']));
        $this->transporte->responder(new RespuestaHttp(200, [], 'ok'));

        $r = $this->cliente()->obtener('https://ejemplo.es/empresa/quienes', 'página');

        self::assertSame('https://ejemplo.es/empresa/contacto', $r->urlFinal);
    }

    public function testRedireccionAUnHostQueApuntaARedInternaSeBloqueaSinConectar(): void
    {
        $this->transporte->responder(new RespuestaHttp(302, ['location' => 'https://interno.ejemplo.es/admin']));

        $r = $this->cliente(['ejemplo.es', 'interno.ejemplo.es'])->obtener('https://ejemplo.es/', 'página');

        self::assertSame(ResultadoDescarga::BloqueadoSeguridad, $r->resultado);
        self::assertCount(1, $this->transporte->peticiones, 'No debe conectar al destino interno');
    }

    public function testRedireccionALocalhostSeBloquea(): void
    {
        $this->transporte->responder(new RespuestaHttp(302, ['location' => 'http://127.0.0.1/']));

        $r = $this->cliente(['ejemplo.es', '127.0.0.1'])->obtener('https://ejemplo.es/', 'página');

        self::assertSame(ResultadoDescarga::BloqueadoSeguridad, $r->resultado);
        self::assertCount(1, $this->transporte->peticiones);
    }

    public function testRedireccionAFileSeBloquea(): void
    {
        $this->transporte->responder(new RespuestaHttp(302, ['location' => 'file:///etc/passwd']));

        $r = $this->cliente()->obtener('https://ejemplo.es/', 'página');

        self::assertSame(ResultadoDescarga::BloqueadoSeguridad, $r->resultado);
    }

    public function testRedireccionFueraDelAmbitoNoSeSigue(): void
    {
        $this->transporte->responder(new RespuestaHttp(302, ['location' => 'https://otro.es/']));

        $r = $this->cliente()->obtener('https://ejemplo.es/', 'página');

        self::assertSame(ResultadoDescarga::FueraDeAmbito, $r->resultado);
        self::assertCount(1, $this->transporte->peticiones);
        self::assertSame('https://otro.es/', $r->redirecciones[0]['destino'], 'Se registra el destino');
    }

    public function testWwwNoEstaIncluidoAutomaticamente(): void
    {
        $r = $this->cliente(['ejemplo.es'])->obtener('https://www.ejemplo.es/', 'página');

        self::assertSame(ResultadoDescarga::FueraDeAmbito, $r->resultado);
        self::assertCount(0, $this->transporte->peticiones);
    }

    public function testBucleDeRedirecciones(): void
    {
        $this->transporte->responder(new RespuestaHttp(302, ['location' => 'https://ejemplo.es/b']));
        $this->transporte->responder(new RespuestaHttp(302, ['location' => 'https://ejemplo.es/a']));

        $r = $this->cliente()->obtener('https://ejemplo.es/a', 'página');

        self::assertSame(ResultadoDescarga::ErrorHttp, $r->resultado);
        self::assertStringContainsString('Bucle', (string) $r->motivo);
    }

    public function testDemasiadasRedirecciones(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->transporte->responder(new RespuestaHttp(302, ['location' => "https://ejemplo.es/{$i}"]));
        }

        $r = $this->cliente(limites: new LimitesDescarga(maxRedirecciones: 3))->obtener('https://ejemplo.es/0', 'página');

        self::assertSame(ResultadoDescarga::ErrorHttp, $r->resultado);
        self::assertStringContainsString('redirecciones', (string) $r->motivo);
        self::assertCount(4, $this->transporte->peticiones);
    }

    public function testRecursoTruncadoPorTamano(): void
    {
        $this->transporte->responder(new RespuestaHttp(200, [], str_repeat('a', 100), truncado: true));

        $r = $this->cliente()->obtener('https://ejemplo.es/', 'página');

        self::assertSame(ResultadoDescarga::DemasiadoGrande, $r->resultado);
        self::assertTrue($r->truncado);
    }

    public function testRobotsUsaSuPropioLimite(): void
    {
        $this->transporte->responder(new RespuestaHttp(200, [], 'User-agent: *'));

        $this->cliente(limites: new LimitesDescarga(maxBytesRobots: 1234))->obtener('https://ejemplo.es/robots.txt', 'robots', 'IA-01.robots-crawlers');

        self::assertSame(1234, $this->transporte->peticiones[0]->maxBytes);
    }

    public function testErrorTlsNoSeReintentaNiSeIgnora(): void
    {
        $this->transporte->responder(new RespuestaHttp(0, error: RespuestaHttp::ERROR_TLS, detalleError: 'certificate has expired'));

        $r = $this->cliente()->obtener('https://ejemplo.es/', 'página');

        self::assertSame(ResultadoDescarga::ErrorTls, $r->resultado);
        self::assertCount(1, $this->transporte->peticiones);
    }

    public function testErrorDeRedSeReintentaConEspera(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->transporte->responder(new RespuestaHttp(0, error: RespuestaHttp::ERROR_RED, detalleError: 'Connection refused'));
        }

        $r = $this->cliente()->obtener('https://ejemplo.es/', 'página');

        self::assertSame(ResultadoDescarga::ErrorRed, $r->resultado);
        self::assertCount(3, $this->transporte->peticiones, '1 intento + 2 reintentos');
        self::assertContains(2.0, $this->reloj->esperas);
        self::assertContains(4.0, $this->reloj->esperas);
    }

    public function testError503SeRecuperaAlReintentar(): void
    {
        $this->transporte->responder(new RespuestaHttp(503));
        $this->transporte->responder(new RespuestaHttp(200, [], 'ok'));

        $r = $this->cliente()->obtener('https://ejemplo.es/', 'página');

        self::assertSame(ResultadoDescarga::Correcto, $r->resultado);
    }

    public function test429RespetaRetryAfter(): void
    {
        $this->transporte->responder(new RespuestaHttp(429, ['retry-after' => '3']));
        $this->transporte->responder(new RespuestaHttp(200, [], 'ok'));

        $r = $this->cliente()->obtener('https://ejemplo.es/', 'página');

        self::assertSame(ResultadoDescarga::Correcto, $r->resultado);
        self::assertContains(3.0, $this->reloj->esperas);
        self::assertSame($this->transporte->peticiones[0]->userAgent, $this->transporte->peticiones[1]->userAgent, 'No cambia de identidad');
    }

    public function test429ConEsperaExcesivaSeCierraComoLimitado(): void
    {
        $this->transporte->responder(new RespuestaHttp(429, ['retry-after' => '3600']));

        $r = $this->cliente()->obtener('https://ejemplo.es/', 'página');

        self::assertSame(ResultadoDescarga::Limitado429, $r->resultado);
        self::assertCount(1, $this->transporte->peticiones);
        self::assertNotContains(3600.0, $this->reloj->esperas);
    }

    public function testCadaReintentoVuelveAValidarElDns(): void
    {
        // Primer intento falla; antes del segundo, el atacante cambia el DNS a una IP interna.
        $this->transporte->responder(new RespuestaHttp(0, error: RespuestaHttp::ERROR_RED));
        $this->transporte->alEnviar = fn () => $this->dns->tabla['ejemplo.es'] = ['127.0.0.1'];

        $r = $this->cliente()->obtener('https://ejemplo.es/', 'página');

        self::assertSame(ResultadoDescarga::BloqueadoSeguridad, $r->resultado);
        self::assertCount(1, $this->transporte->peticiones, 'El segundo intento no llega a conectar');
    }

    public function testRitmoDeUnaPeticionPorSegundoPorHost(): void
    {
        $this->transporte->responder(new RespuestaHttp(200, [], 'a'));
        $this->transporte->responder(new RespuestaHttp(200, [], 'b'));
        $cliente = $this->cliente();

        $cliente->obtener('https://ejemplo.es/a', 'página');
        $cliente->obtener('https://ejemplo.es/b', 'página');

        self::assertEqualsWithDelta(1.0, array_sum($this->reloj->esperas), 0.01);
    }

    public function testVariasALaVezConLasMismasReglasYEnOrden(): void
    {
        $this->transporte->porUrl = [
            'https://ejemplo.es/a' => new RespuestaHttp(200, ['content-type' => 'text/html'], 'a'),
            'https://ejemplo.es/vieja' => new RespuestaHttp(301, ['location' => '/b']),
            'https://ejemplo.es/b' => new RespuestaHttp(200, ['content-type' => 'text/html'], 'b'),
        ];
        $cliente = $this->cliente(limites: new LimitesDescarga(peticionesPorSegundoHost: 3));

        $r = $cliente->obtenerVarias([
            ['https://ejemplo.es/a', 'enlace'],
            ['https://ejemplo.es/vieja', 'enlace', 'SEO-07.enlaces-rotos'],
            ['https://otro.es/', 'enlace'],
        ]);

        self::assertSame('a', $r[0]->cuerpo);
        self::assertSame('https://ejemplo.es/b', $r[1]->urlFinal, 'Sigue la redirección como obtener()');
        self::assertSame('b', $r[1]->cuerpo);
        self::assertSame(ResultadoDescarga::FueraDeAmbito, $r[2]->resultado, 'Fuera del ámbito no se conecta');
        self::assertCount(3, $this->transporte->peticiones);
        self::assertSame(2, $this->transporte->tandas, 'Las dos primeras salen juntas; la redirección, en la siguiente tanda');
        self::assertCount(3, $cliente->registro(), 'Todo queda en el registro, como siempre');
    }

    public function testEnParaleloSeRespetaElRitmoPorHost(): void
    {
        $urls = array_map(static fn (int $i): string => "https://ejemplo.es/{$i}", range(1, 7));
        foreach ($urls as $u) {
            $this->transporte->porUrl[$u] = new RespuestaHttp(200, [], 'ok');
        }
        $inicios = [];
        $this->transporte->alEnviar = function () use (&$inicios): void {
            $inicios[] = $this->reloj->ahora();
        };
        $cliente = $this->cliente(limites: new LimitesDescarga(peticionesPorSegundoHost: 3));

        $cliente->obtenerVarias(array_map(static fn (string $u): array => [$u, 'enlace'], $urls));

        self::assertSame([1000.0, 1000.0, 1000.0, 1001.0, 1001.0, 1001.0, 1002.0], $inicios, 'Como mucho 3 empiezan en cada segundo');
        self::assertSame(3, $this->transporte->tandas);
    }

    public function testConUnaPorSegundoEnParaleloVanDeUnaEnUna(): void
    {
        foreach (['a', 'b'] as $u) {
            $this->transporte->porUrl["https://ejemplo.es/{$u}"] = new RespuestaHttp(200, [], $u);
        }

        $this->cliente()->obtenerVarias([['https://ejemplo.es/a', 'x'], ['https://ejemplo.es/b', 'x']]);

        self::assertEqualsWithDelta(1.0, array_sum($this->reloj->esperas), 0.01);
        self::assertSame(2, $this->transporte->tandas);
    }

    public function testRedireccionAAlgoYaDescargadoNoLoVuelveAPedir(): void
    {
        $this->transporte->porUrl['http://ejemplo.es/a'] = new RespuestaHttp(301, ['location' => 'https://ejemplo.es/a']);
        $cliente = $this->cliente();
        $yaDescargada = new \ProconSEO\Shared\Contract\RecursoDescargado('https://ejemplo.es/a', 'https://ejemplo.es/a', ResultadoDescarga::Correcto, 200, cuerpo: 'a');
        $cliente->usarMemoria(static fn (string $u): ?\ProconSEO\Shared\Contract\RecursoDescargado => $u === 'https://ejemplo.es/a' ? $yaDescargada : null);

        $r = $cliente->obtener('http://ejemplo.es/a', 'variante');

        self::assertCount(1, $this->transporte->peticiones, 'Solo se pide la redirección');
        self::assertSame('https://ejemplo.es/a', $r->urlFinal);
        self::assertSame('a', $r->cuerpo);
        self::assertSame(301, $r->redirecciones[0]['estado'], 'La redirección queda registrada igual');
    }

    public function testLaMemoriaNoSaltaseLaValidacionDeLaRedireccion(): void
    {
        $this->transporte->porUrl['https://ejemplo.es/a'] = new RespuestaHttp(301, ['location' => 'https://interno.ejemplo.es/']);
        $cliente = $this->cliente(['ejemplo.es', 'interno.ejemplo.es']);
        $cliente->usarMemoria(static fn (string $u): \ProconSEO\Shared\Contract\RecursoDescargado => new \ProconSEO\Shared\Contract\RecursoDescargado($u, $u, ResultadoDescarga::Correcto, 200));

        $r = $cliente->obtener('https://ejemplo.es/a', 'enlace');

        self::assertNotSame(ResultadoDescarga::Correcto, $r->resultado, 'Una IP interna se rechaza antes de mirar la memoria');
    }

    public function testPresupuestoDelAnalisisAgotado(): void
    {
        $this->transporte->responder(new RespuestaHttp(200, [], str_repeat('a', 100)));
        $cliente = $this->cliente(limites: new LimitesDescarga(maxBytesAnalisis: 100));

        $cliente->obtener('https://ejemplo.es/a', 'página');
        $r = $cliente->obtener('https://ejemplo.es/b', 'página');

        self::assertSame(ResultadoDescarga::DemasiadoGrande, $r->resultado);
        self::assertStringContainsString('presupuesto', (string) $r->motivo);
        self::assertCount(1, $this->transporte->peticiones);
    }

    public function testError404ConservaElCuerpo(): void
    {
        $this->transporte->responder(new RespuestaHttp(404, [], 'No encontrado'));

        $r = $this->cliente()->obtener('https://ejemplo.es/no-existe', 'página');

        self::assertSame(ResultadoDescarga::ErrorHttp, $r->resultado);
        self::assertSame(404, $r->estadoHttp);
        self::assertSame('No encontrado', $r->cuerpo);
    }

    public function testRegistroIncluyeBloqueosYJustificacion(): void
    {
        $cliente = $this->cliente();
        $cliente->obtener('https://interno.ejemplo.es/', 'sitemap', 'IA-08.sitemap');

        $registro = $cliente->registro();

        self::assertCount(1, $registro);
        self::assertSame(ResultadoDescarga::BloqueadoSeguridad, $registro[0]['recurso']->resultado);
        self::assertSame('IA-08.sitemap', $registro[0]['comprobacion']);
    }
}
