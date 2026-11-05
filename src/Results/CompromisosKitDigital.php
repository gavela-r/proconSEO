<?php

declare(strict_types=1);

namespace ProconSEO\Results;

use ProconSEO\Shared\Contract\Catalogo;
use ProconSEO\Shared\Enum\Gravedad;
use ProconSEO\Shared\Enum\TipoProyecto;

/**
 * Vista "Compromisos Kit Digital" (especificación 6.7, RF-07). No es una nota: es la misma lista
 * de resultados, agrupada según lo que Proconsi promete en las webs y tiendas del Kit Digital.
 * Qué comprobación va en cada compromiso lo dice el catálogo (campo kit_digital de cada subcomprobación).
 */
final class CompromisosKitDigital
{
    /** Códigos del catálogo (catalog/v1/catalogo.json → valores.kit_digital), en el orden de la especificación. */
    public const COMPROMISOS = [
        'indexacion' => [
            'nombre' => 'Indexación correcta',
            'ayuda' => 'La web tiene que poder aparecer en Google y Bing. Error típico: dejar puesto el «no indexar» de cuando la web estaba en desarrollo.',
        ],
        'seo_on_page' => [
            'nombre' => 'SEO on-page',
            'ayuda' => 'Título, descripción, encabezados e imágenes de las páginas.',
        ],
        'paginas_minimas' => [
            'nombre' => 'Al menos 3 páginas',
            'ayuda' => 'La web tiene al menos tres páginas internas enlazadas y accesibles. Necesita un análisis «URL con contexto» o «Sitio completo».',
        ],
        'responsive' => [
            'nombre' => 'Responsive',
            'ayuda' => 'La web se ve y funciona bien en el móvil.',
        ],
        'wcag_2_1_aa' => [
            'nombre' => 'Accesibilidad WCAG 2.1 AA',
            'ayuda' => 'Proconsi promete WCAG 2.1 nivel AA, la que exige la norma UNE-EN 301 549. Los criterios nuevos de WCAG 2.2 se muestran aparte porque no forman parte del compromiso.',
        ],
        'segundo_idioma' => [
            'nombre' => 'Segundo idioma (si se contrató)',
            'ayuda' => 'Si se contrató otro idioma además del castellano, cada versión indica su idioma y enlaza a las demás.',
        ],
        'dominio_https' => [
            'nombre' => 'Dominio y HTTPS',
            'ayuda' => 'La web va siempre por conexión segura, sin contenido ni formularios sin cifrar.',
        ],
        'tienda_producto' => [
            'nombre' => 'Tienda: catálogo y producto',
            'ayuda' => 'Las fichas de producto tienen su marcado de producto, con precio y disponibilidad coherentes.',
        ],
    ];

    /** Orden de los resultados dentro de cada compromiso: primero lo que hay que corregir. */
    private const ORDEN_ESTADOS = ['problema', 'decision_revisar', 'mejorable', 'no_verificable', 'correcto', 'no_aplicable'];

    public static function aplica(?string $tipoProyecto): bool
    {
        return in_array(TipoProyecto::tryFrom((string) $tipoProyecto), [TipoProyecto::WebKitDigital, TipoProyecto::TiendaKitDigital], true);
    }

    /**
     * @param list<array<string, mixed>> $resultados filas con estado_vigente y titulo (Informe::conTitulos)
     * @return list<array{codigo: string, nombre: string, ayuda: string, aplica: bool, resultados: list<array<string, mixed>>, fuera: list<array<string, mixed>>, recuento: array<string, int>, criticos: int}>
     */
    public static function agrupar(array $resultados, Catalogo $catalogo, ?string $tipoProyecto): array
    {
        $porCompromiso = array_fill_keys(array_keys(self::COMPROMISOS), []);
        foreach ($resultados as $r) {
            $ficha = $catalogo->obtener((string) $r['id_comprobacion']);
            $codigos = (array) ($ficha?->extra['kit_digital'] ?? []);
            // Los criterios nuevos de WCAG 2.2 no están en el compromiso, pero se enseñan aparte
            // (especificación 6.7 y 10) para que se vean sin contar como incumplimiento.
            if ($r['bloque'] === 'acc' && Etiquetas::esNuevo22($r) && !in_array('wcag_2_1_aa', $codigos, true)) {
                $codigos[] = 'wcag_2_1_aa';
            }
            foreach ($codigos as $codigo) {
                if (isset($porCompromiso[$codigo])) {
                    $porCompromiso[$codigo][] = $r;
                }
            }
        }

        $compromisos = [];
        foreach (self::COMPROMISOS as $codigo => $datos) {
            $lista = $porCompromiso[$codigo];
            usort($lista, self::comparar(...));

            // Los criterios nuevos de WCAG 2.2 no son incumplimientos del compromiso WCAG 2.1 AA (especificación 10).
            $fuera = $codigo === 'wcag_2_1_aa' ? array_values(array_filter($lista, Etiquetas::esNuevo22(...))) : [];
            $dentro = $codigo === 'wcag_2_1_aa' ? array_values(array_filter($lista, static fn (array $r): bool => !Etiquetas::esNuevo22($r))) : $lista;

            $recuento = [];
            foreach ($dentro as $r) {
                $recuento[(string) $r['estado_vigente']] = ($recuento[(string) $r['estado_vigente']] ?? 0) + 1;
            }

            $compromisos[] = [
                'codigo' => $codigo,
                'nombre' => $datos['nombre'],
                'ayuda' => $datos['ayuda'],
                'aplica' => $codigo !== 'tienda_producto' || TipoProyecto::tryFrom((string) $tipoProyecto) === TipoProyecto::TiendaKitDigital,
                'resultados' => $dentro,
                'fuera' => $fuera,
                'recuento' => $recuento,
                'criticos' => count(array_filter($dentro, static fn (array $r): bool => $r['estado_vigente'] === 'problema' && $r['gravedad'] === Gravedad::Critico->value)),
            ];
        }

        return $compromisos;
    }

    private static function comparar(array $a, array $b): int
    {
        $rango = static fn (array $r): array => [
            array_search($r['estado_vigente'], self::ORDEN_ESTADOS, true),
            $r['estado_vigente'] === 'problema' ? array_search(Gravedad::tryFrom((string) $r['gravedad']), Gravedad::cases(), true) : 9,
            $r['id'],
        ];

        return $rango($a) <=> $rango($b);
    }
}
