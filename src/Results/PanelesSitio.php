<?php

declare(strict_types=1);

namespace ProconSEO\Results;

/**
 * Paneles de un análisis que juntan datos ya observados para verlos de un vistazo:
 *
 * - quién puede leer la web: buscadores, buscadores con IA y agentes de entrenamiento, según el
 *   robots.txt (comprobaciones IA-01.permiso-*);
 * - título y descripción de cada página, marcando los que se repiten entre páginas.
 *
 * No añade conclusiones nuevas: solo ordena lo que dicen los resultados. Los textos vienen de la web
 * analizada, así que son datos y se imprimen escapados. Clase pura, sin base de datos.
 */
final class PanelesSitio
{
    /** Comprobación → tipo de agente que cubre. */
    private const PERMISOS = [
        'IA-01.permiso-buscadores' => 'Buscador',
        'IA-01.permiso-buscadores-ia' => 'Buscador con IA',
        'IA-01.agentes-entrenamiento' => 'Entrenamiento de IA',
    ];

    /** @var list<array{agente: string, tipo: string, permitido: ?bool, texto: string}> */
    public readonly array $agentes;

    /** @var list<array{url: string, titulo: ?string, descripcion: ?string, titulo_repetido: int, descripcion_repetida: int}> */
    public readonly array $textos;

    /** Cuántos títulos y descripciones distintos se repiten en más de una página. */
    public readonly int $titulosRepetidos;
    public readonly int $descripcionesRepetidas;

    /** @param list<array<string, mixed>> $resultados */
    public function __construct(array $resultados)
    {
        $agentes = [];
        $titulos = [];
        $descripciones = [];
        foreach ($resultados as $r) {
            $id = (string) $r['id_comprobacion'];
            $valor = is_string($r['valor_observado'] ?? null) ? trim((string) $r['valor_observado']) : '';
            $url = (string) ($r['url_recurso'] ?? '');
            if (isset(self::PERMISOS[$id]) && $valor !== '') {
                // El robots.txt es del sitio: basta la primera vez que aparece cada agente.
                foreach (explode(';', $valor) as $parte) {
                    [$agente, $texto] = array_map('trim', explode(':', $parte, 2) + [1 => '']);
                    if ($agente !== '' && !isset($agentes[$agente])) {
                        $agentes[$agente] = ['agente' => $agente, 'tipo' => self::PERMISOS[$id], 'permitido' => self::permitido($texto), 'texto' => $texto];
                    }
                }
            }
            if ($url !== '' && $r['estado_vigente'] !== 'no_verificable' && $valor !== '') {
                if ($id === 'SEO-03.title-presente') {
                    $titulos[$url] = $valor;
                } elseif ($id === 'SEO-04.description-presente') {
                    $descripciones[$url] = $valor;
                }
            }
        }
        $this->agentes = array_values($agentes);

        $vecesTitulo = array_count_values(array_map(self::clave(...), $titulos));
        $vecesDescripcion = array_count_values(array_map(self::clave(...), $descripciones));
        $urls = array_unique([...array_keys($titulos), ...array_keys($descripciones)]);
        sort($urls);
        $this->textos = array_map(static fn (string $u): array => [
            'url' => $u,
            'titulo' => $titulos[$u] ?? null,
            'descripcion' => $descripciones[$u] ?? null,
            'titulo_repetido' => isset($titulos[$u]) && $vecesTitulo[self::clave($titulos[$u])] > 1 ? $vecesTitulo[self::clave($titulos[$u])] : 0,
            'descripcion_repetida' => isset($descripciones[$u]) && $vecesDescripcion[self::clave($descripciones[$u])] > 1 ? $vecesDescripcion[self::clave($descripciones[$u])] : 0,
        ], array_map('strval', $urls));
        $this->titulosRepetidos = count(array_filter($vecesTitulo, static fn (int $n): bool => $n > 1));
        $this->descripcionesRepetidas = count(array_filter($vecesDescripcion, static fn (int $n): bool => $n > 1));
    }

    private static function permitido(string $texto): ?bool
    {
        $t = mb_strtolower($texto);

        return match (true) {
            str_contains($t, 'bloquead') || str_contains($t, 'prohibid') || str_contains($t, 'no permitido') => false,
            str_contains($t, 'permitid') => true,
            default => null,
        };
    }

    /** Dos textos son el mismo aunque cambien mayúsculas o espacios. */
    private static function clave(string $texto): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($texto)));
    }
}
