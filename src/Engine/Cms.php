<?php

declare(strict_types=1);

namespace ProconSEO\Engine;

use ProconSEO\Engine\Extraccion\DocumentoHtml;

/**
 * Detección de WordPress, WooCommerce y PrestaShop por señales públicas del HTML
 * (SEO-01.cms-detectado). Es informativa: solo sirve para dar instrucciones concretas.
 */
final class Cms
{
    public const WORDPRESS = 'wordpress';
    public const WOOCOMMERCE = 'woocommerce';
    public const PRESTASHOP = 'prestashop';

    private const NOMBRES = [
        self::WORDPRESS => 'WordPress',
        self::WOOCOMMERCE => 'WooCommerce',
        self::PRESTASHOP => 'PrestaShop',
    ];

    /**
     * @return array<string, list<string>> cms => señales encontradas (vacío si ninguno).
     *         WooCommerce implica WordPress.
     */
    public static function detectar(DocumentoHtml $doc): array
    {
        $senales = [];
        foreach ($doc->generadores() as $g) {
            foreach (self::NOMBRES as $clave => $nombre) {
                if (stripos($g, $nombre) !== false) {
                    $senales[$clave][] = 'meta generator «' . mb_substr($g, 0, 80) . '»';
                }
            }
        }

        $urls = array_column($doc->recursos, 'url');
        foreach ($urls as $url) {
            $ruta = (string) parse_url($url, PHP_URL_PATH);
            if (str_contains($ruta, '/wp-content/') || str_contains($ruta, '/wp-includes/')) {
                $senales[self::WORDPRESS][] = 'recursos en /wp-content/ o /wp-includes/';
            }
            if (str_contains($ruta, '/plugins/woocommerce/')) {
                $senales[self::WOOCOMMERCE][] = 'recursos del plugin WooCommerce';
            }
            if (preg_match('~/themes/[^/]+/assets/~', $ruta) === 1 && !str_contains($ruta, '/wp-content/')) {
                $senales[self::PRESTASHOP][] = 'tema en /themes/…/assets/';
            }
        }

        $body = $doc->dom->getElementsByTagName('body')->item(0);
        $clases = $body instanceof \DOMElement ? ' ' . $body->getAttribute('class') . ' ' : '';
        if (str_contains($clases, ' woocommerce ') || str_contains($clases, ' woocommerce-page ')) {
            $senales[self::WOOCOMMERCE][] = 'clases woocommerce en <body>';
        }

        foreach ($doc->dom->getElementsByTagName('script') as $script) {
            if (preg_match('/\bvar\s+prestashop\s*=/', $script->textContent) === 1) {
                $senales[self::PRESTASHOP][] = 'objeto JavaScript «prestashop»';
                break;
            }
        }
        foreach ($doc->dom->getElementsByTagName('link') as $link) {
            if (str_contains(strtolower($link->getAttribute('rel')), 'https://api.w.org/')) {
                $senales[self::WORDPRESS][] = 'API REST de WordPress (api.w.org)';
                break;
            }
        }

        if (isset($senales[self::WOOCOMMERCE])) {
            $senales[self::WORDPRESS] ??= ['WooCommerce funciona sobre WordPress'];
        }

        return array_map(static fn (array $s): array => array_values(array_unique($s)), $senales);
    }

    public static function nombre(string $cms): string
    {
        return self::NOMBRES[$cms] ?? $cms;
    }
}
