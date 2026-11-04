<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Engine\Extraccion;

use PHPUnit\Framework\TestCase;
use ProconSEO\Engine\Extraccion\ErrorExtraccion;
use ProconSEO\Engine\Extraccion\Sitemap\AnalizadorSitemap;
use ProconSEO\Engine\Extraccion\Sitemap\ContenidoSitemap;

final class AnalizadorSitemapTest extends TestCase
{
    private const NS = 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';

    private static function leer(string $xml): ContenidoSitemap
    {
        return (new AnalizadorSitemap())->analizar($xml);
    }

    private static function error(string $xml): string
    {
        try {
            self::leer($xml);
        } catch (ErrorExtraccion $e) {
            return $e->codigo;
        }
        self::fail('Se esperaba ErrorExtraccion');
    }

    public function testLeeUnUrlset(): void
    {
        $s = self::leer('<?xml version="1.0" encoding="UTF-8"?><urlset ' . self::NS . '>
            <url><loc> https://www.x.example/ </loc><lastmod>2026-10-30</lastmod></url>
            <url><loc>https://www.x.example/a</loc></url>
        </urlset>');

        self::assertSame(ContenidoSitemap::URLSET, $s->tipo);
        self::assertFalse($s->esIndice());
        self::assertSame(['https://www.x.example/', 'https://www.x.example/a'], $s->urls());
        self::assertSame('2026-10-30', $s->entradas[0]['lastmod']);
        self::assertNull($s->entradas[1]['lastmod']);
        self::assertTrue($s->contiene('https://www.x.example/a'));
        self::assertSame([], $s->avisos);
    }

    public function testLeeUnIndice(): void
    {
        $s = self::leer('<sitemapindex ' . self::NS . '><sitemap><loc>https://www.x.example/s1.xml</loc></sitemap></sitemapindex>');

        self::assertTrue($s->esIndice());
        self::assertSame(['https://www.x.example/s1.xml'], $s->urls());
    }

    public function testDescartaEntradasNoValidasConAviso(): void
    {
        $s = self::leer('<urlset ' . self::NS . '><url><loc>/relativa</loc></url><url></url><url><loc>javascript:alert(1)</loc></url></urlset>');

        self::assertSame([], $s->urls());
        self::assertCount(3, $s->avisos);
    }

    public function testAvisaSiFaltaElEspacioDeNombres(): void
    {
        $s = self::leer('<urlset><url><loc>https://www.x.example/</loc></url></urlset>');

        self::assertSame(['https://www.x.example/'], $s->urls());
        self::assertCount(1, $s->avisos);
    }

    public function testRechazaEntidadesExternasXxe(): void
    {
        self::assertSame('dtd_no_permitido', self::error('<?xml version="1.0"?>
            <!DOCTYPE urlset [<!ENTITY secreto SYSTEM "file:///etc/passwd">]>
            <urlset ' . self::NS . '><url><loc>https://www.x.example/&secreto;</loc></url></urlset>'));
    }

    public function testRechazaLaBombaDeEntidades(): void
    {
        self::assertSame('dtd_no_permitido', self::error('<?xml version="1.0"?>
            <!DOCTYPE urlset [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">]>
            <urlset ' . self::NS . '><url><loc>https://www.x.example/&b;</loc></url></urlset>'));
    }

    public function testRechazaXmlMalFormadoYRaizDesconocida(): void
    {
        self::assertSame('xml_mal_formado', self::error('<urlset><url><loc>https://www.x.example/</url>'));
        self::assertSame('xml_mal_formado', self::error(''));
        self::assertSame('formato_desconocido', self::error('<rss><channel></channel></rss>'));
    }

    public function testRechazaGzipYTamanioExcesivo(): void
    {
        self::assertSame('comprimido', self::error("\x1f\x8b\x08\x00resto"));

        $this->expectException(ErrorExtraccion::class);
        (new AnalizadorSitemap(50))->analizar(str_repeat(' ', 51));
    }

    public function testNoSigueNingunaUrl(): void
    {
        // Las URLs fuera del ámbito se devuelven tal cual: decidir si se piden es de la Parte 1.
        $s = self::leer('<urlset ' . self::NS . '><url><loc>https://otro.example/</loc></url></urlset>');

        self::assertSame(['https://otro.example/'], $s->urls());
    }
}
