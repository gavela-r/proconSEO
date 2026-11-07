<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Results;

use PHPUnit\Framework\TestCase;
use ProconSEO\Results\Informe;
use ProconSEO\Results\Prioridades;

/**
 * «Por dónde empezar»: un orden para decidir, nunca una nota ni un porcentaje.
 */
final class PrioridadesTest extends TestCase
{
    private static function fila(string $comprobacion, array $mas = []): array
    {
        return Filas::resultado($mas + ['id_comprobacion' => $comprobacion, 'titulo' => $comprobacion]);
    }

    public function testOrdenGravedadPaginasYEsfuerzo(): void
    {
        $informe = new Informe([
            self::fila('MEJORABLE', ['estado' => 'mejorable', 'esfuerzo' => 'bajo']),
            self::fila('DECISION', ['estado' => 'decision_revisar']),
            self::fila('ALTO-1PAG', ['estado' => 'problema', 'gravedad' => 'alto', 'esfuerzo' => 'bajo']),
            self::fila('ALTO-3PAG', ['estado' => 'problema', 'gravedad' => 'alto', 'esfuerzo' => 'alto', 'url_recurso' => 'https://ejemplo.es/a']),
            self::fila('ALTO-3PAG', ['estado' => 'problema', 'gravedad' => 'alto', 'esfuerzo' => 'alto', 'url_recurso' => 'https://ejemplo.es/b']),
            self::fila('ALTO-3PAG', ['estado' => 'problema', 'gravedad' => 'alto', 'esfuerzo' => 'alto', 'url_recurso' => 'https://ejemplo.es/c']),
            self::fila('CRITICO', ['estado' => 'problema', 'gravedad' => 'critico', 'esfuerzo' => 'alto']),
            self::fila('CORRECTO', ['estado' => 'correcto']),
        ], [], []);

        $p = Prioridades::desde($informe);

        self::assertSame(['CRITICO', 'ALTO-3PAG', 'ALTO-1PAG', 'DECISION', 'MEJORABLE'], array_column($p['empezar'], 'titulo'),
            'Gravedad primero; con la misma gravedad, la que afecta a más páginas');
        self::assertSame(3, $p['empezar'][1]['paginas']);
        self::assertTrue($p['empezar'][1]['repetido']);
        self::assertSame([], $p['rapidos'], 'ALTO-1PAG ya está entre los cinco primeros: no se repite como arreglo rápido');
        self::assertSame(['ALTO-1PAG'], array_column(Prioridades::desde($informe, 2)['rapidos'], 'titulo'), 'Rápidos: poco esfuerzo y que merezca la pena');
    }

    public function testUnaCausaSeTitulaConElNombreCortoDeLaComprobacion(): void
    {
        $causa = ['grupo_causa_id' => 9, 'causa_codigo' => 'imagenes-sin-alt', 'causa_descripcion' => 'Hay imágenes sin texto alternativo. Los buscadores, los asistentes con IA y los lectores de pantalla no saben qué muestran.'];
        $p = Prioridades::desde(new Informe([
            self::fila('Imágenes con atributo alt', $causa + ['estado' => 'problema', 'gravedad' => 'alto', 'bloque' => 'acc']),
            self::fila('Texto alternativo de imágenes', $causa + ['estado' => 'mejorable', 'bloque' => 'seo']),
        ], [], []));

        self::assertSame('Imágenes con atributo alt', $p['empezar'][0]['titulo'], 'La explicación larga va en la tarjeta, no en la lista');
        self::assertTrue($p['empezar'][0]['causa']);
        self::assertSame('#causa-imagenes-sin-alt', $p['empezar'][0]['enlace']);
    }

    public function testComoMuchoCincoYSinNada(): void
    {
        $filas = [];
        for ($i = 1; $i <= 8; $i++) {
            $filas[] = self::fila('P' . $i, ['estado' => 'problema', 'gravedad' => 'medio']);
        }

        self::assertCount(5, Prioridades::desde(new Informe($filas, [], []))['empezar']);
        self::assertSame(['empezar' => [], 'rapidos' => []], Prioridades::desde(new Informe([self::fila('OK')], [], [])));
    }

    public function testElEnlaceLlevaAlResultadoEnLaPagina(): void
    {
        $r = self::fila('X', ['estado' => 'problema', 'gravedad' => 'bajo']);
        $p = Prioridades::desde(new Informe([$r], [], []));

        self::assertSame('#r-' . $r['id'], $p['empezar'][0]['enlace']);
        self::assertArrayNotHasKey('porcentaje', $p['empezar'][0]);
    }
}
