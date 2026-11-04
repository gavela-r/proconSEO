<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Extraccion\Sitemap;

/**
 * Contenido de un sitemap: lista de URLs (urlset) o de otros sitemaps (sitemapindex).
 */
final class ContenidoSitemap
{
    public const URLSET = 'urlset';
    public const INDICE = 'sitemapindex';

    /**
     * @param list<array{loc: string, lastmod: ?string}> $entradas
     * @param list<string> $avisos Entradas descartadas y otros detalles que no impiden leerlo
     */
    public function __construct(
        public readonly string $tipo,
        public readonly array $entradas,
        public readonly array $avisos,
        public readonly bool $truncado,
    ) {
    }

    public function esIndice(): bool
    {
        return $this->tipo === self::INDICE;
    }

    /** @return list<string> */
    public function urls(): array
    {
        return array_column($this->entradas, 'loc');
    }

    public function contiene(string $url): bool
    {
        return in_array($url, $this->urls(), true);
    }
}
