<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Engine\Catalogo;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Comprueba la integridad del catálogo v1 (catalog/v1/) frente a la especificación v1.2.
 */
final class CatalogoV1Test extends TestCase
{
    private const DIR = __DIR__ . '/../../../catalog/v1';

    /** Criterios de la especificación, sección 6. */
    private const CRITERIOS_ESPERADOS = [
        'ia' => 11,
        'seo' => 16,
        'acc' => 12,
    ];

    /** Los 55 criterios WCAG 2.2 A y AA (4.1.1 eliminado). */
    private const WCAG_A_AA = [
        '1.1.1' => 'A', '1.2.1' => 'A', '1.2.2' => 'A', '1.2.3' => 'A', '1.2.4' => 'AA', '1.2.5' => 'AA',
        '1.3.1' => 'A', '1.3.2' => 'A', '1.3.3' => 'A', '1.3.4' => 'AA', '1.3.5' => 'AA',
        '1.4.1' => 'A', '1.4.2' => 'A', '1.4.3' => 'AA', '1.4.4' => 'AA', '1.4.5' => 'AA',
        '1.4.10' => 'AA', '1.4.11' => 'AA', '1.4.12' => 'AA', '1.4.13' => 'AA',
        '2.1.1' => 'A', '2.1.2' => 'A', '2.1.4' => 'A', '2.2.1' => 'A', '2.2.2' => 'A', '2.3.1' => 'A',
        '2.4.1' => 'A', '2.4.2' => 'A', '2.4.3' => 'A', '2.4.4' => 'A', '2.4.5' => 'AA', '2.4.6' => 'AA',
        '2.4.7' => 'AA', '2.4.11' => 'AA',
        '2.5.1' => 'A', '2.5.2' => 'A', '2.5.3' => 'A', '2.5.4' => 'A', '2.5.7' => 'AA', '2.5.8' => 'AA',
        '3.1.1' => 'A', '3.1.2' => 'AA', '3.2.1' => 'A', '3.2.2' => 'A', '3.2.3' => 'AA', '3.2.4' => 'AA',
        '3.2.6' => 'A', '3.3.1' => 'A', '3.3.2' => 'A', '3.3.3' => 'AA', '3.3.4' => 'AA', '3.3.7' => 'A',
        '3.3.8' => 'AA', '4.1.2' => 'A', '4.1.3' => 'AA',
    ];

    /** Nuevos en WCAG 2.2 (especificación 6.3). */
    private const NUEVOS_22 = ['2.4.11', '2.5.7', '2.5.8', '3.2.6', '3.3.7', '3.3.8'];

    /** Subcomprobaciones con ID fijado en la especificación (6.2, 6.5 a 6.8). */
    private const SUBCOMPROBACIONES_ESPECIFICACION = [
        'SEO-03.title-presente',
        'SEO-01.noindex-desarrollo', 'SEO-01.cms-detectado', 'SEO-07.paginas-minimas', 'SEO-10.viewport',
        'SEO-12.video-detectado', 'SEO-12.video-miniatura', 'SEO-12.video-datos', 'SEO-12.video-pagina-dedicada',
        'SEO-14.producto-marcado', 'SEO-14.producto-precio-coherente', 'SEO-14.producto-disponibilidad',
        'IA-06.producto-vigencia', 'IA-06.precios-carburante',
        'IA-08.datos-negocio-coherentes', 'IA-07.negocio-local',
        'ACC-09.formulario-billetes', 'IA-10.horarios-texto', 'ACC-12.chatbot-detectado',
    ];

    private const CAMPOS_CRITERIO = [
        'id', 'bloque', 'nombre', 'que_comprueba', 'cuando_aplica', 'explicacion_sencilla', 'modo',
        'esfuerzo', 'mvp', 'ref_investigacion', 'reutiliza', 'fuentes', 'wcag', 'subcomprobaciones',
    ];

    private const CAMPOS_SUBCOMPROBACION = [
        'id', 'nombre', 'que_comprueba', 'cuando_aplica', 'condicion', 'modo', 'mvp', 'motivo_no_mvp',
        'necesita', 'alcance_minimo', 'wcag', 'kit_digital', 'reutiliza',
    ];

    /** @var array<string, mixed>|null */
    private static ?array $meta = null;

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $criterios = null;

    /** @return array<string, mixed> */
    private static function meta(): array
    {
        return self::$meta ??= self::leerJson(self::DIR . '/catalogo.json');
    }

    /** @return array<string, array<string, mixed>> */
    private static function criterios(): array
    {
        if (self::$criterios === null) {
            self::$criterios = [];
            foreach (glob(self::DIR . '/criterios/*.json') ?: [] as $fichero) {
                self::$criterios[basename($fichero, '.json')] = self::leerJson($fichero);
            }
            ksort(self::$criterios);
        }
        return self::$criterios;
    }

    /** @return array<string, mixed> */
    private static function leerJson(string $fichero): array
    {
        $datos = json_decode((string) file_get_contents($fichero), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($datos, $fichero);
        return $datos;
    }

    /** @return list<array<string, mixed>> */
    private static function subcomprobaciones(): array
    {
        $todas = [];
        foreach (self::criterios() as $criterio) {
            foreach ($criterio['subcomprobaciones'] as $sub) {
                $todas[] = $sub + ['_criterio' => $criterio];
            }
        }
        return $todas;
    }

    /** @return list<string> */
    private static function valores(string $grupo): array
    {
        return array_keys(self::meta()['valores'][$grupo]);
    }

    public function testEstanTodosLosCriteriosYNingunoDeMas(): void
    {
        $esperados = [];
        foreach (self::CRITERIOS_ESPERADOS as $bloque => $total) {
            for ($i = 1; $i <= $total; $i++) {
                $esperados[] = sprintf('%s-%02d', strtoupper($bloque), $i);
            }
        }
        sort($esperados);

        self::assertSame($esperados, array_keys(self::criterios()));
    }

    public function testElNombreDelFicheroCoincideConElIdYConSuBloque(): void
    {
        foreach (self::criterios() as $fichero => $criterio) {
            self::assertSame($fichero, $criterio['id']);
            self::assertStringStartsWith(strtoupper($criterio['bloque']) . '-', $criterio['id']);
        }
    }

    public function testCadaCriterioTieneTodosSusCamposYNingunoVacio(): void
    {
        foreach (self::criterios() as $id => $criterio) {
            foreach (self::CAMPOS_CRITERIO as $campo) {
                self::assertArrayHasKey($campo, $criterio, "$id: falta $campo");
            }
            foreach (['nombre', 'que_comprueba', 'cuando_aplica', 'explicacion_sencilla'] as $texto) {
                self::assertNotSame('', trim($criterio[$texto]), "$id: $texto vacío");
            }
            self::assertNotEmpty($criterio['subcomprobaciones'], "$id: sin subcomprobaciones");
            self::assertNotEmpty($criterio['fuentes'], "$id: sin fuente oficial");
            foreach ($criterio['fuentes'] as $fuente) {
                self::assertMatchesRegularExpression('#^https://#', $fuente['url'], "$id: fuente sin https");
            }
        }
    }

    public function testLosIdsDeSubcomprobacionSonUnicosYEstables(): void
    {
        $vistos = [];
        foreach (self::subcomprobaciones() as $sub) {
            $id = $sub['id'];
            self::assertArrayNotHasKey($id, $vistos, "ID repetido: $id");
            $vistos[$id] = true;
            self::assertMatchesRegularExpression('/^(IA|SEO|ACC)-\d{2}\.[a-z0-9]+(-[a-z0-9]+)*$/', $id);
            self::assertStringStartsWith($sub['_criterio']['id'] . '.', $id, "$id está en otro criterio");
        }
    }

    public function testCadaSubcomprobacionTieneTodosSusCampos(): void
    {
        foreach (self::subcomprobaciones() as $sub) {
            foreach (self::CAMPOS_SUBCOMPROBACION as $campo) {
                self::assertArrayHasKey($campo, $sub, "{$sub['id']}: falta $campo");
            }
            self::assertNotSame('', trim($sub['que_comprueba']), "{$sub['id']}: sin descripción");
            self::assertNotEmpty($sub['necesita'], "{$sub['id']}: necesita vacío");
        }
    }

    public function testEstanLasSubcomprobacionesQueNombraLaEspecificacion(): void
    {
        $ids = array_column(self::subcomprobaciones(), 'id');
        foreach (self::SUBCOMPROBACIONES_ESPECIFICACION as $id) {
            self::assertContains($id, $ids, "Falta $id");
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function camposConValoresCerrados(): iterable
    {
        yield 'bloque' => ['criterio', 'bloque'];
        yield 'modo del criterio' => ['criterio', 'modo'];
        yield 'esfuerzo' => ['criterio', 'esfuerzo'];
        yield 'mvp del criterio' => ['criterio', 'mvp'];
        yield 'modo de la subcomprobación' => ['sub', 'modo'];
        yield 'mvp de la subcomprobación' => ['sub', 'mvp'];
        yield 'alcance mínimo' => ['sub', 'alcance_minimo'];
    }

    #[DataProvider('camposConValoresCerrados')]
    public function testLosValoresSonLosPermitidos(string $nivel, string $campo): void
    {
        $grupo = $campo === 'alcance_minimo' ? 'alcance_minimo' : $campo;
        $permitidos = self::valores($grupo);
        $elementos = $nivel === 'criterio' ? self::criterios() : self::subcomprobaciones();
        foreach ($elementos as $elemento) {
            self::assertContains($elemento[$campo], $permitidos, "{$elemento['id']}: $campo no permitido");
        }
    }

    public function testLasListasYCondicionesUsanValoresPermitidos(): void
    {
        $ids = array_merge(array_keys(self::criterios()), array_column(self::subcomprobaciones(), 'id'));
        foreach (self::subcomprobaciones() as $sub) {
            $id = $sub['id'];
            foreach ($sub['necesita'] as $v) {
                self::assertContains($v, self::valores('necesita'), "$id: necesita $v");
            }
            foreach ($sub['kit_digital'] as $v) {
                self::assertContains($v, self::valores('kit_digital'), "$id: kit_digital $v");
            }
            if ($sub['condicion'] !== null) {
                self::assertContains($sub['condicion'], self::valores('condicion'), "$id: condición");
            }
            if (isset($sub['gravedad_si_problema'])) {
                self::assertContains($sub['gravedad_si_problema'], self::valores('gravedad_si_problema'));
            }
            foreach ($sub['reutiliza'] as $ref) {
                self::assertContains($ref, $ids, "$id reutiliza $ref, que no existe");
            }
        }
        foreach (self::criterios() as $id => $criterio) {
            foreach ($criterio['reutiliza'] as $ref) {
                self::assertContains($ref, $ids, "$id reutiliza $ref, que no existe");
            }
        }
    }

    public function testLoQueNoEntraEnElMvpDiceElMotivo(): void
    {
        foreach (self::subcomprobaciones() as $sub) {
            if ($sub['mvp'] === 'no') {
                self::assertContains($sub['motivo_no_mvp'], self::valores('motivo_no_mvp'), $sub['id']);
            } else {
                self::assertNull($sub['motivo_no_mvp'], $sub['id']);
            }
        }
    }

    public function testSoloSeFijaGravedadCriticaEnElNoindexDeDesarrollo(): void
    {
        $conGravedad = [];
        foreach (self::subcomprobaciones() as $sub) {
            if (isset($sub['gravedad_si_problema'])) {
                $conGravedad[$sub['id']] = $sub['gravedad_si_problema'];
            }
        }
        self::assertSame(['SEO-01.noindex-desarrollo' => 'critico'], $conGravedad);
    }

    public function testLosCriteriosAccCubrenLos55CriteriosWcagSinRepetir(): void
    {
        $cubiertos = [];
        foreach (self::criterios() as $id => $criterio) {
            if ($criterio['bloque'] !== 'acc') {
                self::assertSame([], $criterio['wcag'], "$id no es de accesibilidad");
                continue;
            }
            self::assertNotEmpty($criterio['wcag'], $id);
            foreach ($criterio['wcag'] as $w) {
                self::assertArrayNotHasKey($w['criterio'], $cubiertos, "{$w['criterio']} repetido en $id");
                $cubiertos[$w['criterio']] = $w;
            }
        }

        self::assertEqualsCanonicalizing(array_keys(self::WCAG_A_AA), array_map('strval', array_keys($cubiertos)));
        self::assertArrayNotHasKey('4.1.1', $cubiertos);

        foreach ($cubiertos as $numero => $w) {
            self::assertSame(self::WCAG_A_AA[$numero], $w['nivel'], "Nivel de $numero");
            $version = in_array((string) $numero, self::NUEVOS_22, true) ? '2.2' : '2.1';
            self::assertSame($version, $w['version_wcag'], "Versión de $numero");
            self::assertNotSame('', trim($w['nombre']));
        }
    }

    public function testLasSubcomprobacionesAccCitanCriteriosWcagExistentes(): void
    {
        foreach (self::subcomprobaciones() as $sub) {
            foreach ($sub['wcag'] as $numero) {
                self::assertArrayHasKey($numero, self::WCAG_A_AA, "{$sub['id']}: WCAG $numero");
            }
        }
    }

    public function testLosCriteriosNuevosDe22NoCuentanParaElCompromisoWcag21(): void
    {
        foreach (self::subcomprobaciones() as $sub) {
            $usaNuevo = array_intersect($sub['wcag'], self::NUEVOS_22) !== [];
            if ($usaNuevo) {
                self::assertNotContains('wcag_2_1_aa', $sub['kit_digital'], $sub['id']);
            }
        }
    }

    public function testLaMarcaMvpDeLosCriteriosCoincideConLaEspecificacion(): void
    {
        $esperado = [
            'IA-01' => 'si', 'IA-02' => 'si', 'IA-03' => 'parcial', 'IA-04' => 'no', 'IA-05' => 'no',
            'IA-06' => 'no', 'IA-07' => 'parcial', 'IA-08' => 'si', 'IA-09' => 'no', 'IA-10' => 'parcial',
            'IA-11' => 'parcial',
            'SEO-01' => 'si', 'SEO-02' => 'si', 'SEO-03' => 'parcial', 'SEO-04' => 'parcial',
            'SEO-05' => 'parcial', 'SEO-06' => 'no', 'SEO-07' => 'si', 'SEO-08' => 'parcial', 'SEO-09' => 'no',
            'SEO-10' => 'parcial', 'SEO-11' => 'psi', 'SEO-12' => 'si', 'SEO-13' => 'no', 'SEO-14' => 'parcial',
            'SEO-15' => 'si', 'SEO-16' => 'no',
            'ACC-01' => 'parcial', 'ACC-02' => 'parcial', 'ACC-03' => 'parcial', 'ACC-04' => 'no',
            'ACC-05' => 'psi', 'ACC-06' => 'parcial', 'ACC-07' => 'no', 'ACC-08' => 'no', 'ACC-09' => 'parcial',
            'ACC-10' => 'parcial', 'ACC-11' => 'no', 'ACC-12' => 'parcial',
        ];
        $real = array_map(static fn (array $c): string => $c['mvp'], self::criterios());
        ksort($esperado);
        self::assertSame($esperado, $real);
    }

    public function testUnCriterioFueraDelMvpNoTieneSubcomprobacionesDentro(): void
    {
        foreach (self::criterios() as $id => $criterio) {
            if ($criterio['mvp'] !== 'no') {
                continue;
            }
            foreach ($criterio['subcomprobaciones'] as $sub) {
                self::assertSame('no', $sub['mvp'], "$id está fuera del MVP pero {$sub['id']} no");
            }
        }
    }
}
