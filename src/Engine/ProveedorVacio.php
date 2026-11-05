<?php

declare(strict_types=1);

namespace ProconSEO\Engine;

use ProconSEO\Shared\Contract\ProveedorRecursos;
use ProconSEO\Shared\Contract\RecursoDescargado;

/**
 * Proveedor para la consolidación del sitio: ahí no se pide nada a la red, todo sale de los
 * resúmenes de las páginas. Si alguna comprobación lo intentara, queda no_verificable.
 */
final class ProveedorVacio implements ProveedorRecursos
{
    public function obtener(string $url, string $motivo, ?string $idComprobacion = null): RecursoDescargado
    {
        throw new NoVerificable('La consolidación del sitio no descarga nada.');
    }
}
