<?php

declare(strict_types=1);

namespace ProconSEO\Fetch;

/**
 * Destino que ha superado SEG-01 y SEG-02: la URL es válida y TODAS sus IPs son públicas.
 *
 * Para evitar el DNS rebinding (que el dominio cambie de IP entre la comprobación y la
 * conexión), la conexión debe hacerse a $ipConexion, la misma IP que se ha validado, usando
 * opcionCurlResolve() con CURLOPT_RESOLVE.
 */
final class DestinoValidado
{
    /**
     * @param list<string> $ips Todas las IPs validadas
     */
    public function __construct(
        public readonly DestinoUrl $url,
        public readonly array $ips,
        public readonly string $ipConexion,
    ) {
    }

    /**
     * Valor para CURLOPT_RESOLVE: obliga a cURL a conectar a la IP validada.
     *
     * @return list<string>
     */
    public function opcionCurlResolve(): array
    {
        $ip = str_contains($this->ipConexion, ':') ? '[' . $this->ipConexion . ']' : $this->ipConexion;

        return [$this->url->host . ':' . $this->url->puerto . ':' . $ip];
    }
}
