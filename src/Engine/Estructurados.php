<?php

declare(strict_types=1);

namespace ProconSEO\Engine;

use DOMElement;
use DOMXPath;
use ProconSEO\Engine\Extraccion\DocumentoHtml;

/**
 * Lectura de datos estructurados ya extraídos: nodos JSON-LD (incluido @graph) y elementos
 * de Microdata. Solo interpreta la estructura; no valida contra todo el vocabulario.
 */
final class Estructurados
{
    /** Tipos de schema.org que describen un negocio con atención local (selección habitual). */
    public const NEGOCIO_LOCAL = [
        'LocalBusiness', 'GasStation', 'AutoRepair', 'AutoDealer', 'MedicalClinic', 'Dentist', 'Physician',
        'Pharmacy', 'Hospital', 'Optician', 'Restaurant', 'Bakery', 'CafeOrCoffeeShop', 'BarOrPub', 'FoodEstablishment',
        'Store', 'GroceryStore', 'HardwareStore', 'ClothingStore', 'Plumber', 'Electrician', 'HVACBusiness',
        'HomeAndConstructionBusiness', 'GeneralContractor', 'LegalService', 'Attorney', 'AccountingService',
        'RealEstateAgent', 'TravelAgency', 'BeautySalon', 'HairSalon', 'HealthClub', 'Hotel', 'LodgingBusiness',
        'VeterinaryCare', 'ProfessionalService', 'FinancialService', 'InsuranceAgency', 'MovingCompany',
    ];

    /**
     * Nodos JSON-LD válidos (objetos con @type), aplanando listas y @graph.
     *
     * @return list<array<string, mixed>>
     */
    public static function nodosJsonLd(DocumentoHtml $doc): array
    {
        $nodos = [];
        foreach ($doc->jsonLd as $bloque) {
            if ($bloque['error'] === null) {
                self::recoger($bloque['datos'], $nodos, 0);
            }
        }
        return $nodos;
    }

    /** @param list<array<string, mixed>> $nodos */
    private static function recoger(mixed $dato, array &$nodos, int $profundidad): void
    {
        if (!is_array($dato) || $profundidad > 8) {
            return;
        }
        if (array_is_list($dato)) {
            foreach ($dato as $d) {
                self::recoger($d, $nodos, $profundidad + 1);
            }
            return;
        }
        if (isset($dato['@type'])) {
            $nodos[] = $dato;
        }
        if (isset($dato['@graph'])) {
            self::recoger($dato['@graph'], $nodos, $profundidad + 1);
        }
    }

    /**
     * Tipos de un nodo sin el prefijo de schema.org.
     *
     * @param array<string, mixed> $nodo
     * @return list<string>
     */
    public static function tipos(array $nodo): array
    {
        $tipos = is_array($nodo['@type'] ?? null) ? $nodo['@type'] : [$nodo['@type'] ?? ''];
        $limpios = [];
        foreach ($tipos as $t) {
            if (is_string($t) && $t !== '') {
                $limpios[] = (string) preg_replace('~^(https?://)?(www\.)?schema\.org/~i', '', $t);
            }
        }
        return $limpios;
    }

    /** @param array<string, mixed> $nodo */
    public static function esDeTipo(array $nodo, string ...$tipos): bool
    {
        return array_intersect(self::tipos($nodo), $tipos) !== [];
    }

    /** @param array<string, mixed> $nodo Valor de texto de una propiedad (o null si falta o está vacía). */
    public static function texto(array $nodo, string $propiedad): ?string
    {
        $v = $nodo[$propiedad] ?? null;
        if (is_array($v)) {
            $v = array_is_list($v) ? ($v[0] ?? null) : ($v['@value'] ?? $v['url'] ?? $v['name'] ?? $v['@id'] ?? null);
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }

    /**
     * Objetos anidados de una propiedad (offers, address…).
     *
     * @param array<string, mixed> $nodo
     * @return list<array<string, mixed>>
     */
    public static function objetos(array $nodo, string $propiedad): array
    {
        $v = $nodo[$propiedad] ?? null;
        if (!is_array($v)) {
            return [];
        }
        $lista = array_is_list($v) ? $v : [$v];
        return array_values(array_filter($lista, 'is_array'));
    }

    /**
     * Elementos de Microdata de primer nivel, con sus propiedades simples y anidadas.
     *
     * @return list<array{tipo: list<string>, props: array<string, list<mixed>>}>
     */
    public static function microdatos(DocumentoHtml $doc): array
    {
        $xp = new DOMXPath($doc->dom);
        $items = [];
        foreach ($xp->query('//*[@itemscope][not(@itemprop)]') ?: [] as $e) {
            if ($e instanceof DOMElement) {
                $items[] = self::item($xp, $e, 0);
            }
        }
        return $items;
    }

    /** @return array{tipo: list<string>, props: array<string, list<mixed>>} */
    private static function item(DOMXPath $xp, DOMElement $raiz, int $profundidad): array
    {
        $tipos = [];
        foreach (preg_split('/\s+/', trim($raiz->getAttribute('itemtype'))) ?: [] as $t) {
            if ($t !== '') {
                $tipos[] = (string) preg_replace('~^(https?://)?(www\.)?schema\.org/~i', '', $t);
            }
        }
        $props = [];
        // Propiedades cuyo itemscope más cercano es esta raíz.
        foreach ($xp->query('.//*[@itemprop]', $raiz) ?: [] as $p) {
            if (!$p instanceof DOMElement || self::ambito($p) !== $raiz) {
                continue;
            }
            foreach (preg_split('/\s+/', trim($p->getAttribute('itemprop'))) ?: [] as $nombre) {
                if ($nombre === '') {
                    continue;
                }
                $props[$nombre][] = $p->hasAttribute('itemscope') && $profundidad < 4
                    ? self::item($xp, $p, $profundidad + 1)
                    : self::valorMicrodato($p);
            }
        }
        return ['tipo' => $tipos, 'props' => $props];
    }

    private static function ambito(DOMElement $e): ?DOMElement
    {
        for ($n = $e->parentNode; $n instanceof DOMElement; $n = $n->parentNode) {
            if ($n->hasAttribute('itemscope')) {
                return $n;
            }
        }
        return null;
    }

    private static function valorMicrodato(DOMElement $e): string
    {
        foreach (['content', 'href', 'src', 'datetime', 'value'] as $atributo) {
            if ($e->hasAttribute($atributo)) {
                return trim($e->getAttribute($atributo));
            }
        }
        return trim((string) preg_replace('/\s+/u', ' ', $e->textContent));
    }

    /**
     * Teléfonos españoles normalizados a 9 cifras (sin +34) que aparecen en enlaces tel: y en
     * el texto visible.
     *
     * @return list<string>
     */
    public static function telefonos(DocumentoHtml $doc): array
    {
        $fuentes = [$doc->texto];
        foreach ($doc->enlaces as $a) {
            if (str_starts_with(strtolower($a['href']), 'tel:')) {
                $fuentes[] = substr($a['href'], 4);
            }
        }
        $telefonos = [];
        foreach ($fuentes as $texto) {
            $texto = (string) preg_replace('/(?:\+|\b00)34[\s.\-]?/', ' ', $texto);
            preg_match_all('/(?<!\d)([6-9](?:[\s.\-]?\d){8})(?!\d)/', $texto, $m);
            foreach ($m[1] as $t) {
                $telefonos[] = (string) preg_replace('/\D/', '', $t);
            }
        }
        return array_values(array_unique($telefonos));
    }

    public static function normalizarTelefono(string $telefono): ?string
    {
        $cifras = (string) preg_replace('/\D/', '', $telefono);
        if (str_starts_with($cifras, '34') && strlen($cifras) === 11) {
            $cifras = substr($cifras, 2);
        }
        return strlen($cifras) === 9 ? $cifras : null;
    }
}
