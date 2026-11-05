<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Review;

use PHPUnit\Framework\TestCase;
use ProconSEO\Review\CapturaSegura;
use ProconSEO\Shared\Enum\DecisionRevision;
use ProconSEO\Shared\Enum\EstadoResultado;
use ProconSEO\Shared\Vista;
use ProconSEO\Tests\Results\Filas;

/**
 * Formulario de revisión: escapado (SEG-09), CSRF, subida multipart y estado actual fuera de la lista.
 */
final class PlantillaRevisionTest extends TestCase
{
    private const MALICIOSO = '<script>alert("x")</script>';

    protected function setUp(): void
    {
        Vista::configurar(dirname(__DIR__, 2) . '/templates');
        $_SESSION = [];
    }

    public function testFormulario(): void
    {
        $html = Vista::render('results/revision', [
            'resultado' => Filas::resultado(['estado' => 'no_verificable', 'titulo' => self::MALICIOSO, 'motivo_revision' => self::MALICIOSO, 'explicacion_sencilla' => self::MALICIOSO]),
            'analisis' => Filas::analisis(['proyecto_nombre' => self::MALICIOSO, 'es_dato_prueba' => 1]),
            'evidencias' => [['codigo' => 'E-001', 'contenido' => self::MALICIOSO, 'url_recurso' => null]],
            'revisiones' => [['decision' => 'pedir_evidencia', 'nuevo_estado' => null, 'revisor' => self::MALICIOSO, 'creado_en' => '2026-11-04 10:00:00']],
            'valores' => ['revisor' => self::MALICIOSO, 'decision' => 'cambiar_estado', 'nuevo_estado' => 'correcto'],
            'errores' => ['evidencia_texto' => 'Para pasar a «Correcto» hay que añadir evidencia'],
            'decisiones' => DecisionRevision::opciones(),
            'estados' => EstadoResultado::opciones(),
            'maxCaptura' => CapturaSegura::MAX_BYTES,
        ], false);

        self::assertStringNotContainsString('<script>alert', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('name="_csrf"', $html);
        self::assertStringContainsString('enctype="multipart/form-data"', $html);
        self::assertStringContainsString('accept="image/png,image/jpeg"', $html);
        self::assertStringContainsString('DATOS DE PRUEBA', $html);
        self::assertStringContainsString('aria-invalid="true"', $html);
        self::assertMatchesRegularExpression('/value="cambiar_estado" checked/', $html);
        self::assertMatchesRegularExpression('/value="correcto" selected/', $html);
        self::assertDoesNotMatchRegularExpression('/<option value="no_verificable"/', $html, 'El estado actual no se ofrece como nuevo');
    }
}
