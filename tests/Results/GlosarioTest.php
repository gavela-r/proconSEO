<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Results;

use PHPUnit\Framework\TestCase;
use ProconSEO\Results\Glosario;

/**
 * Glosario: los términos técnicos se conservan y se enlazan a su definición, sin dejar pasar
 * nunca HTML del texto original (SEG-09).
 */
final class GlosarioTest extends TestCase
{
    private static function glosario(): Glosario
    {
        return new Glosario([
            ['termino' => 'noindex', 'variantes' => ['no indexar'], 'definicion' => 'Pide que no se indexe.'],
            ['termino' => 'robots.txt', 'definicion' => 'Fichero con permisos de rastreo.'],
            ['termino' => 'meta robots', 'definicion' => 'Etiqueta <meta name="robots">.'],
            ['termino' => 'indexación', 'definicion' => 'Guardar en el índice.'],
        ]);
    }

    public function testEnlazaLaPrimeraAparicionConSuDefinicion(): void
    {
        $html = self::glosario()->enlazar('La página tiene noindex. Quitar el noindex de la plantilla.');

        self::assertSame(1, substr_count($html, '<a class="termino"'), 'Solo la primera vez');
        self::assertMatchesRegularExpression('#<span class="pista"><a class="termino" href="/glosario\#noindex" aria-describedby="(def-noindex-\d+)">noindex</a><span class="pista__globo" id="\1" role="tooltip" hidden><strong>noindex</strong>Pide que no se indexe.</span></span>#', $html);
        self::assertStringNotContainsString('title=', $html, 'Sin title: no se ve con teclado ni en el móvil');
        self::assertStringContainsString('Quitar el noindex de', $html, 'El término sigue en el texto');
    }

    public function testVariantesMayusculasYTerminosConPunto(): void
    {
        $html = self::glosario()->enlazar('Revisa ROBOTS.TXT y la Meta Robots; no indexar nada.');

        self::assertStringContainsString('href="/glosario#robots-txt"', $html);
        self::assertStringContainsString('>ROBOTS.TXT</a>', $html, 'Se conserva como estaba escrito');
        self::assertStringContainsString('href="/glosario#meta-robots"', $html);
        self::assertStringContainsString('href="/glosario#noindex"', $html, 'Por su variante');
    }

    public function testNoEnlazaDentroDeOtraPalabra(): void
    {
        $html = self::glosario()->enlazar('noindexado, sinnoindex y robots.txtx');

        self::assertStringNotContainsString('<a ', $html);
    }

    public function testElTextoOriginalNuncaSeConvierteEnHtml(): void
    {
        $html = self::glosario()->enlazar('<script>alert(1)</script> noindex "<img src=x onerror=alert(1)>"');

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('Etiqueta &lt;meta name=&quot;robots&quot;&gt;.</span>', self::glosario()->enlazar('meta robots'), 'La definición también va escapada');
    }

    public function testAnclasSinTildes(): void
    {
        self::assertSame('indexacion', Glosario::ancla('indexación'));
        self::assertSame('http-5xx', Glosario::ancla('HTTP 5xx'));
    }

    public function testElGlosarioDelCatalogoSeCargaYCadaTerminoEsUnico(): void
    {
        $g = Glosario::comun();

        self::assertGreaterThan(20, count($g->terminos));
        $anclas = array_column($g->terminos, 'ancla');
        self::assertSame(count($anclas), count(array_unique($anclas)), 'Cada término tiene su propia ancla');
        foreach ($g->terminos as $t) {
            self::assertNotSame('', trim($t['definicion']), $t['termino']);
        }
        self::assertStringContainsString('href="/glosario#noindex"', $g->enlazar('Tiene un noindex.'));
    }

    public function testCadaGloboTieneUnIdDistinto(): void
    {
        $html = self::glosario()->enlazar('noindex') . self::glosario()->enlazar('noindex');
        preg_match_all('/id="(def-[^"]+)"/', $html, $m);

        self::assertCount(2, $m[1]);
        self::assertNotSame($m[1][0], $m[1][1], 'aria-describedby apunta a un solo globo');
    }

    public function testSinTextoDevuelveVacio(): void
    {
        self::assertSame('', self::glosario()->enlazar(null));
        self::assertSame('Sin términos.', (new Glosario([]))->enlazar('Sin términos.'));
    }
}
