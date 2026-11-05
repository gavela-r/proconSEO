<?php

declare(strict_types=1);

namespace ProconSEO\Run;

use ProconSEO\Shared\Contract\ProveedorRecursos;
use ProconSEO\Shared\Contract\RecursoDescargado;
use ProconSEO\Shared\Enum\ResultadoDescarga;

/**
 * Lo que recibe el motor en lugar del cliente HTTP:
 * - recuerda lo ya descargado, para no pedir robots.txt o el sitemap una vez por página;
 * - pone un tope de URLs nuevas por página analizada (con contexto: 20 además de la página y
 *   robots.txt). Pasado el tope no se descarga nada: se devuelve «fuera de ámbito» con el
 *   motivo, y la comprobación que lo pidió queda no_verificable.
 *
 * En el sitio completo, al pasar a otra página se olvidan las páginas HTML de la anterior
 * (no cabría todo el sitio en memoria); robots.txt, sitemaps y demás se conservan.
 */
final class ProveedorAcotado implements ProveedorRecursos
{
    /** @var array<string, RecursoDescargado> Se conservan todo el análisis */
    private array $permanentes = [];
    /** @var array<string, RecursoDescargado> Páginas HTML de la página que se está analizando */
    private array $temporales = [];
    private int $nuevas = 0;
    private int $totalNuevas = 0;

    public function __construct(
        private readonly ProveedorRecursos $proveedor,
        private readonly ?int $maxNuevasPorPagina = null,
        private readonly string $alcance = '',
    ) {
    }

    public function obtener(string $url, string $motivo, ?string $idComprobacion = null): RecursoDescargado
    {
        $guardado = $this->permanentes[$url] ?? $this->temporales[$url] ?? null;
        if ($guardado !== null) {
            return $guardado;
        }
        if ($this->maxNuevasPorPagina !== null && $this->nuevas >= $this->maxNuevasPorPagina) {
            return new RecursoDescargado($url, $url, ResultadoDescarga::FueraDeAmbito,
                motivo: "Se ha llegado al máximo de {$this->maxNuevasPorPagina} URLs extra por página del alcance «{$this->alcance}».");
        }
        $this->nuevas++;
        $this->totalNuevas++;

        $recurso = $this->proveedor->obtener($url, $motivo, $idComprobacion);
        if (self::esHtml($recurso)) {
            $this->temporales[$url] = $recurso;
        } else {
            $this->permanentes[$url] = $recurso;
        }

        return $recurso;
    }

    /** Registra algo ya descargado (robots.txt, la página inicial) sin contarlo en el tope. */
    public function recordar(RecursoDescargado $recurso): void
    {
        $this->permanentes[$recurso->urlSolicitada] ??= $recurso;
    }

    /** Empieza otra página del sitio: tope a cero y fuera las páginas HTML de la anterior. */
    public function nuevaPagina(RecursoDescargado $pagina): void
    {
        $this->nuevas = 0;
        $this->temporales = [$pagina->urlSolicitada => $pagina];
    }

    /** URLs extra pedidas en todo el análisis. */
    public function urlsExtra(): int
    {
        return $this->totalNuevas;
    }

    private static function esHtml(RecursoDescargado $r): bool
    {
        return str_contains(strtolower((string) $r->tipoContenido), 'html');
    }
}
