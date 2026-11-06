<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Results;

use PHPUnit\Framework\TestCase;
use ProconSEO\Engine\Catalogo\CatalogoJson;
use ProconSEO\Results\Etiquetas;
use ProconSEO\Results\Informe;
use ProconSEO\Shared\Vista;

/**
 * Resultados que salen de una norma y no de un criterio WCAG (p. ej. la declaración de
 * accesibilidad, Real Decreto 1112/2018): el informe dice a qué norma se refieren.
 */
final class NormativaTest extends TestCase
{
    private const DECLARACION = [
        'id_comprobacion' => 'ACC-03.declaracion-accesibilidad',
        'id_criterio' => 'ACC-03',
        'bloque' => 'acc',
        'estado' => 'decision_revisar',
        'normativa' => 'Real Decreto 1112/2018',
    ];

    protected function setUp(): void
    {
        Vista::configurar(dirname(__DIR__, 2) . '/templates');
        $_SESSION = [];
    }

    public function testEtiqueta(): void
    {
        self::assertSame('Normativa: Real Decreto 1112/2018', Etiquetas::normativa(Filas::resultado(self::DECLARACION)));
        self::assertNull(Etiquetas::normativa(Filas::resultado(['normativa' => '  '])));
        self::assertNull(Etiquetas::normativa(Filas::resultado()), 'Sin normativa no hay etiqueta');
        self::assertNull(
            Etiquetas::normativa(Filas::resultado(['wcag_criterio' => '1.1.1', 'wcag_nivel' => 'A', 'wcag_version' => '2.1'] + self::DECLARACION)),
            'Si tiene WCAG, manda WCAG',
        );
    }

    public function testDetalleMuestraLaNormativa(): void
    {
        $html = Vista::render('results/detalle', [
            'resultado' => Filas::resultado(self::DECLARACION),
            'ficha' => null, 'analisis' => null, 'evidencias' => [], 'revisiones' => [],
        ], false);

        self::assertStringContainsString('Normativa: Real Decreto 1112/2018', $html);
        self::assertStringContainsString('<dt>Normativa</dt><dd>Real Decreto 1112/2018</dd>', $html);
        self::assertStringNotContainsString('<dt>WCAG</dt>', $html);
    }

    public function testTarjetaYFormularioDeRevisionMuestranLaNormativa(): void
    {
        $r = Filas::resultado(self::DECLARACION + ['titulo' => 'Declaración de accesibilidad']);

        self::assertStringContainsString('Normativa: Real Decreto 1112/2018', Vista::render('results/_resultado', ['r' => $r], false));
        self::assertStringContainsString('Normativa: Real Decreto 1112/2018', Vista::render('results/revision', [
            'resultado' => $r, 'analisis' => null, 'evidencias' => [], 'revisiones' => [], 'valores' => [], 'errores' => [],
            'decisiones' => [], 'estados' => [], 'maxCaptura' => 1,
        ], false));
    }

    public function testLaNormativaSeEscapa(): void
    {
        $html = Vista::render('results/_resultado', ['r' => Filas::resultado(['normativa' => '<script>x</script>', 'titulo' => 'T'])], false);

        self::assertStringNotContainsString('<script>x', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testSiFaltaSeTomaDelCatalogo(): void
    {
        $catalogo = CatalogoJson::v1();
        $ficha = $catalogo->obtener('ACC-03.declaracion-accesibilidad');
        if ($ficha === null || !isset($ficha->extra['normativa'])) {
            self::markTestSkipped('El catálogo no tiene normativa para ACC-03.declaracion-accesibilidad.');
        }

        $r = Informe::conTitulos([Filas::resultado(['normativa' => null] + self::DECLARACION)], $catalogo)[0];

        self::assertSame($ficha->extra['normativa'], $r['normativa']);
    }
}
