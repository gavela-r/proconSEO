<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Fetch;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProconSEO\Fetch\DestinoRechazado;
use ProconSEO\Fetch\ValidadorUrl;

/**
 * SEG-01: URLs válidas que se aceptan y URLs trampa que se rechazan antes de conectar.
 */
final class ValidadorUrlTest extends TestCase
{
    /** @return iterable<string, array{string, string}> entrada => URL normalizada */
    public static function urlsValidas(): iterable
    {
        yield 'https simple' => ['https://www.proconsi.com', 'https://www.proconsi.com/'];
        yield 'mayúsculas en esquema y host' => ['HTTPS://WWW.Proconsi.COM/Ruta', 'https://www.proconsi.com/Ruta'];
        yield 'quita el fragmento' => ['https://ejemplo.es/pagina#seccion', 'https://ejemplo.es/pagina'];
        yield 'conserva la consulta' => ['https://ejemplo.es/buscar?q=gasolina&p=2', 'https://ejemplo.es/buscar?q=gasolina&p=2'];
        yield 'consulta sin ruta' => ['https://ejemplo.es?x=1', 'https://ejemplo.es/?x=1'];
        yield 'quita el puerto por defecto' => ['https://ejemplo.es:443/', 'https://ejemplo.es/'];
        yield 'http en el 80' => ['http://ejemplo.es:80/a', 'http://ejemplo.es/a'];
        yield 'punto final del dominio' => ['https://ejemplo.es./', 'https://ejemplo.es/'];
        yield 'dominio con eñe (IDN)' => ['https://españa.es/', 'https://xn--espaa-rta.es/'];
        yield 'ruta con tildes' => ['https://ejemplo.es/categoría', 'https://ejemplo.es/categor%C3%ADa'];
        yield 'espacios alrededor' => ['  https://ejemplo.es/  ', 'https://ejemplo.es/'];
        yield 'IPv4 pública literal' => ['http://8.8.8.8/', 'http://8.8.8.8/'];
        yield 'IPv6 literal' => ['https://[2606:4700:4700::1111]/', 'https://[2606:4700:4700::1111]/'];
    }

    #[DataProvider('urlsValidas')]
    public function testAceptaYNormaliza(string $entrada, string $esperada): void
    {
        self::assertSame($esperada, (new ValidadorUrl())->validar($entrada)->url());
    }

    /** @return iterable<string, array{string, string}> entrada => código de error */
    public static function urlsTrampa(): iterable
    {
        yield 'vacía' => ['', 'url_vacia'];
        yield 'sin esquema' => ['www.ejemplo.es', 'url_sin_esquema'];
        yield 'file://' => ['file:///etc/passwd', 'url_esquema_no_permitido'];
        yield 'ftp://' => ['ftp://ejemplo.es/', 'url_esquema_no_permitido'];
        yield 'gopher://' => ['gopher://127.0.0.1:6379/_FLUSHALL', 'url_esquema_no_permitido'];
        yield 'javascript:' => ['javascript:alert(1)', 'url_esquema_no_permitido'];
        yield 'data:' => ['data:text/html,<script>', 'url_esquema_no_permitido'];
        yield 'usuario y contraseña' => ['https://usuario:clave@ejemplo.es/', 'url_con_credenciales'];
        yield 'arroba engañosa' => ['https://ejemplo.es@127.0.0.1/', 'url_con_credenciales'];
        yield 'barra invertida' => ['https://ejemplo.es\\@127.0.0.1/', 'url_caracteres_no_validos'];
        yield 'espacio en medio' => ['https://ejem plo.es/', 'url_caracteres_no_validos'];
        yield 'salto de línea' => ["https://ejemplo.es/\r\nHost: interno", 'url_caracteres_no_validos'];
        yield 'byte nulo' => ["https://ejemplo.es\0.evil.com/", 'url_caracteres_no_validos'];
        yield 'puerto no permitido' => ['https://ejemplo.es:8080/', 'url_puerto_no_permitido'];
        yield 'puerto de Redis' => ['http://ejemplo.es:6379/', 'url_puerto_no_permitido'];
        yield 'puerto no numérico' => ['https://ejemplo.es:abc/', 'url_puerto_no_valido'];
        yield 'puerto fuera de rango' => ['https://ejemplo.es:99999/', 'url_puerto_no_valido'];
        yield 'sin host' => ['https:///ruta', 'url_sin_host'];
        yield 'IP en decimal' => ['http://2130706433/', 'url_ip_ambigua'];
        yield 'IP en octal' => ['http://0177.0.0.1/', 'url_ip_ambigua'];
        yield 'IP en hexadecimal' => ['http://0x7f000001/', 'url_ip_ambigua'];
        yield 'IP abreviada' => ['http://127.1/', 'url_ip_ambigua'];
        yield 'IP con partes hex' => ['http://0x7f.0.0.1/', 'url_ip_ambigua'];
        yield 'IP con octeto > 255' => ['http://1.2.3.256/', 'url_ip_ambigua'];
        yield 'IPv6 sin corchetes' => ['http://::1/', 'url_host_no_valido'];
        yield 'IPv6 con zona' => ['http://[fe80::1%25eth0]/', 'url_host_no_valido'];
        yield 'IPv6 mal escrita' => ['http://[zz::1]/', 'url_host_no_valido'];
        yield 'host codificado con %' => ['http://%31%32%37.0.0.1/', 'url_host_no_valido'];
        yield 'localhost' => ['http://localhost/', 'url_host_sin_dominio'];
        yield 'subdominio de localhost' => ['http://app.localhost/', 'url_host_local'];
        yield 'nombre de una sola palabra' => ['http://intranet/', 'url_host_sin_dominio'];
        yield 'dominio .local' => ['http://impresora.local/', 'url_host_local'];
        yield 'dominio .internal' => ['http://metadata.google.internal/', 'url_host_local'];
        yield 'guion al principio' => ['https://-ejemplo.es/', 'url_host_no_valido'];
        yield 'guion bajo' => ['https://mi_web.es/', 'url_host_no_valido'];
        yield 'demasiado larga' => ['https://ejemplo.es/' . str_repeat('a', 2100), 'url_demasiado_larga'];
    }

    #[DataProvider('urlsTrampa')]
    public function testRechazaUrlTrampa(string $entrada, string $codigo): void
    {
        try {
            (new ValidadorUrl())->validar($entrada);
            self::fail("Se ha aceptado una URL que debería rechazarse: {$entrada}");
        } catch (DestinoRechazado $e) {
            self::assertSame($codigo, $e->codigo);
            self::assertSame('SEG-01', $e->regla);
            self::assertSame(422, $e->estadoHttp);
        }
    }

    public function testPuertosPermitidosSeConfiguranDesdeElPerfil(): void
    {
        $validador = new ValidadorUrl([80, 443, 8443]);

        self::assertSame(8443, $validador->validar('https://ejemplo.es:8443/')->puerto);
    }

    public function testOrigenParaRobotsTxt(): void
    {
        self::assertSame('https://ejemplo.es', (new ValidadorUrl())->validar('https://ejemplo.es/a/b?c=1')->origen());
    }
}
