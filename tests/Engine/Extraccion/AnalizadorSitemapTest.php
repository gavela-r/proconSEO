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

    public function testRechazaTamanioExcesivo(): void
    {
        $this->expectException(ErrorExtraccion::class);
        (new AnalizadorSitemap(50))->analizar(str_repeat(' ', 51));
    }

    public function testLeeUnSitemapComprimido(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset ' . self::NS . '><url><loc>https://www.x.example/a</loc></url><url><loc>https://www.x.example/b</loc></url></urlset>';

        $contenido = self::leer((string) gzencode($xml));

        self::assertSame(['https://www.x.example/a', 'https://www.x.example/b'], $contenido->urls());
    }

    public function testLeeUnIndiceComprimidoGrandePorPartes(): void
    {
        $entradas = '';
        for ($i = 0; $i < 3000; $i++) {
            $entradas .= '<sitemap><loc>https://www.x.example/sitemap-' . $i . '.xml.gz</loc></sitemap>';
        }
        $contenido = self::leer((string) gzencode('<sitemapindex ' . self::NS . '>' . $entradas . '</sitemapindex>'));

        self::assertTrue($contenido->esIndice());
        self::assertCount(3000, $contenido->urls());
    }

    public function testUnaBombaGzipSeCortaAlPasarElLimite(): void
    {
        // 50 MB de espacios comprimidos ocupan unos 50 KB.
        $bomba = (string) gzencode('<urlset ' . self::NS . '>' . str_repeat(' ', 50 * 1024 * 1024) . '</urlset>', 9);
        self::assertLessThan(100 * 1024, strlen($bomba));

        $antes = memory_get_peak_usage();
        $inicio = microtime(true);
        self::assertSame('demasiado_grande', self::error($bomba));
        self::assertLessThan(2.0, microtime(true) - $inicio);
        self::assertLessThan(40 * 1024 * 1024, memory_get_peak_usage() - $antes);
    }

    public function testRechazaGzipDanadoIncompletoODoble(): void
    {
        $xml = '<urlset ' . self::NS . '><url><loc>https://www.x.example/</loc></url></urlset>';
        $gz = (string) gzencode($xml);

        self::assertSame('comprimido_danado', self::error("\x1f\x8b\x08\x00resto"));
        self::assertSame('comprimido_danado', self::error(substr($gz, 0, (int) (strlen($gz) / 2))));
        self::assertSame('comprimido_danado', self::error((string) gzencode($gz)));
        self::assertSame('xml_mal_formado', self::error((string) gzencode('   ')));
    }

    public function testDentroDelGzipSiguenLasMismasDefensas(): void
    {
        $xxe = '<?xml version="1.0"?><!DOCTYPE u [<!ENTITY x SYSTEM "file:///etc/passwd">]><urlset ' . self::NS . '><url><loc>&x;</loc></url></urlset>';

        self::assertSame('dtd_no_permitido', self::error((string) gzencode($xxe)));
    }

    public function testNoSigueNingunaUrl(): void
    {
        // Las URLs fuera del ámbito se devuelven tal cual: decidir si se piden es de la Parte 1.
        $s = self::leer('<urlset ' . self::NS . '><url><loc>https://otro.example/</loc></url></urlset>');

        self::assertSame(['https://otro.example/'], $s->urls());
    }
}
