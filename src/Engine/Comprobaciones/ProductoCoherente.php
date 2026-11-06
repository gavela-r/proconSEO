<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Comprobaciones;

use ProconSEO\Engine\Comprobacion;
use ProconSEO\Engine\Entorno;
use ProconSEO\Engine\Estructurados;
use ProconSEO\Shared\Contract\BorradorResultado;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\Gravedad;
use ProconSEO\Shared\Enum\TipoEvidencia;
use RuntimeException;

/**
 * SEO-14, perfil de producto (especificación 6.6): lo que el marcado Product dice a Google
 * coincide con lo que ve la persona.
 *
 * - SEO-14.producto-precio-coherente: el precio del marcado aparece en el texto de la página.
 * - SEO-14.producto-disponibilidad: la disponibilidad marcada no contradice lo que dice la
 *   página. Un producto agotado no es un error por sí solo: solo lo es la contradicción.
 *
 * Sin navegador: se mira el texto del HTML. Si el precio o el aviso de existencias se pintan
 * con JavaScript, no se ven, y el resultado es no verificable.
 */
final class ProductoCoherente implements Comprobacion
{
    /** Diferencia máxima entre precios para darlos por iguales (redondeos). */
    private const TOLERANCIA = 0.005;

    /** Disponibilidades de schema.org que significan que no se puede comprar ahora. */
    private const NO_DISPONIBLE = ['OutOfStock', 'SoldOut', 'Discontinued'];

    /** Disponibilidades que significan que sí se puede comprar o reservar. */
    private const DISPONIBLE = ['InStock', 'LimitedAvailability', 'OnlineOnly', 'InStoreOnly', 'PreOrder', 'PreSale', 'BackOrder', 'MadeToOrder'];

    /** Textos de la página que dicen que no hay existencias. */
    private const TEXTO_AGOTADO = '/\bagotad[oa]s?\b|\bsin (?:existencias|stock)\b|\bfuera de stock\b|\bno disponible\b|\bout of stock\b|\bsold out\b|\besgotad[oa]\b|\bexhaurit\b/iu';

    /** Textos que dicen que sí las hay (después de quitar los de agotado). */
    private const TEXTO_DISPONIBLE = '/\bhay existencias\b|\ben stock\b|\bin stock\b|\bdisponible\b|\bdisponibilidad inmediata\b|\bañadir al carrito\b|\badd to cart\b|\bcomprar ahora\b/iu';

    public function subcomprobaciones(): array
    {
        return [
            'SEO-14.producto-precio-coherente' => Cobertura::ParcialmenteImplementada,
            'SEO-14.producto-disponibilidad' => Cobertura::ParcialmenteImplementada,
        ];
    }

    public function evaluar(string $id, Entorno $entorno): BorradorResultado
    {
        return match ($id) {
            'SEO-14.producto-precio-coherente' => $this->precio($entorno),
            'SEO-14.producto-disponibilidad' => $this->disponibilidad($entorno),
            default => throw new RuntimeException("Subcomprobación desconocida: $id"),
        };
    }

    /**
     * Precio y disponibilidad del primer producto marcado (JSON-LD o Microdata), o null si
     * la página no tiene marcado Product.
     *
     * @return array{origen: string, precio: ?string, moneda: ?string, disponibilidad: ?string}|null
     */
    public static function marcado(Entorno $e): ?array
    {
        $doc = $e->documento();
        foreach (Estructurados::nodosJsonLd($doc) as $nodo) {
            if (!Estructurados::esDeTipo($nodo, 'Product', 'ProductGroup')) {
                continue;
            }
            $oferta = Estructurados::objetos($nodo, 'offers')[0] ?? null;
            return [
                'origen' => 'JSON-LD',
                'precio' => $oferta === null ? null : (Estructurados::texto($oferta, 'price') ?? Estructurados::texto($oferta, 'lowPrice')),
                'moneda' => $oferta === null ? null : Estructurados::texto($oferta, 'priceCurrency'),
                'disponibilidad' => $oferta === null ? null : Estructurados::texto($oferta, 'availability'),
            ];
        }
        foreach (Estructurados::microdatos($doc) as $item) {
            if (!in_array('Product', $item['tipo'], true)) {
                continue;
            }
            $oferta = isset($item['props']['offers'][0]) && is_array($item['props']['offers'][0]) ? $item['props']['offers'][0]['props'] : [];
            $leer = static function (array $o, string $k): ?string {
                $valor = $o[$k][0] ?? null;
                return is_string($valor) && trim($valor) !== '' ? trim($valor) : null;
            };
            return [
                'origen' => 'Microdata',
                'precio' => $leer($oferta, 'price') ?? $leer($oferta, 'lowPrice'),
                'moneda' => $leer($oferta, 'priceCurrency'),
                'disponibilidad' => $leer($oferta, 'availability'),
            ];
        }
        return null;
    }

    /**
     * Número de un precio escrito de cualquiera de las formas habituales: «42.50», «42,50»,
     * «1.299,00», «1,299.00» o «1299». null si no es un número.
     */
    public static function numero(string $texto): ?float
    {
        $t = (string) preg_replace('/[^\d.,]/u', '', $texto);
        if ($t === '' || preg_match('/\d/', $t) !== 1) {
            return null;
        }
        $coma = strrpos($t, ',');
        $punto = strrpos($t, '.');
        // El último separador seguido de 1 o 2 cifras es el decimal; los demás son de miles.
        $ultimo = max($coma === false ? -1 : $coma, $punto === false ? -1 : $punto);
        if ($ultimo >= 0 && strlen($t) - $ultimo - 1 <= 2) {
            $entero = (string) preg_replace('/[.,]/', '', substr($t, 0, $ultimo));
            $t = $entero . '.' . substr($t, $ultimo + 1);
        } else {
            $t = (string) preg_replace('/[.,]/', '', $t);
        }
        return is_numeric($t) ? (float) $t : null;
    }

    /**
     * Precios que se ven en el texto: cifras pegadas a un símbolo o código de moneda.
     *
     * @return list<array{texto: string, valor: float}>
     */
    public static function preciosVisibles(string $texto): array
    {
        $cifra = '\d{1,3}(?:[.,\s]\d{3})*(?:[.,]\d{1,2})?|\d+(?:[.,]\d{1,2})?';
        $moneda = '€|EUR|euros?|\$|USD|£|GBP';
        preg_match_all('/(?:(?:' . $moneda . ')\s?(' . $cifra . '))|(?:(' . $cifra . ')\s?(?:' . $moneda . '))/iu', str_replace("\u{00A0}", ' ', $texto), $m, PREG_SET_ORDER);
        $precios = [];
        foreach ($m as $coincidencia) {
            $cifraTexto = ($coincidencia[1] ?? '') !== '' ? $coincidencia[1] : ($coincidencia[2] ?? '');
            $valor = self::numero(str_replace(' ', '', $cifraTexto));
            if ($valor !== null) {
                $precios[] = ['texto' => trim($coincidencia[0]), 'valor' => $valor];
            }
        }
        return $precios;
    }

    private function precio(Entorno $e): BorradorResultado
    {
        $v = $e->resultado('SEO-14.producto-precio-coherente')
            ->limitacion('Sin navegador: se busca el precio en el texto del HTML; si se pinta con JavaScript no se ve.');
        $marcado = self::marcado($e);
        if ($marcado === null) {
            return $v->noAplicable('No es una página de producto (no hay marcado Product).');
        }
        $precio = $marcado['precio'] === null ? null : self::numero($marcado['precio']);
        if ($precio === null) {
            return $v->noAplicable('El marcado no tiene un precio que comparar (lo revisa SEO-14.producto-marcado).');
        }

        $marcadoTexto = $marcado['precio'] . ' ' . ($marcado['moneda'] ?? '');
        $visibles = self::preciosVisibles($e->documento()->texto);
        if ($visibles === []) {
            return $v->noVerificable('No se ve ningún precio en el texto del HTML: puede que se muestre con JavaScript.');
        }
        foreach ($visibles as $p) {
            if (abs($p['valor'] - $precio) < self::TOLERANCIA) {
                return $v->valor('Marcado (' . $marcado['origen'] . '): ' . trim($marcadoTexto) . '; en la página: ' . $p['texto'] . '.')->correcto();
            }
        }

        $lista = implode(', ', array_slice(array_unique(array_map(static fn (array $p): string => $p['texto'], $visibles)), 0, 5));
        return $v->valor('Marcado (' . $marcado['origen'] . '): ' . trim($marcadoTexto) . '; en la página: ' . $lista . '.')
            ->explicacion('El precio que la página le da a Google no aparece en la página.')
            ->evidencia($e->evidencia(TipoEvidencia::Texto, 'Precio en el marcado: ' . trim($marcadoTexto) . "\nPrecios en el texto: " . $lista))
            ->consecuencia('Google puede mostrar en los resultados un precio que no es el de la tienda, o retirar el producto de Google Shopping por no coincidir.')
            ->correccion('Hacer que el precio del marcado (offers.price) sea el mismo que se muestra, con el IVA incluido si así se muestra.')
            ->correccionCms([
                'woocommerce' => 'Suele pasar con plugins de precios o de IVA: revisar que el marcado use el precio final que ve el cliente.',
                'prestashop' => 'Revisar en product.tpl que itemprop="price" lleve el precio con impuestos que se muestra.',
            ])
            ->problema(Gravedad::Medio);
    }

    private function disponibilidad(Entorno $e): BorradorResultado
    {
        $v = $e->resultado('SEO-14.producto-disponibilidad')
            ->limitacion('Sin navegador: se buscan avisos de existencias en el texto del HTML; si dependen de la variante elegida o de JavaScript, no se ven.');
        $marcado = self::marcado($e);
        if ($marcado === null) {
            return $v->noAplicable('No es una página de producto (no hay marcado Product).');
        }
        if ($marcado['disponibilidad'] === null) {
            return $v->noAplicable('El marcado no indica la disponibilidad (offers.availability).');
        }
        $marca = (string) preg_replace('~^https?://schema\.org/~i', '', trim($marcado['disponibilidad']));
        $marcaAgotado = in_array($marca, self::NO_DISPONIBLE, true);
        if (!$marcaAgotado && !in_array($marca, self::DISPONIBLE, true)) {
            return $v->noVerificable('La disponibilidad «' . $marcado['disponibilidad'] . '» no es un valor de schema.org que se pueda comparar.');
        }

        $texto = $e->documento()->texto;
        $agotado = preg_match(self::TEXTO_AGOTADO, $texto, $ma) === 1;
        $resto = (string) preg_replace(self::TEXTO_AGOTADO, ' ', $texto);
        $disponible = preg_match(self::TEXTO_DISPONIBLE, $resto, $md) === 1;
        $v->valor('Marcado: ' . $marca . '; en la página: ' . match (true) {
            $agotado && $disponible => '«' . $ma[0] . '» y «' . $md[0] . '»',
            $agotado => '«' . $ma[0] . '»',
            $disponible => '«' . $md[0] . '»',
            default => 'sin avisos de existencias',
        } . '.');

        if ($agotado === $disponible) {
            return $v->noVerificable($agotado
                ? 'La página dice a la vez que hay y que no hay existencias (puede depender de la variante).'
                : 'La página no dice en el HTML si hay existencias.');
        }
        if ($marcaAgotado === $agotado) {
            return $v->correcto();
        }

        $dice = $agotado ? 'agotado' : 'disponible';
        $marcaTexto = $marcaAgotado ? 'agotado' : 'disponible';
        return $v
            ->explicacion("A Google se le dice que el producto está $marcaTexto, pero la página dice que está $dice.")
            ->evidencia($e->evidencia(TipoEvidencia::Texto, 'Marcado: ' . $marcado['disponibilidad'] . "\nEn la página: " . ($agotado ? $ma[0] : $md[0])))
            ->consecuencia($marcaAgotado
                ? 'Google muestra el producto como agotado y deja de enseñarlo a quien quiere comprarlo.'
                : 'Google muestra como disponible un producto que no se puede comprar: el cliente llega y se va.')
            ->correccion('Hacer que offers.availability diga lo mismo que la página: InStock si se puede comprar, OutOfStock si está agotado.')
            ->correccionCms([
                'woocommerce' => 'La disponibilidad del marcado sale del «Estado del inventario» del producto: revisar que coincida con las existencias reales.',
                'prestashop' => 'Revisar en product.tpl el itemprop="availability" y que el stock del producto esté actualizado.',
            ])
            ->problema(Gravedad::Medio);
    }
}
