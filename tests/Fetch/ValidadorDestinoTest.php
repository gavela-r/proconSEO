<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Fetch;

use PHPUnit\Framework\TestCase;
use ProconSEO\Fetch\DestinoRechazado;
use ProconSEO\Fetch\ResolvedorDns;
use ProconSEO\Fetch\ValidadorDestino;
use ProconSEO\Fetch\ValidadorUrl;

/**
 * SEG-02: un dominio de apariencia normal puede apuntar a una IP interna. Se comprueba con
 * un DNS simulado, sin hacer ninguna petición real.
 */
final class ValidadorDestinoTest extends TestCase
{
    /** @param array<string, list<string>> $tabla dominio => IPs */
    private function validador(array $tabla): ValidadorDestino
    {
        $dns = new class ($tabla) implements ResolvedorDns {
            /** @var list<string> */
            public array $consultas = [];

            public function __construct(private readonly array $tabla)
            {
            }

            public function resolver(string $host): array
            {
                $this->consultas[] = $host;

                return $this->tabla[$host] ?? [];
            }
        };

        return new ValidadorDestino(new ValidadorUrl(), $dns);
    }

    public function testDominioPublicoSeAceptaYSeFijaLaIp(): void
    {
        $destino = $this->validador(['www.proconsi.com' => ['93.184.216.34']])->validar('https://www.proconsi.com/');

        self::assertSame('93.184.216.34', $destino->ipConexion);
        self::assertSame(['www.proconsi.com:443:93.184.216.34'], $destino->opcionCurlResolve());
    }

    public function testIpv6SeFijaEntreCorchetes(): void
    {
        $destino = $this->validador(['ejemplo.es' => ['2606:4700:4700::1111']])->validar('https://ejemplo.es/');

        self::assertSame(['ejemplo.es:443:[2606:4700:4700::1111]'], $destino->opcionCurlResolve());
    }

    public function testDominioQueApuntaALocalhostSeRechaza(): void
    {
        $this->esperarBloqueo();
        $this->validador(['trampa.ejemplo.es' => ['127.0.0.1']])->validar('https://trampa.ejemplo.es/');
    }

    public function testDominioQueApuntaAMetadatosCloudSeRechaza(): void
    {
        $this->esperarBloqueo();
        $this->validador(['metadatos.ejemplo.es' => ['169.254.169.254']])->validar('http://metadatos.ejemplo.es/latest/meta-data/');
    }

    public function testDominioConIpPublicaYPrivadaSeRechazaEntero(): void
    {
        $this->esperarBloqueo();
        $this->validador(['mixto.ejemplo.es' => ['93.184.216.34', '10.0.0.5']])->validar('https://mixto.ejemplo.es/');
    }

    public function testDominioConIpv6InternaSeRechaza(): void
    {
        $this->esperarBloqueo();
        $this->validador(['v6.ejemplo.es' => ['93.184.216.34', '::1']])->validar('https://v6.ejemplo.es/');
    }

    public function testIpLiteralPrivadaSeRechazaSinConsultarDns(): void
    {
        $this->esperarBloqueo();
        $this->validador([])->validar('http://192.168.1.1/');
    }

    public function testIpv6LiteralLoopbackSeRechaza(): void
    {
        $this->esperarBloqueo();
        $this->validador([])->validar('http://[::1]/');
    }

    public function testIpv4MapeadaEnIpv6SeRechaza(): void
    {
        $this->esperarBloqueo();
        $this->validador([])->validar('http://[::ffff:127.0.0.1]/');
    }

    public function testDominioQueNoResuelveSeRechaza(): void
    {
        try {
            $this->validador([])->validar('https://no-existe.ejemplo.es/');
            self::fail('Debería rechazarse');
        } catch (DestinoRechazado $e) {
            self::assertSame('dns_sin_resultado', $e->codigo);
        }
    }

    public function testCadaLlamadaVuelveAConsultarElDns(): void
    {
        // Contra el DNS rebinding: no se reutiliza una resolución anterior.
        $tabla = ['cambia.ejemplo.es' => ['93.184.216.34']];
        $dns = new class ($tabla) implements ResolvedorDns {
            public int $llamadas = 0;

            public function __construct(private array $tabla)
            {
            }

            public function resolver(string $host): array
            {
                ++$this->llamadas;
                // La segunda vez, el atacante ha cambiado el DNS a una IP interna.
                return $this->llamadas === 1 ? $this->tabla[$host] : ['127.0.0.1'];
            }
        };
        $validador = new ValidadorDestino(new ValidadorUrl(), $dns);

        $validador->validar('https://cambia.ejemplo.es/');
        $this->esperarBloqueo();
        $validador->validar('https://cambia.ejemplo.es/');
    }

    private function esperarBloqueo(): void
    {
        $this->expectException(DestinoRechazado::class);
        $this->expectExceptionMessageMatches('/no permitida/');
    }
}
