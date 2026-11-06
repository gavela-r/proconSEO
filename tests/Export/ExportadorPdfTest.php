<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Export;

use PHPUnit\Framework\TestCase;
use ProconSEO\Export\ExportadorPdf;
use ProconSEO\Results\Informe;
use ProconSEO\Shared\Vista;
use ProconSEO\Tests\Results\Filas;

/**
 * Exportación a PDF (RF-50): sin recursos remotos, con todo escapado, en el orden de la
 * especificación 4.4 y con la marca de datos de prueba (RF-52).
 */
final class ExportadorPdfTest extends TestCase
{
    private const MALICIOSO = '<img src="https://rastreo.example/pixel.png"><script>alert(1)</script>';

    protected function setUp(): void
    {
        Vista::configurar(dirname(__DIR__, 2) . '/templates');
    }

    public function testOpcionesSeguras(): void
    {
        $opciones = ExportadorPdf::opciones();

        self::assertFalse($opciones->getIsRemoteEnabled(), 'No carga recursos remotos');
        self::assertSame(['file://'], array_keys($opciones->getAllowedProtocols()), 'Ni http ni https permitidos');
        self::assertFalse($opciones->getIsPhpEnabled(), 'Sin PHP embebido');
        self::assertFalse($opciones->getIsJavascriptEnabled(), 'Sin JavaScript');
        self::assertCount(1, $opciones->getChroot(), 'Solo puede leer la carpeta de Dompdf (sus fuentes)');
        self::assertStringContainsString('dompdf', strtolower((string) $opciones->getChroot()[0]));
    }

    public function testGeneraUnPdf(): void
    {
        $pdf = ExportadorPdf::generar($this->html());

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(2000, strlen($pdf));
    }

    public function testUnaImagenRemotaNoSeDescarga(): void
    {
        // Aunque llegara HTML con una imagen remota sin escapar, Dompdf no se conecta: genera el PDF sin ella.
        $inicio = microtime(true);
        $pdf = ExportadorPdf::generar('<html><body><p>Hola</p><img src="http://10.255.255.1/x.png"></body></html>');

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertLessThan(5, microtime(true) - $inicio, 'No ha esperado a una conexión que no responde');
    }

    public function testPlantillaEscapadaYEnOrden(): void
    {
        $html = $this->html();

        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringNotContainsString('<img src="https://rastreo', $html, 'El contenido de la web va como texto');
        self::assertStringContainsString('&lt;img src=&quot;https://rastreo', $html);
        self::assertStringContainsString('DATOS DE PRUEBA', $html);
        self::assertStringContainsString('No es una nota', $html);
        $texto = strip_tags((string) preg_replace('#<style.*?</style>#s', '', $html));
        self::assertDoesNotMatchRegularExpression('/\d+\s*%|puntuaci/iu', $texto, 'Sin porcentajes en el texto del informe');

        $orden = array_map(static fn (string $t): int|false => strpos($html, $t), ['Resumen</h2>', '1. Bloqueos', '2. Qué hay que corregir', '3. Lo que está bien', '4. Lo que no se ha podido comprobar', '5. Límites']);
        self::assertNotContains(false, $orden);
        $ordenado = $orden;
        sort($ordenado);
        self::assertSame($ordenado, $orden, 'Orden de la especificación 4.4');

        self::assertStringContainsString('Normativa: Real Decreto 1112/2018', $html);
        self::assertStringContainsString('Revisión de Marta', $html);
    }

    public function testSinResultadosNoConcluyeNada(): void
    {
        $html = Vista::render('results/pdf', [
            'analisis' => Filas::analisis(),
            'informe' => new Informe([], [], []),
            'exportacion' => $this->exportacion([], false),
            'generado' => '6 nov 2026, 12:00',
        ], false);

        self::assertStringContainsString('no se puede decir nada de la web', $html);
        self::assertStringNotContainsString('DATOS DE PRUEBA', $html);
    }

    private function html(): string
    {
        $resultados = [
            Filas::resultado(['id' => 1, 'estado' => 'problema', 'gravedad' => 'critico', 'explicacion_sencilla' => self::MALICIOSO, 'evidencias' => 'E-001', 'titulo' => 'Título']),
            Filas::resultado(['id' => 2, 'bloque' => 'acc', 'estado' => 'decision_revisar', 'normativa' => 'Real Decreto 1112/2018', 'titulo' => 'Declaración']),
            Filas::resultado(['id' => 3, 'estado' => 'correcto', 'titulo' => 'Correcto']),
            Filas::resultado(['id' => 4, 'estado' => 'no_verificable', 'cobertura' => 'no_implementada', 'limitacion' => 'Necesita navegador', 'titulo' => 'Pendiente']),
        ];

        return Vista::render('results/pdf', [
            'analisis' => Filas::analisis(['proyecto_nombre' => self::MALICIOSO, 'objetivo' => self::MALICIOSO]),
            'informe' => new Informe($resultados, [], [['fase' => 'renderizado', 'estado' => 'omitida', 'mensaje' => null]]),
            'exportacion' => $this->exportacion([
                ['codigo' => 'E-001', 'tipo' => 'fragmento_html', 'contenido' => self::MALICIOSO, 'captura' => null, 'url' => null, 'truncada' => false, 'revision_id' => null, 'capturada_en_utc' => null],
            ], true),
            'generado' => '6 nov 2026, 12:00',
        ], false);
    }

    private function exportacion(array $evidencias, bool $prueba): array
    {
        return [
            'datos_de_prueba' => $prueba,
            'evidencias' => $evidencias,
            'revisiones' => [['id' => 9, 'resultado_id' => 1, 'comprobacion' => 'x', 'revisor' => 'Marta', 'motivo' => 'M', 'decision' => 'confirmar', 'nuevo_estado' => null, 'justificacion' => self::MALICIOSO, 'evidencias' => [], 'creada_en_utc' => '2026-11-04 10:00:00']],
            'eventos_seguridad' => [['regla' => 'SEG-03', 'fase' => null, 'url' => null, 'condicion' => self::MALICIOSO, 'decision' => 'continuar']],
        ];
    }
}
