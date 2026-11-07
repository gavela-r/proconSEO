<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Results;

use PHPUnit\Framework\TestCase;
use ProconSEO\Results\FiltroResultados;
use ProconSEO\Results\Responsable;

final class ResponsableTest extends TestCase
{
    public function testPorCriterioYDecisionDelCliente(): void
    {
        self::assertSame(Responsable::CONTENIDO, Responsable::de(Filas::resultado(['id_criterio' => 'ACC-01', 'estado' => 'problema'])));
        self::assertSame(Responsable::DISENO, Responsable::de(Filas::resultado(['id_criterio' => 'ACC-05', 'estado' => 'problema'])));
        self::assertSame(Responsable::DESARROLLO, Responsable::de(Filas::resultado(['id_criterio' => 'SEO-01', 'estado' => 'problema'])));
        self::assertSame(Responsable::CLIENTE, Responsable::de(Filas::resultado(['id_criterio' => 'IA-01', 'estado' => 'decision_revisar'])), 'Una decisión a revisar es del cliente');
        self::assertSame(Responsable::DESARROLLO, Responsable::de(Filas::resultado(['id_criterio' => 'XX-99'])), 'Criterio desconocido: desarrollo');
    }

    public function testFiltroPorResponsableYNivelWcag(): void
    {
        $alt = Filas::resultado(['id_criterio' => 'ACC-01', 'estado' => 'problema', 'wcag_criterio' => '1.1.1', 'wcag_nivel' => 'A']);
        $contraste = Filas::resultado(['id_criterio' => 'ACC-05', 'estado' => 'problema', 'wcag_criterio' => '1.4.3', 'wcag_nivel' => 'AA']);
        $objetivo = Filas::resultado(['id_criterio' => 'ACC-08', 'estado' => 'problema', 'wcag_criterio' => '2.5.8', 'wcag_nivel' => 'AA']);
        $seo = Filas::resultado(['id_criterio' => 'SEO-01', 'estado' => 'problema']);
        $todos = [$alt, $contraste, $objetivo, $seo];

        $ids = static fn (array $consulta): array => array_column(FiltroResultados::desdeConsulta($consulta)->aplicar($todos), 'id');

        self::assertSame([$alt['id']], $ids(['responsable' => 'contenido']));
        self::assertSame([$contraste['id'], $objetivo['id']], $ids(['responsable' => 'diseno']));
        self::assertSame([$alt['id']], $ids(['nivel' => 'A']));
        self::assertSame([$contraste['id'], $objetivo['id']], $ids(['nivel' => 'AA']), 'Sin criterio WCAG no entra en ningún nivel');
        self::assertSame([$objetivo['id']], $ids(['nivel' => 'nuevo22']));
        self::assertCount(4, $ids(['responsable' => 'otro', 'nivel' => 'AAA']), 'Valores no válidos: se ignoran');
        self::assertSame(['responsable' => 'diseno', 'nivel' => 'AA'], FiltroResultados::desdeConsulta(['responsable' => 'diseno', 'nivel' => 'AA'])->consulta());
    }
}
