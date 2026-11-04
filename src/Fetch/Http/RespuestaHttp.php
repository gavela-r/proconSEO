<?php

declare(strict_types=1);

namespace ProconSEO\Fetch\Http;

/**
 * Lo que devuelve el transporte para una petición. Si $error no es null, la petición no
 * llegó a completarse (red, TLS, tiempo…) y $estado puede ser 0.
 */
final class RespuestaHttp
{
    public const ERROR_RED = 'red';
    public const ERROR_TLS = 'tls';
    public const ERROR_TIEMPO = 'tiempo';

    /**
     * @param array<string, string> $cabeceras En minúsculas. Si una cabecera se repite, se unen con ", "
     */
    public function __construct(
        public readonly int $estado,
        public readonly array $cabeceras = [],
        public readonly string $cuerpo = '',
        public readonly bool $truncado = false,
        public readonly ?string $error = null,
        public readonly ?string $detalleError = null,
    ) {
    }

    public function cabecera(string $nombre): ?string
    {
        return $this->cabeceras[strtolower($nombre)] ?? null;
    }

    public function esRedireccion(): bool
    {
        return in_array($this->estado, [301, 302, 303, 307, 308], true) && $this->cabecera('location') !== null;
    }
}
