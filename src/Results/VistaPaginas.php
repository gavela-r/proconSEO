<?php

declare(strict_types=1);

namespace ProconSEO\Results;

use ProconSEO\Shared\Enum\Gravedad;
use ProconSEO\Shared\Enum\ResultadoDescarga;

/**
 * Vista «por páginas» de un análisis y el inventario de lo que se descargó.
 *
 * Con un sitio completo no basta la lista de hallazgos: hay que ver qué páginas concentran los
 * problemas, qué zonas de la web (plantillas) se parecen y qué respondió cada dirección. Son
 * recuentos por estado, nunca una nota ni un porcentaje. Clase pura: recibe filas ya leídas.
 */
final class VistaPaginas
{
    private const INCIDENCIAS = ['problema', 'decision_revisar', 'mejorable'];
    public const ORDENES = ['problemas' => 'Más problemas primero', 'gravedad' => 'Más grave primero', 'direccion' => 'Por dirección'];

    /**
     * @var list<array{url: string, zona: string, estado_http: ?int, bytes: ?int, tipo: ?string, redirige: bool,
     *                 problemas: int, decisiones: int, mejorables: int, correctos: int, no_verificables: int,
     *                 por_bloque: array<string, int>, gravedad: ?string, comprobaciones: int}>
     */
    public readonly array $paginas;

    /** @var list<array{zona: string, paginas: int, problemas: int, decisiones: int, mejorables: int, gravedad: ?string}> */
    public readonly array $zonas;

    /**
     * Inventario de todo lo descargado (páginas, robots.txt, sitemaps, enlaces comprobados…).
     *
     * @var array{total: int, por_codigo: array<string, int>, por_resultado: array<string, int>, redirecciones: int, bytes: int, rotos: list<array{url: string, estado_http: ?int, motivo: ?string}>}
     */
    public readonly array $inventario;

    /**
     * @param list<array<string, mixed>> $resultados filas con estado_vigente (RepositorioResultados::resultados)
     * @param list<array<string, mixed>> $recursos   RepositorioResultados::recursos
     */
    public function __construct(array $resultados, array $recursos, string $orden = 'problemas')
    {
        $porUrl = [];
        foreach ($recursos as $rec) {
            $porUrl[(string) $rec['url']] ??= $rec;
            if ($rec['url_final'] !== null) {
                $porUrl[(string) $rec['url_final']] ??= $rec;
            }
        }

        $paginas = [];
        foreach ($resultados as $r) {
            $url = (string) ($r['url_recurso'] ?? '');
            if ($url === '') {
                continue;
            }
            $rec = $porUrl[$url] ?? null;
            $paginas[$url] ??= [
                'url' => $url,
                'zona' => self::zona($url),
                'estado_http' => $rec !== null && $rec['estado_http'] !== null ? (int) $rec['estado_http'] : null,
                'bytes' => $rec !== null && $rec['bytes'] !== null ? (int) $rec['bytes'] : null,
                'tipo' => $rec !== null ? self::tipo((string) ($rec['tipo_contenido'] ?? '')) : null,
                'redirige' => $rec !== null && $rec['url_final'] !== null && $rec['url_final'] !== $rec['url'],
                'problemas' => 0, 'decisiones' => 0, 'mejorables' => 0, 'correctos' => 0, 'no_verificables' => 0,
                'por_bloque' => ['ia' => 0, 'seo' => 0, 'acc' => 0],
                'gravedad' => null,
                'ids' => [],
            ];
            $p = &$paginas[$url];
            $estado = (string) $r['estado_vigente'];
            $p['ids'][(string) $r['id_comprobacion']] = true;
            match ($estado) {
                'problema' => $p['problemas']++,
                'decision_revisar' => $p['decisiones']++,
                'mejorable' => $p['mejorables']++,
                'correcto' => $p['correctos']++,
                'no_verificable' => $p['no_verificables']++,
                default => null,
            };
            if (in_array($estado, self::INCIDENCIAS, true) && isset($p['por_bloque'][(string) $r['bloque']])) {
                $p['por_bloque'][(string) $r['bloque']]++;
            }
            if ($estado === 'problema') {
                $p['gravedad'] = self::masGrave($p['gravedad'], Gravedad::tryFrom((string) $r['gravedad'])?->value);
            }
            unset($p);
        }

        $lista = array_values(array_map(static function (array $p): array {
            $p['comprobaciones'] = count($p['ids']);
            unset($p['ids']);

            return $p;
        }, $paginas));
        usort($lista, self::comparador($orden));
        $this->paginas = $lista;
        $this->zonas = self::zonas($lista);
        $this->inventario = self::inventario($recursos);
    }

    /** La primera parte de la ruta («/blog/», «/productos/»): suele corresponder a una plantilla. */
    public static function zona(string $url): string
    {
        $ruta = (string) (parse_url($url, PHP_URL_PATH) ?? '/');
        $partes = array_values(array_filter(explode('/', $ruta), static fn (string $s): bool => $s !== ''));
        if ($partes === []) {
            return '/';
        }
        // Un fichero en la raíz (robots.txt, sitemap.xml) es un recurso suelto, no una zona.
        if (count($partes) === 1 && str_contains($partes[0], '.')) {
            return '/' . $partes[0];
        }

        return count($partes) === 1 ? '/ (páginas sueltas)' : '/' . $partes[0] . '/';
    }

    private static function tipo(string $tipoContenido): ?string
    {
        return match (true) {
            str_contains($tipoContenido, 'html') => 'página',
            str_contains($tipoContenido, 'xml') => 'XML',
            str_contains($tipoContenido, 'text/plain') => 'texto',
            $tipoContenido === '' => null,
            default => 'otro',
        };
    }

    private static function masGrave(?string $a, ?string $b): ?string
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return self::rango($a) <= self::rango($b) ? $a : $b;
    }

    private static function rango(?string $gravedad): int
    {
        $g = $gravedad === null ? null : Gravedad::tryFrom($gravedad);
        $rango = $g === null ? false : array_search($g, Gravedad::cases(), true);

        return $rango === false ? 9 : (int) $rango;
    }

    /** @return callable(array, array): int */
    private static function comparador(string $orden): callable
    {
        return match ($orden) {
            'direccion' => static fn (array $a, array $b): int => strcmp($a['url'], $b['url']),
            'gravedad' => static fn (array $a, array $b): int => [self::rango($a['gravedad']), -$a['problemas'], -$a['decisiones'] - $a['mejorables'], $a['url']]
                <=> [self::rango($b['gravedad']), -$b['problemas'], -$b['decisiones'] - $b['mejorables'], $b['url']],
            default => static fn (array $a, array $b): int => [-$a['problemas'], self::rango($a['gravedad']), -$a['decisiones'] - $a['mejorables'], $a['url']]
                <=> [-$b['problemas'], self::rango($b['gravedad']), -$b['decisiones'] - $b['mejorables'], $b['url']],
        };
    }

    /**
     * @param list<array<string, mixed>> $paginas
     * @return list<array{zona: string, paginas: int, problemas: int, decisiones: int, mejorables: int, gravedad: ?string}>
     */
    private static function zonas(array $paginas): array
    {
        /** @var array<string, array{zona: string, paginas: int, problemas: int, decisiones: int, mejorables: int, gravedad: ?string}> $zonas */
        $zonas = [];
        foreach ($paginas as $p) {
            $clave = (string) $p['zona'];
            $z = $zonas[$clave] ?? ['zona' => $clave, 'paginas' => 0, 'problemas' => 0, 'decisiones' => 0, 'mejorables' => 0, 'gravedad' => null];
            $z['paginas']++;
            $z['problemas'] += (int) $p['problemas'];
            $z['decisiones'] += (int) $p['decisiones'];
            $z['mejorables'] += (int) $p['mejorables'];
            $z['gravedad'] = self::masGrave($z['gravedad'], $p['gravedad'] === null ? null : (string) $p['gravedad']);
            $zonas[$clave] = $z;
        }
        $zonas = array_values($zonas);
        usort($zonas, static fn (array $a, array $b): int => [-$a['paginas'], $a['zona']] <=> [-$b['paginas'], $b['zona']]);

        return $zonas;
    }

    /**
     * @param list<array<string, mixed>> $recursos
     * @return array{total: int, por_codigo: array<string, int>, por_resultado: array<string, int>, redirecciones: int, bytes: int, rotos: list<array{url: string, estado_http: ?int, motivo: ?string}>}
     */
    private static function inventario(array $recursos): array
    {
        $porCodigo = [];
        $porResultado = [];
        $redirecciones = 0;
        $bytes = 0;
        $rotos = [];
        foreach ($recursos as $rec) {
            $resultado = (string) $rec['resultado'];
            $porResultado[$resultado] = ($porResultado[$resultado] ?? 0) + 1;
            if ($rec['estado_http'] !== null) {
                $familia = intdiv((int) $rec['estado_http'], 100) . 'xx';
                $porCodigo[$familia] = ($porCodigo[$familia] ?? 0) + 1;
            }
            if ($rec['url_final'] !== null && $rec['url_final'] !== $rec['url']) {
                $redirecciones++;
            }
            $bytes += (int) ($rec['bytes'] ?? 0);
            $falla = $resultado !== ResultadoDescarga::Correcto->value && $resultado !== ResultadoDescarga::FueraDeAmbito->value;
            if ($falla) {
                $rotos[] = ['url' => (string) $rec['url'], 'estado_http' => $rec['estado_http'] === null ? null : (int) $rec['estado_http'], 'motivo' => $rec['motivo'] === null ? null : (string) $rec['motivo']];
            }
        }
        ksort($porCodigo);
        arsort($porResultado);

        return [
            'total' => count($recursos),
            'por_codigo' => $porCodigo,
            'por_resultado' => $porResultado,
            'redirecciones' => $redirecciones,
            'bytes' => $bytes,
            'rotos' => $rotos,
        ];
    }
}
