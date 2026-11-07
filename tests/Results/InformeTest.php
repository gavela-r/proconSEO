<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Results;

use PHPUnit\Framework\TestCase;
use ProconSEO\Results\Informe;
use ProconSEO\Shared\Contract\Catalogo;
use ProconSEO\Shared\Contract\FichaComprobacion;
use ProconSEO\Shared\Enum\Bloque;
use ProconSEO\Shared\Enum\Cobertura;

/**
 * Orden y agrupación del informe (especificación 4.4: RF-20, RF-21, RF-24).
 */
final class InformeTest extends TestCase
{
    public function testResumenCuentaPorEstadoVigenteYBloqueSinNotas(): void
    {
        $informe = new Informe([
            Filas::resultado(['bloque' => 'seo', 'estado' => 'problema', 'gravedad' => 'alto']),
            Filas::resultado(['bloque' => 'seo', 'estado' => 'decision_revisar', 'estado_vigente' => 'correcto', 'revisado' => true]),
            Filas::resultado(['bloque' => 'acc', 'estado' => 'no_verificable', 'cobertura' => 'no_implementada']),
            Filas::resultado(['bloque' => 'acc', 'estado' => 'no_verificable', 'revision_pendiente' => true]),
        ], [], []);

        self::assertSame(['ia', 'seo', 'acc'], array_keys($informe->resumen), 'Siempre los tres bloques, aunque no tengan resultados');
        self::assertSame(1, $informe->resumen['seo']['recuento']['problema']);
        self::assertSame(1, $informe->resumen['seo']['recuento']['correcto'], 'Cuenta el estado tras la revisión');
        self::assertSame(0, $informe->resumen['seo']['recuento']['decision_revisar']);
        self::assertSame(2, $informe->resumen['acc']['recuento']['no_verificable']);
        self::assertSame(1, $informe->resumen['acc']['no_implementadas']);
        self::assertSame(1, $informe->resumen['acc']['pendientes']);
        self::assertSame(0, $informe->resumen['ia']['total']);
        self::assertEqualsCanonicalizing(['recuento', 'total', 'no_implementadas', 'pendientes'], array_keys($informe->resumen['ia']), 'El resumen no tiene ningún campo de nota o porcentaje');
    }

    public function testUnaCausaSeMuestraUnaVezConTodosSusEfectos(): void
    {
        $causa = ['grupo_causa_id' => 7, 'causa_codigo' => 'C-001', 'causa_descripcion' => 'noindex olvidado'];
        $informe = new Informe([
            Filas::resultado($causa + ['bloque' => 'seo', 'estado' => 'problema', 'gravedad' => 'critico']),
            Filas::resultado($causa + ['bloque' => 'ia', 'estado' => 'problema', 'gravedad' => 'critico']),
            Filas::resultado(['estado' => 'mejorable']),
        ], [], []);

        self::assertCount(2, $informe->incidencias);
        self::assertSame('causa', $informe->incidencias[0]['tipo']);
        self::assertSame('C-001', $informe->incidencias[0]['codigo']);
        self::assertCount(2, $informe->incidencias[0]['resultados']);
        self::assertEqualsCanonicalizing(['seo', 'ia'], $informe->incidencias[0]['bloques']);
        self::assertSame(3, $informe->numeroIncidencias());
    }

    public function testLaMismaComprobacionEnVariasPaginasSeMuestraUnaVez(): void
    {
        $fila = static fn (string $url, array $mas = []): array => Filas::resultado($mas + ['id_comprobacion' => 'ACC-01.alt-ausente', 'estado' => 'problema', 'gravedad' => 'alto', 'url_recurso' => $url]);
        $informe = new Informe([
            $fila('https://ejemplo.es/a'),
            $fila('https://ejemplo.es/b'),
            $fila('https://ejemplo.es/c'),
            $fila('https://ejemplo.es/d', ['gravedad' => 'medio']),
            Filas::resultado(['estado' => 'mejorable']),
        ], [], []);

        self::assertCount(3, $informe->incidencias, 'Las tres de gravedad alta juntas; la de gravedad media aparte');
        self::assertSame('repetido', $informe->incidencias[0]['tipo']);
        self::assertCount(3, $informe->incidencias[0]['resultados']);
        self::assertSame('resultado', $informe->incidencias[1]['tipo']);
        self::assertSame(5, $informe->numeroIncidencias(), 'El recuento sigue contando cada resultado');
    }

    public function testEnLaMismaPaginaNoSeAgrupa(): void
    {
        $informe = new Informe([
            Filas::resultado(['estado' => 'problema', 'gravedad' => 'alto']),
            Filas::resultado(['estado' => 'problema', 'gravedad' => 'alto']),
        ], [], []);

        self::assertSame(['resultado', 'resultado'], array_column($informe->incidencias, 'tipo'));
    }

    public function testIncidenciasOrdenadasPorEstadoYGravedad(): void
    {
        $mejorable = Filas::resultado(['estado' => 'mejorable']);
        $bajo = Filas::resultado(['estado' => 'problema', 'gravedad' => 'bajo']);
        $decision = Filas::resultado(['estado' => 'decision_revisar']);
        $critico = Filas::resultado(['estado' => 'problema', 'gravedad' => 'critico']);
        $alto = Filas::resultado(['estado' => 'problema', 'gravedad' => 'alto']);

        $informe = new Informe([$mejorable, $bajo, $decision, $critico, $alto], [], []);

        self::assertSame(
            [$critico['id'], $alto['id'], $bajo['id'], $decision['id'], $mejorable['id']],
            array_map(static fn (array $e): int => $e['principal']['id'], $informe->incidencias),
        );
    }

    public function testLosBloqueosVanPrimero(): void
    {
        $noindex = Filas::resultado(['estado' => 'problema', 'gravedad' => 'critico', 'bloquea_dependientes' => true]);
        $otro = Filas::resultado(['estado' => 'problema', 'gravedad' => 'critico']);

        $informe = new Informe([$otro, $noindex], [], []);

        self::assertSame([$noindex['id']], array_map(static fn (array $e): int => $e['principal']['id'], $informe->bloqueos));
        self::assertSame([$otro['id']], array_map(static fn (array $e): int => $e['principal']['id'], $informe->incidencias));
    }

    public function testUnBloqueoRevisadoComoCorrectoYaNoBloquea(): void
    {
        $informe = new Informe([
            Filas::resultado(['estado' => 'problema', 'estado_vigente' => 'correcto', 'revisado' => true, 'bloquea_dependientes' => true]),
        ], [], []);

        self::assertSame([], $informe->bloqueos);
        self::assertCount(1, $informe->correctos);
    }

    public function testRecursosQueNoSePudieronDescargar(): void
    {
        $principalCaida = Filas::recurso(['resultado' => 'error_http', 'estado_http' => 503]);
        $robotsNoExiste = Filas::recurso(['url' => 'https://ejemplo.es/robots.txt', 'resultado' => 'error_http', 'estado_http' => 404, 'justificado_por' => 'IA-01']);
        $sitemapProhibido = Filas::recurso(['url' => 'https://ejemplo.es/sitemap.xml', 'resultado' => 'error_http', 'estado_http' => 403, 'justificado_por' => 'IA-08']);
        $truncado = Filas::recurso(['truncado' => 1]);

        $informe = new Informe([], [$principalCaida, $robotsNoExiste, $sitemapProhibido, $truncado, Filas::recurso()], []);

        self::assertSame([$principalCaida['id'], $sitemapProhibido['id']], array_column($informe->recursosBloqueados, 'id'));
        self::assertSame([$robotsNoExiste['id']], array_column($informe->recursosNoEncontrados, 'id'), 'Un robots.txt que no existe no es un bloqueo');
        self::assertSame([$truncado['id']], array_column($informe->recursosTruncados, 'id'));
    }

    public function testUnaPaginaPrincipalQueNoExisteSiEsBloqueo(): void
    {
        $informe = new Informe([], [Filas::recurso(['resultado' => 'error_http', 'estado_http' => 404])], []);

        self::assertCount(1, $informe->recursosBloqueados);
        self::assertSame([], $informe->recursosNoEncontrados);
    }

    public function testNoVerificablesPendientesPrimeroYNoImplementadasAparte(): void
    {
        $normal = Filas::resultado(['estado' => 'no_verificable']);
        $pendiente = Filas::resultado(['estado' => 'no_verificable', 'revision_pendiente' => true]);
        $noImplementada = Filas::resultado(['estado' => 'no_verificable', 'cobertura' => 'no_implementada']);

        $informe = new Informe([$normal, $noImplementada, $pendiente], [], []);

        self::assertSame([$pendiente['id'], $normal['id']], array_column($informe->noVerificables, 'id'));
        self::assertSame([$noImplementada['id']], array_column($informe->noImplementadas, 'id'));
        self::assertSame(1, $informe->pendientesDeRevision());
    }

    public function testCorrectosYNoAplicables(): void
    {
        $informe = new Informe([Filas::resultado(['estado' => 'correcto']), Filas::resultado(['estado' => 'no_aplicable'])], [], []);

        self::assertCount(1, $informe->correctos);
        self::assertCount(1, $informe->noAplicables);
        self::assertSame([], $informe->incidencias);
    }

    public function testFasesIncompletas(): void
    {
        $informe = new Informe([], [], [
            ['fase' => 'validacion', 'estado' => 'terminada', 'mensaje' => null],
            ['fase' => 'descubrimiento', 'estado' => 'omitida', 'mensaje' => null],
            ['fase' => 'renderizado', 'estado' => 'fallida', 'mensaje' => 'x'],
        ]);

        self::assertSame(['descubrimiento', 'renderizado'], array_column($informe->fasesIncompletas, 'fase'));
    }

    public function testTitulosDelCatalogoYSiNoEstaElIdentificador(): void
    {
        $catalogo = new class implements Catalogo {
            public function version(): string
            {
                return 'prueba';
            }

            public function todas(): array
            {
                return [];
            }

            public function obtener(string $idComprobacion): ?FichaComprobacion
            {
                return $idComprobacion === 'SEO-03.title-presente'
                    ? new FichaComprobacion('SEO-03.title-presente', 'SEO-03', Bloque::Seo, 'Title presente', 'x', Cobertura::Implementada, true, extra: ['nombre_criterio' => 'Title'])
                    : null;
            }
        };

        $informe = new Informe([Filas::resultado(), Filas::resultado(['id_comprobacion' => 'SEO-99.desconocida'])], [], [], $catalogo);

        self::assertSame(['Title presente', 'SEO-99.desconocida'], array_column($informe->correctos, 'titulo'));
        self::assertSame('Title', $informe->correctos[0]['nombre_criterio']);
    }
}
