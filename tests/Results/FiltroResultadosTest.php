<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Results;

use PHPUnit\Framework\TestCase;
use ProconSEO\Results\FiltroResultados;
use ProconSEO\Results\Informe;
use ProconSEO\Shared\Enum\Bloque;
use ProconSEO\Shared\Enum\EstadoResultado;

/**
 * Filtros y búsqueda del dashboard (RF-23).
 */
final class FiltroResultadosTest extends TestCase
{
    /** @var array<string, array<string, mixed>> */
    private array $r;

    protected function setUp(): void
    {
        $this->r = [
            'seo' => Filas::resultado(['bloque' => 'seo', 'id_criterio' => 'SEO-03', 'estado' => 'problema', 'titulo' => 'Título de la página', 'url_recurso' => 'https://ejemplo.es/']),
            'acc' => Filas::resultado(['bloque' => 'acc', 'id_criterio' => 'ACC-01', 'estado' => 'no_verificable', 'revision_pendiente' => true, 'titulo' => 'Texto alternativo', 'elemento' => 'img[src="logo.png"]', 'url_recurso' => 'https://ejemplo.es/contacto']),
            'ia' => Filas::resultado(['bloque' => 'ia', 'id_criterio' => 'IA-01', 'estado' => 'decision_revisar', 'estado_vigente' => 'correcto', 'titulo' => 'Permiso en robots.txt', 'url_recurso' => 'https://ejemplo.es/robots.txt']),
        ];
    }

    public function testSinFiltrosDevuelveTodo(): void
    {
        $filtro = FiltroResultados::ninguno();

        self::assertFalse($filtro->activo());
        self::assertCount(3, $filtro->aplicar(array_values($this->r)));
        self::assertSame([], $filtro->consulta());
    }

    public function testPorBloqueEstadoCriterioYPagina(): void
    {
        self::assertSame(['seo'], $this->claves(['bloque' => 'seo']));
        self::assertSame(['ia'], $this->claves(['estado' => 'correcto']), 'Filtra por el estado vigente, no por el original');
        self::assertSame([], $this->claves(['estado' => 'decision_revisar']));
        self::assertSame(['acc'], $this->claves(['criterio' => 'acc-01']), 'El criterio no distingue mayúsculas');
        self::assertSame(['ia'], $this->claves(['url' => 'https://ejemplo.es/robots.txt']));
        self::assertSame(['seo'], $this->claves(['url' => 'https://ejemplo.es/']), 'Una dirección exacta del análisis filtra solo esa, no todas las que empiezan igual');
        self::assertSame(['acc'], $this->claves(['url' => '/CONTACTO']), 'Parte de la dirección, sin distinguir mayúsculas');
        self::assertSame(['seo', 'acc', 'ia'], $this->claves(['url' => 'ejemplo.es']));
        self::assertSame(['acc'], $this->claves(['pendiente' => '1']));
        self::assertSame([], $this->claves(['bloque' => 'seo', 'pendiente' => '1']), 'Los filtros se combinan');
    }

    public function testBusquedaSinDistinguirTildesNiMayusculas(): void
    {
        self::assertSame(['seo'], $this->claves(['q' => 'TITULO']));
        self::assertSame(['acc'], $this->claves(['q' => 'logo.png']), 'Busca también en el elemento');
        self::assertSame(['ia'], $this->claves(['q' => 'IA-01']), 'Y en el código de la comprobación');
        self::assertSame([], $this->claves(['q' => 'no existe']));
    }

    public function testValoresNoValidosSeIgnoran(): void
    {
        $filtro = FiltroResultados::desdeConsulta([
            'bloque' => 'otro', 'estado' => 'aprobado', 'criterio' => "SEO-03' OR 1=1", 'pendiente' => 'si', 'q' => ['array'], 'url' => str_repeat('a', 3000),
        ]);

        self::assertFalse($filtro->activo());
    }

    public function testTextoLimitadoYConsultaParaEnlaces(): void
    {
        $filtro = FiltroResultados::desdeConsulta(['bloque' => 'acc', 'estado' => 'problema', 'q' => '  ' . str_repeat('x', 300)]);

        self::assertSame(Bloque::Acc, $filtro->bloque);
        self::assertSame(EstadoResultado::Problema, $filtro->estado);
        self::assertSame(FiltroResultados::MAX_TEXTO, mb_strlen($filtro->texto));
        self::assertSame(['bloque' => 'acc', 'estado' => 'problema', 'q' => $filtro->texto], $filtro->consulta());
    }

    public function testElResumenDelInformeNoCambiaConLosFiltros(): void
    {
        $informe = new Informe(array_values($this->r), [], [], null, FiltroResultados::desdeConsulta(['bloque' => 'seo']));

        self::assertSame(3, $informe->total);
        self::assertSame(1, $informe->mostrados);
        self::assertSame(1, $informe->resumen['acc']['total'], 'El resumen sigue contando todo');
        self::assertSame([], $informe->correctos, 'Las secciones sí se filtran');
        self::assertSame(['ACC-01', 'IA-01', 'SEO-03'], $informe->criterios);
        self::assertCount(3, $informe->urls);
    }

    /** @return list<string> */
    private function claves(array $consulta): array
    {
        $ids = array_column(FiltroResultados::desdeConsulta($consulta)->aplicar(array_values($this->r)), 'id');

        return array_values(array_keys(array_filter($this->r, static fn (array $r): bool => in_array($r['id'], $ids, true))));
    }
}
