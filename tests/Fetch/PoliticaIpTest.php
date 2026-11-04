<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Fetch;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProconSEO\Fetch\PoliticaIp;

/**
 * SEG-02: IPs prohibidas e IPs públicas permitidas, en IPv4 e IPv6.
 */
final class PoliticaIpTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function ipsProhibidas(): iterable
    {
        yield 'loopback' => ['127.0.0.1'];
        yield 'loopback alto' => ['127.255.255.254'];
        yield '0.0.0.0' => ['0.0.0.0'];
        yield 'privada 10' => ['10.1.2.3'];
        yield 'privada 172.16' => ['172.16.0.1'];
        yield 'privada 172.31' => ['172.31.255.255'];
        yield 'privada 192.168' => ['192.168.1.1'];
        yield 'metadatos AWS/GCP/Azure' => ['169.254.169.254'];
        yield 'metadatos Alibaba' => ['100.100.100.200'];
        yield 'CGNAT' => ['100.64.0.1'];
        yield 'documentación' => ['192.0.2.10'];
        yield 'pruebas de red' => ['198.18.0.1'];
        yield 'multicast' => ['224.0.0.1'];
        yield 'broadcast' => ['255.255.255.255'];
        yield 'IPv6 loopback' => ['::1'];
        yield 'IPv6 sin especificar' => ['::'];
        yield 'IPv4 mapeada (127.0.0.1)' => ['::ffff:127.0.0.1'];
        yield 'IPv4 mapeada (pública también)' => ['::ffff:8.8.8.8'];
        yield 'IPv4 compatible' => ['::127.0.0.1'];
        yield 'NAT64' => ['64:ff9b::7f00:1'];
        yield '6to4 hacia privada' => ['2002:c0a8:0101::1'];
        yield 'Teredo' => ['2001:0:4136:e378::1'];
        yield 'documentación IPv6' => ['2001:db8::1'];
        yield 'ULA privada' => ['fd00::1'];
        yield 'metadatos AWS IPv6' => ['fd00:ec2::254'];
        yield 'link-local IPv6' => ['fe80::1'];
        yield 'multicast IPv6' => ['ff02::1'];
        yield 'texto que no es IP' => ['no-es-una-ip'];
    }

    #[DataProvider('ipsProhibidas')]
    public function testBloqueaIpsProhibidas(string $ip): void
    {
        self::assertNotNull((new PoliticaIp())->motivoBloqueo($ip), "Debería bloquear {$ip}");
    }

    /** @return iterable<string, array{string}> */
    public static function ipsPublicas(): iterable
    {
        yield 'Google DNS' => ['8.8.8.8'];
        yield 'Cloudflare' => ['1.1.1.1'];
        yield 'justo antes de 172.16' => ['172.15.255.255'];
        yield 'justo después de 172.31' => ['172.32.0.0'];
        yield 'justo antes de CGNAT' => ['100.63.255.255'];
        yield 'IPv6 pública de Cloudflare' => ['2606:4700:4700::1111'];
        yield 'IPv6 pública de Google' => ['2001:4860:4860::8888'];
    }

    #[DataProvider('ipsPublicas')]
    public function testPermiteIpsPublicas(string $ip): void
    {
        self::assertNull((new PoliticaIp())->motivoBloqueo($ip), "Debería permitir {$ip}");
    }
}
