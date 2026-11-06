<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Comprobaciones;

use DOMElement;
use DOMXPath;
use ProconSEO\Engine\Comprobacion;
use ProconSEO\Engine\Entorno;
use ProconSEO\Engine\Extraccion\Url;
use ProconSEO\Shared\Contract\BorradorResultado;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\Gravedad;
use ProconSEO\Shared\Enum\TipoEvidencia;
use RuntimeException;

/**
 * SEO-16.paginacion: los listados paginados se pueden rastrear (guía de Google sobre
 * paginación y carga progresiva):
 *
 * - cada página del listado se enlaza con <a href> a su propia URL, no con botones,
 *   javascript: ni fragmentos (#pagina-2), que Google no sigue;
 * - la página 2 o siguientes no declaran como canónica la primera, porque entonces Google
 *   no indexa los productos que solo están en ellas.
 *
 * Sin navegador: los listados que cargan más resultados con JavaScript al bajar no se ven.
 */
final class Paginacion implements Comprobacion
{
    /** Clases, ids o etiquetas habituales de los bloques de paginación. */
    private const PISTAS = ['pagination', 'paginacion', 'paginación', 'pager', 'page-numbers', 'paginador', 'pagina', 'paging'];

    /** Textos de los enlaces de paginación que no son números. */
    private const SIGUIENTE_ANTERIOR = '/^(?:siguiente|anterior|next|prev|previous|primera|última|ultima|first|last|[»«›‹<>→←]+)$/iu';

    /** Número de página en la URL: /page/3/, /pagina/3, ?page=3, ?p=3, ?pagina=3, ?pg=3 (no /p/3, que en muchas tiendas es un producto). */
    private const NUMERO_EN_URL = '~(?:/(?:page|pagina|página)/(\d+)/?(?=$|[?#])|[?&](?:page|pagina|p|pg|paged)=(\d+))~iu';

    public function subcomprobaciones(): array
    {
        return [
            'SEO-16.paginacion' => Cobertura::ParcialmenteImplementada,
        ];
    }

    public function evaluar(string $id, Entorno $entorno): BorradorResultado
    {
        return match ($id) {
            'SEO-16.paginacion' => $this->paginacion($entorno),
            default => throw new RuntimeException("Subcomprobación desconocida: $id"),
        };
    }

    /** Número de página que indica la URL, o null si no indica ninguno. */
    public static function numeroPagina(string $url): ?int
    {
        if (preg_match(self::NUMERO_EN_URL, $url, $m) !== 1) {
            return null;
        }
        return (int) (($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '0'));
    }

    /** La misma URL sin el número de página: la primera página del listado. */
    public static function primeraPagina(string $url): string
    {
        $sin = (string) preg_replace('~/(?:page|pagina|página)/\d+/?(?=$|[?#])~iu', '/', Url::sinFragmento($url));
        $sin = (string) preg_replace('~([?&])(?:page|pagina|p|pg|paged)=\d+&?~iu', '$1', $sin);
        return rtrim($sin, '?&');
    }

    private function paginacion(Entorno $e): BorradorResultado
    {
        $doc = $e->documento();
        $xp = new DOMXPath($doc->dom);
        $actual = Url::sinFragmento($e->url());
        $v = $e->resultado('SEO-16.paginacion')
            ->limitacion('Sin navegador: no se ven los listados que cargan más resultados con JavaScript al bajar o con un botón «Ver más».');

        $controles = $this->controles($xp);
        $relNext = false;
        foreach ($xp->query('//link[@rel] | //a[@rel]') ?: [] as $l) {
            if ($l instanceof DOMElement && array_intersect(['next', 'prev'], preg_split('/\s+/', strtolower($l->getAttribute('rel'))) ?: []) !== []) {
                $relNext = true;
            }
        }
        if ($controles === [] && !$relNext) {
            return $v->noAplicable('La página no tiene un listado paginado.');
        }

        $rastreables = 0;
        $malos = [];
        foreach ($controles as $c) {
            $motivo = $this->motivoNoRastreable($c, $doc->urlBase, $actual);
            if ($motivo === null) {
                ++$rastreables;
            } elseif ($motivo !== '') {
                $malos[] = self::etiqueta($c) . ': ' . $motivo;
            }
        }

        $numero = self::numeroPagina($actual);
        $canonica = $doc->canonicos[0] ?? null;
        $canonicaEnPrimera = $numero !== null && $numero >= 2 && $canonica !== null
            && Url::sinFragmento($canonica) !== $actual && rtrim(Url::sinFragmento($canonica), '/') === rtrim(self::primeraPagina($actual), '/');

        $v->valor($rastreables . ' enlaces de paginación rastreables' . ($malos !== [] ? ', ' . count($malos) . ' que Google no puede seguir' : '')
            . ($numero !== null ? '; página ' . $numero . ' del listado' : '') . '.');

        if ($malos !== []) {
            return $v->elemento(explode(': ', $malos[0])[0])
                ->explicacion('La paginación usa botones o enlaces sin dirección propia: Google no pasa de la primera página.')
                ->evidencia($e->evidencia(TipoEvidencia::FragmentoHtml, implode("\n", array_slice($malos, 0, 20))))
                ->consecuencia('Los productos o artículos que solo están en la página 2 o siguientes no se descubren ni aparecen en Google.')
                ->correccion('Enlazar cada página con <a href="…?page=2"> (o /page/2/), cada una con su propia URL. Un botón «Ver más» puede seguir, pero con esos enlaces también.')
                ->correccionCms([
                    'woocommerce' => 'Los temas que cambian la paginación por «scroll infinito» o «Cargar más» suelen tener una opción para mantener los enlaces numerados.',
                    'prestashop' => 'Revisar que el módulo de listado (o de scroll infinito) no quite los enlaces de pagination.tpl.',
                ])
                ->problema(Gravedad::Medio);
        }
        if ($canonicaEnPrimera) {
            return $v->elemento('<link rel="canonical" href="' . $canonica . '">')
                ->explicacion('Esta página del listado dice que su versión principal es la primera página.')
                ->evidencia($e->evidencia(TipoEvidencia::FragmentoHtml, '<link rel="canonical" href="' . $canonica . '">'))
                ->consecuencia('Google puede ignorar esta página y no indexar los productos o artículos que solo están en ella.')
                ->correccion('Cada página del listado debe ser canónica de sí misma: en ' . $actual . ', canonical a ' . $actual . '.')
                ->mejorable();
        }
        return $v->correcto();
    }

    /**
     * Enlaces y botones de los bloques de paginación que llevan a otra página del listado:
     * números y siguiente/anterior. La página actual (un <span>, o aria-current) no cuenta.
     *
     * @return list<DOMElement>
     */
    private function controles(DOMXPath $xp): array
    {
        $minus = "translate(concat(@class, ' ', @id, ' ', @aria-label), 'ABCDEFGHIJKLMNOPQRSTUVWXYZÁ', 'abcdefghijklmnopqrstuvwxyzá')";
        $condiciones = implode(' or ', array_map(static fn (string $p): string => 'contains(' . $minus . ', "' . $p . '")', self::PISTAS));
        $lista = [];
        $vistos = [];
        foreach ($xp->query('//*[self::nav or self::ul or self::ol or self::div or self::p][' . $condiciones . ']') ?: [] as $bloque) {
            if (!$bloque instanceof DOMElement) {
                continue;
            }
            foreach ($xp->query('.//a | .//button', $bloque) ?: [] as $c) {
                if (!$c instanceof DOMElement || $c->getAttribute('aria-current') !== '' || isset($vistos[spl_object_id($c)])) {
                    continue;
                }
                $texto = trim((string) preg_replace('/\s+/u', ' ', $c->textContent));
                $texto = $texto !== '' ? $texto : trim($c->getAttribute('aria-label'));
                if (preg_match('/^\d{1,4}$/', $texto) === 1 || preg_match(self::SIGUIENTE_ANTERIOR, $texto) === 1
                    || preg_match('/p[aá]gina siguiente|p[aá]gina anterior|next page|previous page/iu', $texto) === 1) {
                    $vistos[spl_object_id($c)] = true;
                    $lista[] = $c;
                }
            }
        }
        return $lista;
    }

    /** null si Google lo puede seguir; '' si no se tiene en cuenta (lleva a la propia página); si no, el motivo. */
    private function motivoNoRastreable(DOMElement $c, string $base, string $actual): ?string
    {
        if ($c->nodeName === 'button') {
            return 'es un botón, no un enlace';
        }
        if (!$c->hasAttribute('href')) {
            return 'enlace sin href';
        }
        $href = trim($c->getAttribute('href'));
        if ($href === '' || str_starts_with($href, '#')) {
            return 'solo cambia el fragmento (' . ($href === '' ? 'href vacío' : $href) . ')';
        }
        if (Url::esNoNavegable($href)) {
            return 'usa ' . strtolower((string) Url::esquema($href)) . ':';
        }
        $url = Url::resolver($base, $href);
        if ($url === null) {
            return 'dirección no válida';
        }
        if (Url::sinFragmento($url) === $actual) {
            // «Primera» o «anterior» en la primera página pueden llevar a sí misma: no es un fallo.
            return str_contains($url, '#') ? 'solo cambia el fragmento (' . substr($url, (int) strpos($url, '#')) . ')' : '';
        }
        return null;
    }

    private static function etiqueta(DOMElement $c): string
    {
        $atributos = '';
        foreach (['href', 'class', 'onclick'] as $a) {
            if ($c->hasAttribute($a)) {
                $atributos .= ' ' . $a . '="' . mb_substr($c->getAttribute($a), 0, 60) . '"';
            }
        }
        $texto = trim((string) preg_replace('/\s+/u', ' ', $c->textContent));
        return '<' . $c->nodeName . $atributos . '>' . mb_substr($texto, 0, 30) . '</' . $c->nodeName . '>';
    }
}
