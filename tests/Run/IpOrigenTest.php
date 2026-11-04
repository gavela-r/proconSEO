<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Run;

use PHPUnit\Framework\TestCase;
use ProconSEO\Run\IpOrigen;

final class IpOrigenTest extends TestCase
{
    public function testSinProxyUsaLaIpDeLaConexion(): void
    {
        self::assertSame('203.0.113.5', IpOrigen::desde(['REMOTE_ADDR' => '203.0.113.5']));
    }

    public function testIgnoraXForwardedForSiNoVieneDeUnProxyDeConfianza(): void
    {
        $servidor = ['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'];

        self::assertSame('203.0.113.5', IpOrigen::desde($servidor, ['10.0.0.1']));
    }

    public function testDetrasDeProxyDeConfianzaTomaLaIpDelCliente(): void
    {
        // El cliente puede inventarse lo de la izquierda; vale la última IP antes de nuestros proxies.
        $servidor = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.7, 10.0.0.2'];

        self::assertSame('198.51.100.7', IpOrigen::desde($servidor, ['10.0.0.1', '10.0.0.2']));
    }

    public function testCabeceraMalFormadaSeQuedaConLaDelProxy(): void
    {
        $servidor = ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'no-es-una-ip'];

        self::assertSame('10.0.0.1', IpOrigen::desde($servidor, ['10.0.0.1']));
    }

    public function testNormalizaIpv6YDescartaBasura(): void
    {
        self::assertSame('2001:db8::1', IpOrigen::desde(['REMOTE_ADDR' => '2001:0db8:0000::0001']));
        self::assertNull(IpOrigen::desde(['REMOTE_ADDR' => 'basura']));
        self::assertSame(['10.0.0.1'], IpOrigen::proxies(' 10.0.0.1, , nada'));
    }
}
