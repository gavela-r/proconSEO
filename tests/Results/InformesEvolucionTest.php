<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Results;

use PHPUnit\Framework\TestCase;
use ProconSEO\Export\ExportadorCsvHallazgos;
use ProconSEO\Results\Evolucion;
use ProconSEO\Shared\Vista;

/**
 * CSV por hallazgo y evolución del proyecto: recuentos, nunca una nota ni un porcentaje.
 */
final class InformesEvolucionTest extends TestCase
{
    protected function setUp(): void
    {
        Vista::configurar(dirname(__DIR__, 2) . '/templates');
    }

    /** @return list<list<string>> */
    private static function leer(string $csv): array
    {
        self::assertStringStartsWith("\xEF\xBB\xBF", $csv, 'Con BOM para que Excel lea bien las tildes');
        $f = fopen('php://temp', 'r+');
        fwrite($f, substr($csv, 3));
        rewind($f);
        $filas = [];
        while (($fila = fgetcsv($f, null, ';', '"', '')) !== false) {
            $filas[] = $fila;
        }

        return $filas;
    }

    public function testUnaFilaPorHallazgoConSusPaginasYLoMasUrgentePrimero(): void
    {
        $r = static fn (string $id, string $url, array $mas = []): array => Filas::resultado($mas + ['id_comprobacion' => $id, 'titulo' => $id, 'url_recurso' => $url]);
        $csv = ExportadorCsvHallazgos::generar([
            $r('SEO-12.alt', 'https://ejemplo.es/a', ['estado' => 'mejorable']),
            $r('ACC-01.alt', 'https://ejemplo.es/a', ['estado' => 'problema', 'gravedad' => 'alto', 'bloque' => 'acc']),
            $r('ACC-01.alt', 'https://ejemplo.es/b', ['estado' => 'problema', 'gravedad' => 'alto', 'bloque' => 'acc', 'revision_pendiente' => true]),
            $r('SEO-03.title', 'https://ejemplo.es/a'),
            $r('SEO-01.x', 'https://ejemplo.es/a', ['estado' => 'problema', 'gravedad' => 'critico', 'explicacion_sencilla' => '=HYPERLINK("http://malo")']),
        ], true);
        $filas = self::leer($csv);

        self::assertSame(ExportadorCsvHallazgos::COLUMNAS, $filas[0]);
        $cabecera = array_flip($filas[0]);
        self::assertCount(5, $filas, 'Cabecera y cuatro hallazgos: el de las dos páginas es una fila');
        self::assertSame(['SEO-01.x', 'ACC-01.alt', 'SEO-12.alt', 'SEO-03.title'], array_column(array_slice($filas, 1), $cabecera['Comprobación']), 'Crítico, alto, mejorable, correcto');
        self::assertSame('2', $filas[2][$cabecera['Páginas']]);
        self::assertSame("https://ejemplo.es/a\nhttps://ejemplo.es/b", $filas[2][$cabecera['Lista de páginas']]);
        self::assertSame('1', $filas[2][$cabecera['Revisiones pendientes']]);
        self::assertSame('Alto', $filas[2][$cabecera['Gravedad']]);
        self::assertSame('SÍ', $filas[1][$cabecera['Datos de prueba']]);
        self::assertSame("'=HYPERLINK(\"http://malo\")", $filas[1][$cabecera['Explicación']], 'Una fórmula de la web no se ejecuta en Excel');
        self::assertSame('', $filas[4][$cabecera['Responsable']], 'Lo correcto no tiene responsable');
    }

    public function testEvolucionSoloComparaLaMismaDireccionYAlcance(): void
    {
        $a = static fn (int $id, string $alcance, string $fecha, array $recuento, string $estado = 'completado'): array => [
            'id' => $id, 'url_introducida' => 'https://ejemplo.es/', 'url_final' => 'https://ejemplo.es/', 'alcance' => $alcance,
            'estado' => $estado, 'creado_en' => $fecha, 'terminado_en' => $fecha, 'recuento' => $recuento,
        ];
        // Como lo devuelve el repositorio: el más reciente primero.
        $evolucion = new Evolucion([
            $a(5, 'sitio_completo', '2026-11-05 10:00:00', [], 'en_curso'),
            $a(4, 'sitio_completo', '2026-11-04 10:00:00', ['problema' => 3, 'mejorable' => 1], 'parcial'),
            $a(3, 'url_individual', '2026-11-03 10:00:00', ['problema' => 9]),
            $a(2, 'sitio_completo', '2026-11-02 10:00:00', ['problema' => 7, 'decision_revisar' => 2, 'correcto' => 40]),
            $a(1, 'sitio_completo', '2026-11-01 10:00:00', []),
        ]);

        self::assertCount(1, $evolucion->series, 'La URL individual solo tiene un análisis: no hay evolución que enseñar');
        $serie = $evolucion->series[0];
        self::assertSame([2, 4], array_column($serie['puntos'], 'id'), 'Del más antiguo al más reciente, sin los que están en marcha ni los vacíos');
        self::assertSame(['problema' => 7, 'decision_revisar' => 2, 'mejorable' => 0], $serie['puntos'][0]['valores'], 'Los correctos no se suman');
        self::assertSame(9, $serie['maximo']);

        $html = Vista::render('results/_evolucion', ['evolucion' => $evolucion], false);
        self::assertStringContainsString('role="img"', $html);
        self::assertStringContainsString('Ver los datos', $html, 'Con la tabla para quien no ve el gráfico');
        self::assertStringContainsString('incompleto', $html, 'El análisis parcial se marca');
        self::assertStringNotContainsString('style=', $html, 'Sin estilos en línea (CSP)');
        self::assertStringNotContainsString('%', $html);
        self::assertSame('', Vista::render('results/_evolucion', ['evolucion' => new Evolucion([])], false));
    }
}
