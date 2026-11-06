<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Fetch\Navegador;

use PHPUnit\Framework\TestCase;
use ProconSEO\Fetch\Ambito;
use ProconSEO\Fetch\Navegador\FiltroPeticiones;
use ProconSEO\Fetch\ValidadorUrl;

final class FiltroPeticionesTest extends TestCase
{
    private static function filtro(): FiltroPeticiones
    {
        return new FiltroPeticiones(new Ambito(['ejemplo.es']), new ValidadorUrl([80, 443]));
    }

    public function testLaPaginaYLoNecesarioParaPintarla(): void
    {
        $f = self::filtro();
        self::assertSame(FiltroPeticiones::DOCUMENTO, $f->decidir('GET', 'Document', 'https://ejemplo.es/', true)['clase']);
        foreach (['Stylesheet', 'Script', 'Font'] as $tipo) {
            $d = $f->decidir('GET', $tipo, 'https://cdn.otro.net/a', false);
            self::assertTrue($d['permitir'], $tipo);
            self::assertSame(FiltroPeticiones::SUBRECURSO, $d['clase']);
            self::assertGreaterThan(0, $d['maxBytes']);
        }
        self::assertSame(FiltroPeticiones::DATOS, $f->decidir('GET', 'Fetch', 'https://ejemplo.es/api/datos', false)['clase']);
    }

    public function testNuncaEnviosNiNavegarAOtraPagina(): void
    {
        $f = self::filtro();
        foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $metodo) {
            self::assertFalse($f->decidir($metodo, 'Fetch', 'https://ejemplo.es/api', false)['permitir'], $metodo);
            self::assertFalse($f->decidir($metodo, 'Document', 'https://ejemplo.es/', true)['permitir'], "{$metodo} de formulario");
        }
        self::assertFalse($f->decidir('GET', 'Document', 'https://ejemplo.es/otra', false)['permitir'], 'Navegación tras la carga');
    }

    public function testNadaInnecesarioNiATerceros(): void
    {
        $f = self::filtro();
        self::assertFalse($f->decidir('GET', 'Fetch', 'https://analitica.otra.com/hit', false)['permitir']);
        foreach (['Image', 'Media', 'Ping', 'WebSocket', 'EventSource', 'Manifest', 'Other', 'CSPViolationReport'] as $tipo) {
            self::assertFalse($f->decidir('GET', $tipo, 'https://ejemplo.es/x', false)['permitir'], $tipo);
        }
    }

    public function testDireccionesNoValidas(): void
    {
        $f = self::filtro();
        foreach (['file:///C:/Windows/win.ini', 'ftp://ejemplo.es/a', 'https://usuario:clave@ejemplo.es/', 'https://ejemplo.es:8080/', 'http://localhost/', 'chrome://settings'] as $url) {
            self::assertFalse($f->decidir('GET', 'Script', $url, false)['permitir'], $url);
        }
    }

    public function testElAmbitoDeSubrecursosAceptaCualquierHostYElNormalNo(): void
    {
        $url = (new ValidadorUrl())->validar('https://cdn.otro.net/a.css');
        self::assertTrue(Ambito::subrecursos()->permite($url));
        self::assertFalse((new Ambito(['ejemplo.es']))->permite($url));
    }
}
