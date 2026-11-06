<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Shared;

use PHPUnit\Framework\TestCase;
use ProconSEO\Shared\Contract\ResumenPagina;
use ProconSEO\Shared\Contract\SalidaMotor;

/**
 * Contrato para las comprobaciones de todo el sitio (ConsolidadorSitio).
 */
final class ResumenPaginaTest extends TestCase
{
    public function testGuardaLoNecesarioDeCadaPagina(): void
    {
        $r = new ResumenPagina('https://www.x.example/a', 200, true, ['Título'], ['Descripción'], 'https://www.x.example/a', ['https://www.x.example/b']);

        self::assertSame('https://www.x.example/a', $r->url);
        self::assertSame(['Título'], $r->titulos);
        self::assertSame(['https://www.x.example/b'], $r->enlacesInternos);
    }

    public function testLaHuellaDelTextoYElMenuSonOpcionales(): void
    {
        $antes = new ResumenPagina('https://www.x.example/a', 200, true);
        self::assertSame([], $antes->huellaTexto);
        self::assertSame(0, $antes->palabrasTexto);
        self::assertSame([], $antes->menu);

        $r = new ResumenPagina('https://www.x.example/a', 200, true, huellaTexto: [3, 1, 4], palabrasTexto: 250, menu: ['https://www.x.example/', 'https://www.x.example/contacto']);
        self::assertSame([3, 1, 4], $r->huellaTexto);
        self::assertSame(250, $r->palabrasTexto);
        self::assertSame(['https://www.x.example/', 'https://www.x.example/contacto'], $r->menu);
    }

    public function testLaSalidaDelMotorPuedeLlevarlo(): void
    {
        $resumen = new ResumenPagina('https://www.x.example/', 200, true);

        self::assertNull((new SalidaMotor())->resumen, 'Es opcional: el código que ya existe no cambia');
        self::assertSame($resumen, (new SalidaMotor(resumen: $resumen))->resumen);
    }
}
