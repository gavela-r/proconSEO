<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Results;

use PHPUnit\Framework\TestCase;
use ProconSEO\Results\PanelesSitio;
use ProconSEO\Results\VistaPaginas;
use ProconSEO\Results\VistaWcag;
use ProconSEO\Review\RevisionEnBloque;
use ProconSEO\Shared\Vista;

/**
 * Vistas para un sitio completo: por páginas (con inventario), por criterios WCAG y revisión en bloque.
 * Sin base de datos. Son recuentos: nunca una nota ni un porcentaje.
 */
final class VistasSitioCompletoTest extends TestCase
{
    private const MALICIOSO = '<script>alert(1)</script>';

    protected function setUp(): void
    {
        Vista::configurar(dirname(__DIR__, 2) . '/templates');
        $_SESSION = [];
    }

    public function testPaginasOrdenadasPorProblemasConSuRespuestaYZona(): void
    {
        $r = static fn (string $url, string $estado, array $mas = []): array => Filas::resultado($mas + ['url_recurso' => $url, 'estado' => $estado]);
        $vista = new VistaPaginas([
            $r('https://ejemplo.es/', 'correcto'),
            $r('https://ejemplo.es/blog/a', 'problema', ['gravedad' => 'medio', 'bloque' => 'acc']),
            $r('https://ejemplo.es/blog/a', 'problema', ['gravedad' => 'critico', 'bloque' => 'seo', 'id_comprobacion' => 'SEO-01.x']),
            $r('https://ejemplo.es/blog/b', 'mejorable', ['bloque' => 'ia']),
            $r('https://ejemplo.es/robots.txt', 'correcto'),
        ], [
            Filas::recurso(['url' => 'https://ejemplo.es/blog/a', 'url_final' => 'https://ejemplo.es/blog/a', 'bytes' => 2048]),
            Filas::recurso(['url' => 'http://ejemplo.es/', 'url_final' => 'https://ejemplo.es/', 'justificado_por' => 'SEO-01.https']),
            Filas::recurso(['url' => 'https://ejemplo.es/roto', 'url_final' => null, 'estado_http' => 404, 'resultado' => 'error_http', 'motivo' => 'enlace roto']),
            Filas::recurso(['url' => 'https://www.ejemplo.es/', 'url_final' => null, 'estado_http' => null, 'resultado' => 'fuera_de_ambito']),
        ]);

        $primera = $vista->paginas[0];
        self::assertSame('https://ejemplo.es/blog/a', $primera['url']);
        self::assertSame(2, $primera['problemas']);
        self::assertSame('critico', $primera['gravedad'], 'La más grave de la página');
        self::assertSame(['ia' => 0, 'seo' => 1, 'acc' => 1], $primera['por_bloque']);
        self::assertSame(200, $primera['estado_http']);
        self::assertSame(2048, $primera['bytes']);
        self::assertSame(2, $primera['comprobaciones']);
        self::assertSame('/blog/', $primera['zona']);
        self::assertSame('/blog/', $vista->zonas[0]['zona'], 'La zona con más páginas primero');
        self::assertSame(2, $vista->zonas[0]['paginas']);

        self::assertSame(4, $vista->inventario['total']);
        self::assertSame(['2xx' => 2, '4xx' => 1], $vista->inventario['por_codigo']);
        self::assertSame(1, $vista->inventario['redirecciones']);
        self::assertSame([['url' => 'https://ejemplo.es/roto', 'estado_http' => 404, 'motivo' => 'enlace roto']], $vista->inventario['rotos'], 'Lo que quedó fuera del ámbito no es un fallo');
        self::assertSame(['https://ejemplo.es/', 'https://ejemplo.es/blog/a', 'https://ejemplo.es/blog/b', 'https://ejemplo.es/robots.txt'],
            array_column((new VistaPaginas([$r('https://ejemplo.es/blog/b', 'mejorable'), $r('https://ejemplo.es/', 'correcto'), $r('https://ejemplo.es/robots.txt', 'correcto'), $r('https://ejemplo.es/blog/a', 'problema')], [], 'direccion'))->paginas, 'url'));
    }

    public function testZonas(): void
    {
        self::assertSame('/', VistaPaginas::zona('https://ejemplo.es/'));
        self::assertSame('/ (páginas sueltas)', VistaPaginas::zona('https://ejemplo.es/contacto/'));
        self::assertSame('/robots.txt', VistaPaginas::zona('https://ejemplo.es/robots.txt'));
        self::assertSame('/productos/', VistaPaginas::zona('https://ejemplo.es/productos/sillas/roja?color=1'));
    }

    public function testCriteriosWcagNuncaDicenCumple(): void
    {
        $r = static fn (string $criterio, string $estado, array $mas = []): array => Filas::resultado($mas + ['wcag_criterio' => $criterio, 'estado' => $estado, 'bloque' => 'acc']);
        $wcag = new VistaWcag([
            $r('1.1.1', 'problema', ['gravedad' => 'alto', 'url_recurso' => 'https://ejemplo.es/a', 'id_comprobacion' => 'ACC-01.alt']),
            $r('1.1.1', 'correcto', ['url_recurso' => 'https://ejemplo.es/b', 'id_comprobacion' => 'ACC-01.alt']),
            $r('1.4.3', 'correcto'),
            $r('3.1.2', 'no_verificable'),
            $r('2.4.4', 'correcto', ['revision_pendiente' => true]),
            $r('1.2.1', 'no_aplicable'),
        ]);
        $por = array_column($wcag->criterios, null, 'numero');

        self::assertCount(55, $wcag->criterios, 'Los 55 criterios A y AA de WCAG 2.2');
        self::assertArrayNotHasKey('4.1.1', $por, 'Retirado en WCAG 2.2');
        self::assertSame('problemas', $por['1.1.1']['situacion']);
        self::assertSame([['id' => $por['1.1.1']['comprobaciones'][0]['id'], 'titulo' => 'ACC-01.alt', 'estado' => 'problema', 'paginas' => 1, 'total' => 2]],
            $por['1.1.1']['comprobaciones'], 'Una línea por comprobación con su peor estado: problema en 1 de 2 páginas');
        self::assertSame('sin_problemas', $por['1.4.3']['situacion']);
        self::assertSame('revisar', $por['3.1.2']['situacion']);
        self::assertSame('revisar', $por['2.4.4']['situacion'], 'Con una revisión pendiente, falta revisar');
        self::assertSame('no_aplica', $por['1.2.1']['situacion']);
        self::assertSame('sin_comprobar', $por['2.1.1']['situacion']);
        self::assertTrue($por['2.5.8']['nuevo22']);
        self::assertSame(55, array_sum($wcag->resumen));
        self::assertArrayNotHasKey('cumple', VistaWcag::SITUACIONES);
    }

    public function testPanelesQuienPuedeLeerYTitulosRepetidos(): void
    {
        $r = static fn (string $id, string $valor, string $url = 'https://ejemplo.es/', string $estado = 'correcto'): array => Filas::resultado(['id_comprobacion' => $id, 'valor_observado' => $valor, 'url_recurso' => $url, 'estado' => $estado]);
        $p = new PanelesSitio([
            $r('IA-01.permiso-buscadores', 'Googlebot: permitido; bingbot: permitido'),
            $r('IA-01.permiso-buscadores-ia', 'OAI-SearchBot: bloqueado; PerplexityBot: permitido'),
            $r('IA-01.permiso-buscadores-ia', 'OAI-SearchBot: permitido', 'https://ejemplo.es/b'),
            $r('IA-01.agentes-entrenamiento', 'GPTBot: no permitido'),
            $r('SEO-03.title-presente', 'Inicio | Ejemplo', 'https://ejemplo.es/a'),
            $r('SEO-03.title-presente', 'inicio  | ejemplo', 'https://ejemplo.es/b'),
            $r('SEO-03.title-presente', 'Contacto', 'https://ejemplo.es/c'),
            $r('SEO-04.description-presente', 'Sin dato fiable', 'https://ejemplo.es/c', 'no_verificable'),
        ]);

        $agentes = array_column($p->agentes, null, 'agente');
        self::assertCount(5, $agentes, 'Cada agente una vez, aunque el robots.txt se evalúe en cada página');
        self::assertFalse($agentes['OAI-SearchBot']['permitido']);
        self::assertSame('Buscador con IA', $agentes['OAI-SearchBot']['tipo']);
        self::assertFalse($agentes['GPTBot']['permitido'], '«no permitido» es bloqueado');
        self::assertTrue($agentes['Googlebot']['permitido']);

        $textos = array_column($p->textos, null, 'url');
        self::assertSame(2, $textos['https://ejemplo.es/a']['titulo_repetido'], 'Iguales aunque cambien mayúsculas y espacios');
        self::assertSame(0, $textos['https://ejemplo.es/c']['titulo_repetido']);
        self::assertNull($textos['https://ejemplo.es/c']['descripcion'], 'Un no verificable no es un dato');
        self::assertSame(1, $p->titulosRepetidos);
        self::assertSame(0, $p->descripcionesRepetidas);
    }

    public function testRevisionEnBloqueSoloALoIgualYSinRevisar(): void
    {
        $base = ['id_comprobacion' => 'ACC-01.alt', 'estado' => 'problema', 'gravedad' => 'alto', 'analisis_id' => 7];
        $esta = Filas::resultado($base + ['url_recurso' => 'https://ejemplo.es/a']);
        $igual = Filas::resultado($base + ['url_recurso' => 'https://ejemplo.es/b']);
        $yaRevisada = Filas::resultado($base + ['url_recurso' => 'https://ejemplo.es/c', 'revisado' => true]);
        $otroEstado = Filas::resultado(['estado' => 'correcto'] + $base + ['url_recurso' => 'https://ejemplo.es/d']);
        $otraComprobacion = Filas::resultado(['id_comprobacion' => 'ACC-02.x'] + $base);
        $otroAnalisis = Filas::resultado(['analisis_id' => 8] + $base);

        self::assertSame([$igual['id']], array_column(RevisionEnBloque::iguales([$esta, $igual, $yaRevisada, $otroEstado, $otraComprobacion, $otroAnalisis], $esta), 'id'));
    }

    public function testPlantillasEscapanLaDireccionDeLaWeb(): void
    {
        $url = 'https://ejemplo.es/' . self::MALICIOSO;
        $resultados = [Filas::resultado(['url_recurso' => $url, 'estado' => 'problema', 'gravedad' => 'alto', 'wcag_criterio' => '1.1.1', 'titulo' => self::MALICIOSO])];
        $comun = ['analisis' => Filas::analisis(), 'conKitDigital' => false];

        $paginas = Vista::render('results/paginas', $comun + ['paginas' => new VistaPaginas($resultados, [Filas::recurso(['url' => $url, 'resultado' => 'error_http', 'estado_http' => 500, 'motivo' => self::MALICIOSO])]), 'orden' => 'problemas'], false);
        $wcag = Vista::render('results/wcag', $comun + ['wcag' => new VistaWcag($resultados)], false);

        foreach ([$paginas, $wcag] as $html) {
            self::assertStringNotContainsString('<script>alert', $html);
            self::assertStringContainsString('&lt;script&gt;', $html);
            self::assertStringNotContainsString('%', strip_tags(preg_replace('/(href|title)="[^"]*"/', '', $html) ?? ''), 'Sin porcentajes');
        }
        self::assertStringContainsString('aria-current="page">Por páginas', $paginas);
        self::assertStringContainsString('Sin problemas detectados', $wcag);
        self::assertStringNotContainsString('Cumple', $wcag);
    }
}
