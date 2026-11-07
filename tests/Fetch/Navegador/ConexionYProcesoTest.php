<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Fetch\Navegador;

use PHPUnit\Framework\TestCase;
use ProconSEO\Fetch\Navegador\ConexionCdp;
use ProconSEO\Fetch\Navegador\ProcesoNavegador;

final class ConexionYProcesoTest extends TestCase
{
    /** @return array{0: bool, 1: int, 2: string} */
    private static function leer(string $bytes): array
    {
        $pos = 0;

        return ConexionCdp::leerTrama(static function (int $n) use ($bytes, &$pos): string {
            $trozo = substr($bytes, $pos, $n);
            $pos += $n;

            return $trozo;
        });
    }

    public function testTramasDeIdaYVueltaConLosTresTamanos(): void
    {
        foreach ([0, 5, 125, 126, 65535, 65536, 300_000] as $largo) {
            $carga = str_repeat('áb', intdiv($largo, 3)) . str_repeat('x', $largo - 3 * intdiv($largo, 3));
            $trama = ConexionCdp::trama(1, $carga, "\x01\x02\x03\x04");
            self::assertSame(0x81, ord($trama[0]));
            self::assertNotSame(0, ord($trama[1]) & 0x80, 'El cliente siempre enmascara');
            [$fin, $codigo, $leida] = self::leer($trama);
            self::assertTrue($fin);
            self::assertSame(1, $codigo);
            self::assertSame($carga, $leida, "largo {$largo}");
        }
    }

    public function testTramaDelServidorSinMascara(): void
    {
        [$fin, $codigo, $carga] = self::leer("\x81\x05hola!");
        self::assertSame([true, 1, 'hola!'], [$fin, $codigo, $carga]);
    }

    public function testArrancaSinRedPropiaYConLaSandboxActiva(): void
    {
        $args = ProcesoNavegador::argumentos('C:\\tmp\\perfil');

        self::assertContains('--host-resolver-rules=MAP * ~NOTFOUND , EXCLUDE 127.0.0.1', $args, 'No resuelve nombres por su cuenta');
        self::assertContains('--proxy-server=http://127.0.0.1:9', $args, 'Lo que se escape va a un puerto cerrado');
        self::assertContains('--remote-debugging-address=127.0.0.1', $args);
        self::assertContains('--user-data-dir=C:\\tmp\\perfil', $args);
        self::assertContains('--block-new-web-contents', $args);
        self::assertContains('--deny-permission-prompts', $args);
        foreach ($args as $a) {
            self::assertStringNotContainsString('no-sandbox', $a, 'La sandbox nunca se desactiva');
            self::assertStringNotContainsString('disable-web-security', $a);
            self::assertStringNotContainsString('ignore-certificate-errors', $a);
        }
    }

    public function testRutaConfiguradaQueNoExiste(): void
    {
        self::assertNull(ProcesoNavegador::buscarEjecutable('C:\\no\\existe\\chrome.exe'));
    }
}
