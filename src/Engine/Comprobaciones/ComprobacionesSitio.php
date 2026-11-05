<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Comprobaciones;

use ProconSEO\Engine\Comprobacion;
use ProconSEO\Engine\Entorno;
use ProconSEO\Engine\NoVerificable;
use ProconSEO\Engine\Valoracion;
use ProconSEO\Shared\Contract\BorradorResultado;
use ProconSEO\Shared\Contract\ResumenPagina;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\TipoConclusion;
use ProconSEO\Shared\Enum\TipoEvidencia;
use RuntimeException;

/**
 * Comprobaciones que comparan las páginas de un sitio completo entre sí (ConsolidadorSitio):
 * SEO-03.title-duplicado, SEO-04.description-duplicada, IA-08.enlazado-interno y
 * SEO-08.parametros-variantes.
 *
 * - En cada página (evaluar) no se pueden valorar: con sitio completo se dejan para el final y,
 *   con otro alcance, quedan no_verificable por el alcance.
 * - Al final (consolidar) se valoran con los ResumenPagina de todas las páginas analizadas.
 * - Solo se comparan páginas que responden 2xx, son indexables y no declaran canonical a otra:
 *   una página con canonical a otra ya dice que es una copia.
 */
final class ComprobacionesSitio implements Comprobacion
{
    private const MAXIMO_GRUPOS = 20;
    private const MAXIMO_URLS = 10;

    public function subcomprobaciones(): array
    {
        return [
            'SEO-03.title-duplicado' => Cobertura::Implementada,
            'SEO-04.description-duplicada' => Cobertura::Implementada,
            'IA-08.enlazado-interno' => Cobertura::Implementada,
            // El contenido igual se aproxima con el mismo title: comparar el texto necesita IA (SEO-08.contenido-duplicado).
            'SEO-08.parametros-variantes' => Cobertura::ParcialmenteImplementada,
        ];
    }

    /** En una sola página no se pueden comparar páginas. */
    public function evaluar(string $id, Entorno $entorno): BorradorResultado
    {
        if (!isset($this->subcomprobaciones()[$id])) {
            throw new RuntimeException("Subcomprobación desconocida: $id");
        }
        if ($entorno->contexto->alcance === Alcance::SitioCompleto) {
            throw new NoVerificable('Se valora para todo el sitio al terminar el recorrido.');
        }
        $entorno->exigeAlcance(Alcance::SitioCompleto, 'comparar las páginas del sitio entre sí');
        throw new NoVerificable('Se valora para todo el sitio al terminar el recorrido.');
    }

    /**
     * @param list<ResumenPagina> $resumenes
     */
    public function consolidar(string $id, array $resumenes, Entorno $e): BorradorResultado
    {
        return match ($id) {
            'SEO-03.title-duplicado' => $this->repetidos($e->resultado($id), $resumenes, $e, 'title', static fn (ResumenPagina $r): ?string => $r->titulos[0] ?? null),
            'SEO-04.description-duplicada' => $this->repetidos($e->resultado($id), $resumenes, $e, 'description', static fn (ResumenPagina $r): ?string => $r->descripciones[0] ?? null),
            'IA-08.enlazado-interno' => $this->enlazado($e->resultado($id), $resumenes, $e),
            'SEO-08.parametros-variantes' => $this->variantes($e->resultado($id), $resumenes, $e),
            default => throw new RuntimeException("Subcomprobación desconocida: $id"),
        };
    }

    /** URL para comparar: esquema y host en minúsculas, sin fragmento ni barra final. */
    public static function normalizar(string $url): string
    {
        $p = parse_url($url);
        if ($p === false || !isset($p['host'])) {
            return $url;
        }
        $esquema = strtolower($p['scheme'] ?? 'https');
        $puerto = isset($p['port']) && !(($esquema === 'https' && $p['port'] === 443) || ($esquema === 'http' && $p['port'] === 80)) ? ':' . $p['port'] : '';
        $ruta = $p['path'] ?? '/';
        if ($ruta !== '/') {
            $ruta = rtrim($ruta, '/');
        }
        return $esquema . '://' . strtolower($p['host']) . $puerto . ($ruta === '' ? '/' : $ruta) . (isset($p['query']) ? '?' . $p['query'] : '');
    }

    private static function clave(?string $texto): string
    {
        return $texto === null ? '' : mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $texto)));
    }

    /**
     * Páginas que se pueden comparar: 2xx, indexables y sin canonical a otra URL.
     *
     * @param list<ResumenPagina> $resumenes
     * @return list<ResumenPagina>
     */
    private static function comparables(array $resumenes): array
    {
        $vistas = [];
        $lista = [];
        foreach ($resumenes as $r) {
            $url = self::normalizar($r->url);
            $ok = $r->estadoHttp !== null && $r->estadoHttp >= 200 && $r->estadoHttp < 300 && $r->indexable;
            if (!$ok || isset($vistas[$url]) || ($r->canonical !== null && self::normalizar($r->canonical) !== $url)) {
                continue;
            }
            $vistas[$url] = true;
            $lista[] = $r;
        }
        return $lista;
    }

    /** @param list<ResumenPagina> $paginas */
    private static function exigirVarias(array $paginas, int $analizadas): void
    {
        if (count($paginas) < 2) {
            throw new NoVerificable('Hacen falta al menos dos páginas indexables para comparar; se analizaron ' . $analizadas . ' y se pueden comparar ' . count($paginas) . '.');
        }
    }

    private static function limiteAnalizadas(int $n): string
    {
        return "Solo se comparan las $n páginas analizadas; las que quedaron fuera por los límites del recorrido no se tienen en cuenta.";
    }

    /**
     * Title o description repetidos en varias páginas.
     *
     * @param list<ResumenPagina> $resumenes
     * @param callable(ResumenPagina): ?string $valor
     */
    private function repetidos(Valoracion $v, array $resumenes, Entorno $e, string $que, callable $valor): BorradorResultado
    {
        $paginas = self::comparables($resumenes);
        self::exigirVarias($paginas, count($resumenes));

        $grupos = [];
        $textos = [];
        $sinValor = 0;
        foreach ($paginas as $p) {
            $texto = $valor($p);
            $clave = self::clave($texto);
            if ($clave === '') {
                $sinValor++;
                continue;
            }
            $grupos[$clave][] = $p->url;
            $textos[$clave] ??= trim((string) preg_replace('/\s+/u', ' ', (string) $texto));
        }
        $repetidos = array_filter($grupos, static fn (array $urls): bool => count($urls) > 1);
        uasort($repetidos, static fn (array $a, array $b): int => count($b) <=> count($a));

        $limitacion = self::limiteAnalizadas(count($resumenes));
        if ($sinValor > 0) {
            $limitacion .= " $sinValor página(s) sin $que no entran en la comparación (eso lo valora cada página).";
        }
        $v->limitacion($limitacion);
        if ($repetidos === []) {
            return $v->valor(count($paginas) . " páginas comparadas; ningún $que repetido.")->correcto();
        }

        $afectadas = array_sum(array_map('count', $repetidos));
        $lineas = [];
        foreach (array_slice($repetidos, 0, self::MAXIMO_GRUPOS, true) as $clave => $urls) {
            $lineas[] = '«' . mb_substr($textos[$clave], 0, 160) . '» (' . count($urls) . ' páginas)';
            foreach (array_slice($urls, 0, self::MAXIMO_URLS) as $url) {
                $lineas[] = '  ' . $url;
            }
            if (count($urls) > self::MAXIMO_URLS) {
                $lineas[] = '  … y ' . (count($urls) - self::MAXIMO_URLS) . ' más';
            }
        }
        $primero = array_key_first($repetidos);
        $v->valor(count($repetidos) . " $que repetido(s) en $afectadas de " . count($paginas) . ' páginas.')
            ->elemento(mb_substr($textos[$primero], 0, 160))
            ->evidencia($e->evidencia(TipoEvidencia::Texto, implode("\n", $lineas)));

        if ($que === 'title') {
            return $v
                ->consecuencia('Con el mismo título, Google no sabe qué página mostrar para cada búsqueda y en los resultados no se distinguen: suele mostrar una y dejar las otras.')
                ->correccion('Escribir un title distinto en cada página, que diga de qué trata esa página en concreto (producto, servicio, ciudad…).')
                ->correccionCms([
                    'wordpress' => 'En Yoast SEO o Rank Math, revisar la plantilla de título del tipo de contenido y escribir un título SEO propio en cada página.',
                    'woocommerce' => 'Revisar la plantilla de título de productos y categorías en el plugin SEO; que incluya el nombre del producto.',
                    'prestashop' => 'Catálogo > Productos > SEO (meta título) y Parámetros de la tienda > Tráfico y SEO para las páginas fijas.',
                ])
                ->mejorable();
        }
        return $v
            ->consecuencia('Con la misma descripción, los resultados de Google se ven iguales y no dicen qué tiene de especial cada página; Google suele sustituirla por un trozo del texto.')
            ->correccion('Escribir una meta description propia para cada página, que resuma su contenido en una o dos frases.')
            ->correccionCms([
                'wordpress' => 'En Yoast SEO o Rank Math, escribir la meta descripción de cada página o ajustar la plantilla para que use el extracto.',
                'prestashop' => 'Catálogo > Productos > SEO (meta descripción) en cada producto.',
            ])
            ->mejorable();
    }

    /** @param list<ResumenPagina> $resumenes */
    private function enlazado(Valoracion $v, array $resumenes, Entorno $e): BorradorResultado
    {
        $paginas = self::comparables($resumenes);
        self::exigirVarias($paginas, count($resumenes));

        $enlazadas = [];
        foreach ($resumenes as $r) {
            $propia = self::normalizar($r->url);
            foreach ($r->enlacesInternos as $destino) {
                $destino = self::normalizar($destino);
                if ($destino !== $propia) {
                    $enlazadas[$destino] = true;
                }
            }
        }
        $inicio = self::normalizar($e->url());
        $huerfanas = [];
        foreach ($paginas as $p) {
            $url = self::normalizar($p->url);
            if ($url !== $inicio && !isset($enlazadas[$url])) {
                $huerfanas[] = $p->url;
            }
        }

        $v->limitacion(self::limiteAnalizadas(count($resumenes)) . ' La página de inicio del análisis no cuenta: es la entrada.');
        if ($huerfanas === []) {
            return $v->valor('Las ' . (count($paginas) - 1) . ' páginas indexables (sin contar la de inicio) reciben enlaces de otras páginas analizadas.')->correcto();
        }
        $lista = array_slice($huerfanas, 0, self::MAXIMO_GRUPOS * self::MAXIMO_URLS);
        return $v->valor(count($huerfanas) . ' de ' . count($paginas) . ' páginas indexables no reciben ningún enlace de las demás páginas analizadas.')
            ->elemento($huerfanas[0])
            ->evidencia($e->evidencia(TipoEvidencia::Texto, implode("\n", $lista) . (count($huerfanas) > count($lista) ? "\n… y " . (count($huerfanas) - count($lista)) . ' más' : '')))
            ->consecuencia('Una página a la que no enlaza ninguna otra es difícil de encontrar: los buscadores y los asistentes con IA la consideran poco importante y las personas no llegan a ella navegando.')
            ->correccion('Enlazar estas páginas desde el menú, desde páginas relacionadas o desde el listado de su sección.')
            ->correccionCms([
                'wordpress' => 'Añadirlas en Apariencia > Menús o enlazarlas desde las páginas o entradas relacionadas.',
                'prestashop' => 'Asignar los productos a una categoría visible o enlazar las páginas CMS desde el pie.',
            ])
            ->mejorable();
    }

    /** @param list<ResumenPagina> $resumenes */
    private function variantes(Valoracion $v, array $resumenes, Entorno $e): BorradorResultado
    {
        $porRuta = [];
        foreach ($resumenes as $r) {
            if ($r->estadoHttp === null || $r->estadoHttp < 200 || $r->estadoHttp >= 300) {
                continue;
            }
            $url = self::normalizar($r->url);
            $ruta = (string) preg_replace('/\?.*$/', '', $url);
            $porRuta[$ruta][$url] = $r;
        }
        $conParametros = 0;
        $problemas = [];
        foreach ($porRuta as $ruta => $versiones) {
            foreach ($versiones as $url => $r) {
                if (!str_contains($url, '?')) {
                    continue;
                }
                $conParametros++;
                if (!$r->indexable || ($r->canonical !== null && self::normalizar($r->canonical) !== $url)) {
                    continue; // Ya dice cuál es la buena (noindex o canonical a otra).
                }
                $titulo = self::clave($r->titulos[0] ?? null);
                foreach ($versiones as $otraUrl => $otra) {
                    if ($otraUrl !== $url && $titulo !== '' && self::clave($otra->titulos[0] ?? null) === $titulo) {
                        $problemas[] = $url . '  =  ' . $otraUrl;
                        break;
                    }
                }
            }
        }

        if ($conParametros === 0) {
            return $v->noAplicable('Ninguna página analizada tiene parámetros en la URL.');
        }
        $v->conclusion(TipoConclusion::Inferencia)
            ->limitacion(self::limiteAnalizadas(count($resumenes)) . ' Que sean la misma página se deduce de que tienen la misma ruta y el mismo title; comparar el texto necesita IA (SEO-08.contenido-duplicado).');
        if ($problemas === []) {
            return $v->valor("$conParametros URL(s) con parámetros; todas declaran su canonical o noindex, o no se parecen a otra.")->correcto();
        }
        return $v->valor(count($problemas) . ' URL(s) con parámetros parecen la misma página que otra y no declaran canonical.')
            ->elemento(explode('  =  ', $problemas[0])[0])
            ->evidencia($e->evidencia(TipoEvidencia::Texto, implode("\n", array_slice($problemas, 0, self::MAXIMO_GRUPOS * self::MAXIMO_URLS))))
            ->consecuencia('Google puede indexar varias direcciones para el mismo contenido y repartir entre ellas la relevancia, o mostrar la versión con parámetros.')
            ->correccion('Declarar en cada variante con parámetros un canonical a la URL limpia, o no enlazar las variantes.')
            ->mejorable();
    }
}
