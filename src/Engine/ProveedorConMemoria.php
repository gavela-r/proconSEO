<?php

declare(strict_types=1);

namespace ProconSEO\Engine;

use ProconSEO\Shared\Contract\ProveedorRecursos;
use ProconSEO\Shared\Contract\RecursoDescargado;

/**
 * Evita pedir dos veces la misma URL dentro de un análisis (varias comprobaciones usan
 * robots.txt, el canonical…). Así se respeta el ritmo por host y el presupuesto de descarga.
 */
final class ProveedorConMemoria implements ProveedorRecursos
{
    /** @var array<string, RecursoDescargado> */
    private array $memoria = [];

    public function __construct(private readonly ProveedorRecursos $proveedor)
    {
    }

    public function obtener(string $url, string $motivo, ?string $idComprobacion = null): RecursoDescargado
    {
        return $this->memoria[$url] ??= $this->proveedor->obtener($url, $motivo, $idComprobacion);
    }

    public function tiene(string $url): bool
    {
        return isset($this->memoria[$url]);
    }

    /** Registra un recurso ya descargado (la página principal) para no pedirlo otra vez. */
    public function recordar(RecursoDescargado $recurso): void
    {
        $this->memoria[$recurso->urlSolicitada] ??= $recurso;
    }
}
