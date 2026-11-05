<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Comprobaciones;

use DOMXPath;
use ProconSEO\Engine\Comprobacion;
use ProconSEO\Engine\Entorno;
use ProconSEO\Engine\Estructurados;
use ProconSEO\Shared\Contract\BorradorResultado;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\Gravedad;
use ProconSEO\Shared\Enum\Sector;
use ProconSEO\Shared\Enum\TipoConclusion;
use ProconSEO\Shared\Enum\TipoEvidencia;
use RuntimeException;

/**
 * IA-07 (datos estructurados) y SEO-14 (resultados enriquecidos y perfil de producto).
 * La ausencia de datos estructurados nunca es un problema.
 */
final class DatosEstructurados implements Comprobacion
{
    /**
     * Propiedades obligatorias de Google para los tipos con resultado enriquecido más
     * habituales en las webs de Proconsi. Una lista dentro de la lista significa «una de ellas».
     */
    private const OBLIGATORIAS = [
        'Product' => ['name', ['offers', 'review', 'aggregateRating']],
        'Event' => ['name', 'startDate', 'location'],
        'Recipe' => ['name', 'image'],
        'VideoObject' => ['name', 'thumbnailUrl', 'uploadDate'],
        'BreadcrumbList' => ['itemListElement'],
        'FAQPage' => ['mainEntity'],
        'JobPosting' => ['title', 'description', 'datePosted', 'hiringOrganization', 'jobLocation'],
        'Course' => ['name', 'description', 'provider'],
    ];

    /** Sectores que suelen tener atención local (especificación 6.8). */
    private const SECTORES_LOCALES = [Sector::Hidrocarburos, Sector::Salud, Sector::Alimentacion];

    public function subcomprobaciones(): array
    {
        return [
            'IA-07.jsonld-sintaxis' => Cobertura::Implementada,
            'IA-07.microdata-rdfa' => Cobertura::Implementada,
            'IA-07.tipos-schema' => Cobertura::ParcialmenteImplementada,
            'IA-07.negocio-local' => Cobertura::Implementada,
            'SEO-14.elegibilidad-enriquecidos' => Cobertura::ParcialmenteImplementada,
            'SEO-14.producto-marcado' => Cobertura::Implementada,
        ];
    }

    public function evaluar(string $id, Entorno $e): BorradorResultado
    {
        return match ($id) {
            'IA-07.jsonld-sintaxis' => $this->sintaxis($e),
            'IA-07.microdata-rdfa' => $this->microdataRdfa($e),
            'IA-07.tipos-schema' => $this->tiposSchema($e),
            'IA-07.negocio-local' => $this->negocioLocal($e),
            'SEO-14.elegibilidad-enriquecidos' => $this->elegibilidad($e),
            'SEO-14.producto-marcado' => $this->producto($e),
            default => throw new RuntimeException("Subcomprobación desconocida: $id"),
        };
    }

    private function sintaxis(Entorno $e): BorradorResultado
    {
        $bloques = $e->documento()->jsonLd;
        $v = $e->resultado('IA-07.jsonld-sintaxis');
        if ($bloques === []) {
            return $v->noAplicable('La página no tiene JSON-LD; su ausencia no es un problema.');
        }
        $errores = array_values(array_filter($bloques, static fn (array $b): bool => $b['error'] !== null));
        if ($errores === []) {
            return $v->valor(count($bloques) . ' bloque(s) JSON-LD sin errores de sintaxis.')->correcto();
        }
        $detalle = array_map(static fn (array $b): string => $b['error'] . ': ' . mb_substr($b['crudo'], 0, 300), $errores);
        return $v->valor(count($errores) . ' de ' . count($bloques) . ' bloque(s) JSON-LD no se pueden leer.')
            ->elemento(mb_substr($errores[0]['crudo'], 0, 200))
            ->evidencia($e->evidencia(TipoEvidencia::FragmentoHtml, implode("\n---\n", $detalle)))
            ->consecuencia('Los buscadores descartan entero un bloque JSON-LD con errores: esos datos no sirven para nada.')
            ->correccion('Corregir la sintaxis (comas sobrantes, comillas…). Se puede comprobar con la Prueba de resultados enriquecidos de Google o el validador de schema.org.')
            ->problema(Gravedad::Medio);
    }

    private function microdataRdfa(Entorno $e): BorradorResultado
    {
        $doc = $e->documento();
        $xp = new DOMXPath($doc->dom);
        $rdfa = $xp->query('//*[@typeof or @vocab]');
        $numRdfa = $rdfa === false ? 0 : $rdfa->length;
        $v = $e->resultado('IA-07.microdata-rdfa');
        if ($doc->tiposMicrodatos === [] && $numRdfa === 0) {
            return $v->noAplicable('La página no usa Microdata ni RDFa.');
        }
        $tipos = array_count_values(array_map(static fn (string $t): string => (string) preg_replace('~^(https?://)?(www\.)?schema\.org/~i', '', $t), $doc->tiposMicrodatos));
        return $v->valor('Microdata: ' . ($tipos === [] ? 'ninguno' : implode(', ', array_map(static fn (string $t, int $n): string => "$t ($n)", array_keys($tipos), $tipos))) . '; RDFa: ' . $numRdfa . ' elemento(s).')
            ->correcto();
    }

    private function tiposSchema(Entorno $e): BorradorResultado
    {
        $doc = $e->documento();
        $v = $e->resultado('IA-07.tipos-schema');
        $validos = array_values(array_filter($doc->jsonLd, static fn (array $b): bool => $b['error'] === null));
        if ($validos === [] && $doc->tiposMicrodatos === []) {
            return $v->noAplicable('No hay datos estructurados legibles.');
        }
        $avisos = [];
        foreach ($validos as $b) {
            $datos = $b['datos'];
            $contexto = is_array($datos) && !array_is_list($datos) ? ($datos['@context'] ?? null) : null;
            $textoContexto = is_string($contexto) ? $contexto : json_encode($contexto);
            if (is_array($datos) && !array_is_list($datos) && !str_contains(strtolower((string) $textoContexto), 'schema.org')) {
                $avisos[] = 'Bloque sin @context de schema.org.';
            }
        }
        $nodos = Estructurados::nodosJsonLd($doc);
        $tipos = [];
        foreach ($nodos as $nodo) {
            foreach (Estructurados::tipos($nodo) as $t) {
                $tipos[] = $t;
                if (preg_match('/^[A-Z][A-Za-z0-9]+$/', $t) !== 1) {
                    $avisos[] = 'Tipo con formato no válido: «' . mb_substr($t, 0, 60) . '».';
                }
            }
        }
        if ($validos !== [] && $nodos === []) {
            $avisos[] = 'Ningún objeto JSON-LD declara @type.';
        }
        $micro = array_map(static fn (string $t): string => (string) preg_replace('~^(https?://)?(www\.)?schema\.org/~i', '', $t), $doc->tiposMicrodatos);
        $todos = array_values(array_unique(array_merge($tipos, $micro)));
        $v->valor('Tipos: ' . ($todos === [] ? 'ninguno' : implode(', ', $todos)) . '.')
            ->limitacion('No se valida contra todo el vocabulario de schema.org ni la coherencia con lo visible (IA-07.coherencia-visible).');
        if ($avisos === []) {
            return $v->correcto();
        }
        return $v->evidencia($e->evidencia(TipoEvidencia::Texto, implode("\n", $avisos)))
            ->consecuencia('Sin @context o @type válidos, los buscadores no saben qué describe el marcado.')
            ->correccion('Incluir "@context": "https://schema.org" y un "@type" de schema.org en cada objeto.')
            ->mejorable();
    }

    private function negocioLocal(Entorno $e): BorradorResultado
    {
        $doc = $e->documento();
        $v = $e->resultado('IA-07.negocio-local');
        $negocios = array_values(array_filter(Estructurados::nodosJsonLd($doc), static fn (array $n): bool => Estructurados::esDeTipo($n, ...Estructurados::NEGOCIO_LOCAL)));
        $porSector = in_array($e->contexto->sector, self::SECTORES_LOCALES, true);

        if ($negocios === []) {
            if (!$porSector) {
                return $v->noAplicable('No hay marcado de negocio local y el sector del proyecto no lo requiere.');
            }
            return $v->valor('No hay marcado LocalBusiness (ni de un tipo más concreto).')
                ->consecuencia('Google y los asistentes con IA tienen que deducir de la página la dirección, el teléfono y el horario.')
                ->correccion('Añadir JSON-LD de tipo ' . ($e->contexto->sector === Sector::Hidrocarburos ? 'GasStation' : 'LocalBusiness (o el tipo concreto, p. ej. MedicalClinic)') . ' con name, address, telephone y openingHours.')
                ->mejorable();
        }

        $n = $negocios[0];
        $tipo = Estructurados::tipos($n)[0] ?? 'LocalBusiness';
        $faltan = [];
        if (Estructurados::texto($n, 'name') === null) {
            $faltan[] = 'name';
        }
        $direccion = Estructurados::objetos($n, 'address')[0] ?? null;
        if ($direccion === null && Estructurados::texto($n, 'address') === null) {
            $faltan[] = 'address';
        } elseif ($direccion !== null) {
            foreach (['streetAddress', 'addressLocality'] as $p) {
                if (Estructurados::texto($direccion, $p) === null) {
                    $faltan[] = 'address.' . $p;
                }
            }
        }

        $telefonoMarcado = Estructurados::texto($n, 'telephone');
        $telefonosVisibles = Estructurados::telefonos($doc);
        $normalizado = $telefonoMarcado === null ? null : Estructurados::normalizarTelefono($telefonoMarcado);
        $v->valor($tipo . ' «' . (Estructurados::texto($n, 'name') ?? '—') . '»'
            . ($telefonoMarcado !== null ? ', teléfono ' . $telefonoMarcado : '')
            . ($faltan !== [] ? '; faltan: ' . implode(', ', $faltan) : '') . '.');

        if ($normalizado !== null && $telefonosVisibles !== [] && !in_array($normalizado, $telefonosVisibles, true)) {
            return $v->evidencia($e->evidencia(TipoEvidencia::Texto, 'Teléfono en el marcado: ' . $telefonoMarcado . "\nTeléfonos visibles: " . implode(', ', $telefonosVisibles)))
                ->consecuencia('El marcado da un teléfono distinto del que ve el usuario: buscadores y asistentes pueden mostrar el equivocado.')
                ->correccion('Poner en el marcado el mismo teléfono que aparece en la página.')
                ->problema(Gravedad::Medio);
        }
        if ($faltan !== []) {
            return $v->consecuencia('Sin ' . implode(', ', $faltan) . ', el marcado no sirve para mostrar el negocio en búsquedas locales.')
                ->correccion('Completar el marcado ' . $tipo . ' con ' . implode(', ', $faltan) . '.')
                ->mejorable();
        }
        return $v->conclusion($telefonosVisibles === [] ? TipoConclusion::Inferencia : TipoConclusion::Hecho)
            ->limitacion('Se comprueba el teléfono con lo visible; la dirección y el horario, solo que estén.')
            ->correcto();
    }

    private function elegibilidad(Entorno $e): BorradorResultado
    {
        $doc = $e->documento();
        $v = $e->resultado('SEO-14.elegibilidad-enriquecidos');
        $nodos = Estructurados::nodosJsonLd($doc);
        if ($nodos === [] && $doc->tiposMicrodatos === []) {
            return $v->noAplicable('No hay datos estructurados.');
        }
        $revisados = [];
        $incompletos = [];
        foreach ($nodos as $nodo) {
            foreach (Estructurados::tipos($nodo) as $tipo) {
                if (!isset(self::OBLIGATORIAS[$tipo])) {
                    continue;
                }
                $faltan = self::faltan($nodo, self::OBLIGATORIAS[$tipo]);
                $revisados[] = $tipo;
                if ($faltan !== []) {
                    $incompletos[] = $tipo . ': falta ' . implode(', ', $faltan);
                }
            }
        }
        if ($revisados === []) {
            return $v->valor('Tipos sin resultado enriquecido revisado en esta versión.')
                ->noAplicable('Ningún tipo de los revisados (Product, Event, Recipe, VideoObject, BreadcrumbList, FAQPage, JobPosting, Course).');
        }
        $v->valor('Revisados: ' . implode(', ', array_unique($revisados)) . '.')
            ->limitacion('Solo se comprueban las propiedades obligatorias en JSON-LD; la elegibilidad final la decide Google.');
        if ($incompletos === []) {
            return $v->correcto();
        }
        return $v->evidencia($e->evidencia(TipoEvidencia::Texto, implode("\n", $incompletos)))
            ->consecuencia('Sin las propiedades obligatorias, Google no muestra el resultado enriquecido.')
            ->correccion('Completar: ' . implode('; ', $incompletos) . '.')
            ->mejorable();
    }

    private function producto(Entorno $e): BorradorResultado
    {
        $doc = $e->documento();
        $v = $e->resultado('SEO-14.producto-marcado');
        $jsonld = array_values(array_filter(Estructurados::nodosJsonLd($doc), static fn (array $n): bool => Estructurados::esDeTipo($n, 'Product', 'ProductGroup')));
        $micro = array_values(array_filter(Estructurados::microdatos($doc), static fn (array $i): bool => in_array('Product', $i['tipo'], true)));

        if ($jsonld === [] && $micro === []) {
            return $v->noAplicable('No es una página de producto (no hay marcado Product).');
        }

        $faltan = [];
        if ($jsonld !== []) {
            $p = $jsonld[0];
            $origen = 'JSON-LD';
            $nombre = Estructurados::texto($p, 'name');
            $ofertas = Estructurados::objetos($p, 'offers');
            $oferta = $ofertas[0] ?? null;
            $precio = $oferta === null ? null : (Estructurados::texto($oferta, 'price') ?? Estructurados::texto($oferta, 'lowPrice'));
            $moneda = $oferta === null ? null : Estructurados::texto($oferta, 'priceCurrency');
            $disponibilidad = $oferta === null ? null : Estructurados::texto($oferta, 'availability');
        } else {
            $p = $micro[0];
            $origen = 'Microdata';
            $nombre = isset($p['props']['name'][0]) && is_string($p['props']['name'][0]) && $p['props']['name'][0] !== '' ? $p['props']['name'][0] : null;
            $oferta = isset($p['props']['offers'][0]) && is_array($p['props']['offers'][0]) ? $p['props']['offers'][0]['props'] : null;
            $leer = static fn (?array $o, string $k): ?string => isset($o[$k][0]) && is_string($o[$k][0]) && $o[$k][0] !== '' ? $o[$k][0] : null;
            $precio = $leer($oferta, 'price') ?? $leer($oferta, 'lowPrice');
            $moneda = $leer($oferta, 'priceCurrency');
            $disponibilidad = $leer($oferta, 'availability');
        }

        if ($nombre === null) {
            $faltan[] = 'name';
        }
        if ($oferta === null) {
            $faltan[] = 'offers';
        } else {
            if ($precio === null) {
                $faltan[] = 'offers.price';
            }
            if ($moneda === null) {
                $faltan[] = 'offers.priceCurrency';
            }
        }
        $v->valor('Product (' . $origen . ') «' . ($nombre ?? '—') . '»'
            . ($precio !== null ? ', ' . $precio . ' ' . ($moneda ?? '') : '')
            . ($disponibilidad !== null ? ', ' . (string) preg_replace('~^https?://schema\.org/~', '', $disponibilidad) : '')
            . ($faltan !== [] ? '; faltan: ' . implode(', ', $faltan) : '') . '.')
            ->limitacion('No se compara el precio ni la disponibilidad con lo visible (SEO-14.producto-precio-coherente).');

        if ($faltan === []) {
            return $disponibilidad === null
                ? $v->correccion('Añadir offers.availability (InStock, OutOfStock…) para mostrar la disponibilidad.')->correcto()
                : $v->correcto();
        }
        return $v->consecuencia('Sin ' . implode(', ', $faltan) . ', Google no puede mostrar el precio del producto en los resultados ni en Google Shopping.')
            ->correccion('Completar el marcado del producto con ' . implode(', ', $faltan) . '.')
            ->correccionCms([
                'woocommerce' => 'WooCommerce genera el marcado a partir de los datos del producto: comprobar que el producto tiene «Precio normal» y que ningún plugin desactiva el marcado.',
                'prestashop' => 'El tema debe incluir itemprop="price" y itemprop="priceCurrency" en la plantilla product.tpl; los temas recientes lo traen.',
            ])
            ->problema(Gravedad::Medio);
    }

    /**
     * @param array<string, mixed> $nodo
     * @param list<string|list<string>> $obligatorias
     * @return list<string>
     */
    private static function faltan(array $nodo, array $obligatorias): array
    {
        $faltan = [];
        foreach ($obligatorias as $o) {
            $opciones = is_array($o) ? $o : [$o];
            $alguna = false;
            foreach ($opciones as $p) {
                if (isset($nodo[$p]) && $nodo[$p] !== '' && $nodo[$p] !== []) {
                    $alguna = true;
                }
            }
            if (!$alguna) {
                $faltan[] = implode(' o ', $opciones);
            }
        }
        return $faltan;
    }
}
