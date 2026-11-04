<?php

declare(strict_types=1);

namespace ProconSEO\Fetch\Http;

use ProconSEO\Fetch\DestinoValidado;

/**
 * Una única petición GET a un destino ya validado. El transporte nunca sigue redirecciones:
 * cada salto lo decide ClienteHttpSeguro tras volver a validar el destino.
 */
final class PeticionHttp
{
    public function __construct(
        public readonly DestinoValidado $destino,
        public readonly int $maxBytes,
        public readonly int $segundosConexion,
        public readonly int $segundosTotal,
        public readonly string $userAgent,
        public readonly string $idioma = 'es-ES',
    ) {
    }
}
