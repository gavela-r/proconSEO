<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Fetch\Navegador;

use PHPUnit\Framework\TestCase;
use ProconSEO\Fetch\Navegador\LecturaProvisional;
use ProconSEO\Fetch\Navegador\PaginaRenderizada;
use ProconSEO\Shared\Enum\EstadoResultado;
use ProconSEO\Shared\Enum\Gravedad;

final class LecturaProvisionalTest extends TestCase
{
    private static function muestra(array $color, string $texto = 'Hola', array $capas = [[255, 255, 255, 1]], ?string $motivo = null): array
    {
        return ['texto' => $texto, 'selector' => 'p', 'color' => $color, 'opacidad' => 1, 'capas' => $capas, 'motivo' => $motivo, 'tamano' => 16, 'peso' => 400];
    }

    /** @param array<string, mixed> $datos */
    private static function leer(array $datos): array
    {
        $p = new PaginaRenderizada(...array_merge([
            'url' => 'https://ejemplo.es/',
            'htmlInicial' => '<html><body><p>' . str_repeat('palabra ', 300) . '</p></body></html>',
            'palabrasVisibles' => 300,
            'contraste' => ['muestras' => [self::muestra([0, 0, 0, 1])], 'tapados' => 0, 'fueraDeVista' => 0, 'inactivos' => 0, 'elementos' => 1],
            'foco' => ['enfocables' => 2, 'pasos' => [
                ['indice' => 0, 'selector' => 'a', 'etiqueta' => 'a', 'nombre' => 'Inicio', 'visible' => true, 'mismo' => false],
                ['indice' => 1, 'selector' => 'button', 'etiqueta' => 'button', 'nombre' => 'Enviar', 'visible' => true, 'mismo' => false],
            ], 'indicadores' => [true, true], 'vueltaCompleta' => true],
            'reflow' => ['ancho' => 320, 'total' => 320, 'culpables' => []],
        ], $datos));

        return array_column(LecturaProvisional::leer($p), null, 'id');
    }

    public function testPaginaQueCumpleTodo(): void
    {
        foreach (self::leer([]) as $id => $r) {
            self::assertSame(EstadoResultado::Correcto, $r['estado'], $id);
            self::assertNull($r['gravedad']);
        }
    }

    public function testPaginaQueNoCumple(): void
    {
        $r = self::leer([
            'contraste' => ['muestras' => [
                self::muestra([0xbb, 0xbb, 0xbb, 1], 'uno'), self::muestra([0xbb, 0xbb, 0xbb, 1], 'dos'),
                self::muestra([0, 0, 0, 1], 'Sobre foto', [], 'Texto sobre una imagen, un vídeo o un gráfico'),
            ], 'tapados' => 0, 'fueraDeVista' => 0, 'inactivos' => 0, 'elementos' => 3],
            'foco' => ['enfocables' => 1, 'pasos' => [
                ['indice' => 0, 'selector' => 'div#widget', 'etiqueta' => 'div', 'nombre' => '', 'visible' => true, 'mismo' => false],
                ['indice' => 1, 'selector' => 'div#widget', 'etiqueta' => 'div', 'nombre' => '', 'visible' => true, 'mismo' => true],
                ['indice' => 2, 'selector' => 'div#widget', 'etiqueta' => 'div', 'nombre' => '', 'visible' => true, 'mismo' => true],
                ['indice' => 3, 'selector' => 'div#widget', 'etiqueta' => 'div', 'nombre' => '', 'visible' => true, 'mismo' => true],
            ], 'indicadores' => [false, false, false, false], 'vueltaCompleta' => false],
            'reflow' => ['ancho' => 320, 'total' => 900, 'culpables' => [['selector' => 'div.ancho', 'etiqueta' => 'div', 'derecha' => 900]]],
            'htmlInicial' => '<html><body><div id="app"></div><script>pintar()</script></body></html>',
            'palabrasVisibles' => 800,
        ]);

        self::assertSame(EstadoResultado::Problema, $r['ACC-05.contraste-texto']['estado']);
        self::assertSame(Gravedad::Alto, $r['ACC-05.contraste-texto']['gravedad'], '#bbbbbb no llega ni a 3:1');
        self::assertCount(1, $r['ACC-05.contraste-texto']['evidencias'], 'Los dos textos con los mismos colores son una sola causa');
        self::assertStringContainsString('2 textos', $r['ACC-05.contraste-texto']['evidencias'][0]);
        self::assertSame(EstadoResultado::NoVerificable, $r['ACC-05.contraste-sobre-imagen']['estado'], 'Sobre una imagen no se supone el fondo');

        self::assertSame(EstadoResultado::Problema, $r['ACC-06.foco-visible']['estado']);
        self::assertSame(EstadoResultado::Problema, $r['ACC-06.trampa-teclado']['estado']);
        self::assertSame(EstadoResultado::Problema, $r['ACC-04.reflow-320']['estado']);
        self::assertSame(EstadoResultado::Problema, $r['SEO-09.contenido-tras-js']['estado'], 'Página vacía sin JavaScript');
    }

    public function testTablaAnchaEsDecisionDeRevisar(): void
    {
        $r = self::leer(['reflow' => ['ancho' => 320, 'total' => 700, 'culpables' => [['selector' => 'table.precios', 'etiqueta' => 'table', 'derecha' => 700]]]]);

        self::assertSame(EstadoResultado::DecisionRevisar, $r['ACC-04.reflow-320']['estado']);
    }

    public function testPaginaBloqueadaTodoNoVerificable(): void
    {
        $p = new PaginaRenderizada('https://ejemplo.es/', error: 'El navegador no pudo abrir la página: bloqueado por seguridad');
        $r = LecturaProvisional::leer($p);

        self::assertCount(count(LecturaProvisional::titulos()), $r);
        foreach ($r as $x) {
            self::assertSame(EstadoResultado::NoVerificable, $x['estado'], $x['id']);
            self::assertStringContainsString('bloqueado por seguridad', $x['detalle']);
        }
    }

    public function testPalabrasDelHtmlSinScriptsNiEstilos(): void
    {
        self::assertSame(3, LecturaProvisional::palabrasHtml('<html><head><title>No cuenta</title><style>p{}</style></head><body><p>Uno dos</p><script>var x = "nada";</script><p>tres</p></body></html>'));
        self::assertSame(0, LecturaProvisional::palabrasHtml(''));
    }
}
