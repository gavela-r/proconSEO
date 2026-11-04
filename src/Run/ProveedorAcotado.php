<?php

declare(strict_types=1);

namespace ProconSEO\Run;

use ProconSEO\Shared\Contract\ProveedorRecursos;
use ProconSEO\Shared\Contract\RecursoDescargado;
use ProconSEO\Shared\Enum\ResultadoDescarga;

/**
 * Lo que recibe el motor en lugar del cliente HTTP:
 * - recuerda lo ya descargado, para no pedir robots.txt o el sitemap una vez por página;
 * - pone un tope de URLs distintas por alcance (con contexto: 20 además de la página y
 *   robots.txt). Pasado el tope no se descarga nada: se devuelve «fuera de ámbito» con el
 *   motivo, y la comprobación que lo pidió queda no_verificable.
 */
final class ProveedorAcotado implements ProveedorRecursos
{
    /** @var array<string, RecursoDescargado> */
    private array $memoria = [];
    private int $nuevas = 0;

    public function __construct(
        private readonly ProveedorRecursos $proveedor,
        private readonly ?int $maxNuevas = null,
        private readonly string $alcance = '',
    ) {
    }

    public function obtener(string $url, string $motivo, ?string $idComprobacion = null): RecursoDescargado
    {
        if (isset($this->memoria[$url])) {
            return $this->memoria[$url];
        }
        if ($this->maxNuevas !== null && $this->nuevas >= $this->maxNuevas) {
            return new RecursoDescargado($url, $url, ResultadoDescarga::FueraDeAmbito,
                motivo: "Se ha llegado al máximo de {$this->maxNuevas} URLs extra del alcance «{$this->alcance}».");
        }
        $this->nuevas++;

        return $this->memoria[$url] = $this->proveedor->obtener($url, $motivo, $idComprobacion);
    }

    /** Registra algo ya descargado (la página o robots.txt) sin contarlo en el tope. */
    public function recordar(RecursoDescargado $recurso): void
    {
        $this->memoria[$recurso->urlSolicitada] ??= $recurso;
    }

    /** Libera una página ya analizada, para no tener todo el sitio en memoria. */
    public function olvidar(RecursoDescargado $recurso): void
    {
        unset($this->memoria[$recurso->urlSolicitada]);
    }

    public function urlsExtra(): int
    {
        return $this->nuevas;
    }
}
