<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Run;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProconSEO\Run\SanitizadorEvidencias;

/**
 * SEG-09: lo sensible se enmascara antes de guardarse.
 */
final class SanitizadorEvidenciasTest extends TestCase
{
    /** @return iterable<string, array{string, string}> texto => secreto que no debe quedar */
    public static function secretos(): iterable
    {
        yield 'cabecera Cookie' => ["Cookie: PHPSESSID=abc123secreto", 'abc123secreto'];
        yield 'cabecera Set-Cookie' => ["Set-Cookie: sesion=zzz999; Path=/", 'zzz999'];
        yield 'Authorization Bearer' => ['Authorization: Bearer eyJhbGciOi.payload.firma', 'eyJhbGciOi'];
        yield 'Bearer suelto' => ['token Bearer abcdefghijklmnop123', 'abcdefghijklmnop123'];
        yield 'JWT' => ['valor eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl fin', 'eyJzdWIiOiIxIn0'];
        yield 'token en URL' => ['https://ejemplo.es/?token=SECRETO123&p=2', 'SECRETO123'];
        yield 'api_key en URL' => ['https://ejemplo.es/a?x=1&api_key=CLAVE987', 'CLAVE987'];
        yield 'contraseña en JSON' => ['{"usuario":"ana","password":"hunter2"}', 'hunter2'];
        yield 'sesión en URL' => ['https://ejemplo.es/;jsessionid=ABCDEF0123', 'ABCDEF0123'];
        yield 'campo password con valor' => ['<input type="password" name="p" value="mi-clave">', 'mi-clave'];
        yield 'clave privada' => ["-----BEGIN RSA PRIVATE KEY-----\nMIIEow\n-----END RSA PRIVATE KEY-----", 'MIIEow'];
    }

    #[DataProvider('secretos')]
    public function testEnmascaraSecretos(string $texto, string $secreto): void
    {
        $limpio = (new SanitizadorEvidencias())->texto($texto);

        self::assertStringNotContainsString($secreto, $limpio);
        self::assertStringContainsString(SanitizadorEvidencias::MASCARA, $limpio);
    }

    public function testNoTocaTextoNormal(): void
    {
        $texto = '<title>Gasolinera en León</title> <a href="/contacto?ref=menu">Contacto</a>';

        self::assertSame($texto, (new SanitizadorEvidencias())->texto($texto));
    }

    public function testUrlSinCredenciales(): void
    {
        self::assertSame('https://ejemplo.es/a', (new SanitizadorEvidencias())->url('https://usuario:clave@ejemplo.es/a'));
    }
}
