<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Fetch\Navegador;

use PHPUnit\Framework\TestCase;
use ProconSEO\Fetch\Navegador\InformeHtml;
use ProconSEO\Fetch\Navegador\LecturaProvisional;
use ProconSEO\Fetch\Navegador\PaginaRenderizada;

final class InformeHtmlTest extends TestCase
{
    public function testEscapaTodoLoQueVieneDeLaWeb(): void
    {
        $malo = '<script>alert(1)</script>';
        $p = new PaginaRenderizada(
            url: 'https://ejemplo.es/"><img src=x onerror=alert(1)>',
            contraste: ['muestras' => [['texto' => $malo, 'selector' => 'p' . $malo, 'color' => [200, 200, 200, 1], 'opacidad' => 1, 'capas' => [], 'motivo' => null, 'tamano' => 16, 'peso' => 400]], 'tapados' => 0, 'fueraDeVista' => 0, 'inactivos' => 0, 'elementos' => 1],
            peticiones: [['url' => 'https://ejemplo.es/' . $malo, 'tipo' => 'Script', 'permitida' => false, 'motivo' => $malo]],
            limitaciones: [$malo],
        );

        $html = InformeHtml::generar($p, LecturaProvisional::leer($p));

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('Problema', $html, 'El contraste flojo sale como problema');
    }

    public function testPaginaQueNoSePudoPintar(): void
    {
        $p = new PaginaRenderizada('https://ejemplo.es/', error: 'bloqueado por seguridad');
        $html = InformeHtml::generar($p, LecturaProvisional::leer($p));

        self::assertStringContainsString('bloqueado por seguridad', $html);
        self::assertStringContainsString('No verificable', $html);
    }
}
