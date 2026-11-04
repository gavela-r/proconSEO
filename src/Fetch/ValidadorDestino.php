<?php

declare(strict_types=1);

namespace ProconSEO\Fetch;

/**
 * SEG-01 + SEG-02 — Decide si se puede conectar a una URL, antes de abrir ninguna conexión.
 *
 * 1. Valida y normaliza la URL (ValidadorUrl).
 * 2. Resuelve el dominio a TODAS sus IPs (IPv4 e IPv6).
 * 3. Si alguna IP está prohibida, rechaza el destino entero: un dominio que mezcla IPs
 *    públicas y privadas es sospechoso (y cURL podría acabar usando la privada).
 *
 * Hay que llamarlo en cada petición, en cada salto de redirección y en cada reintento.
 */
final class ValidadorDestino
{
    public function __construct(
        private readonly ValidadorUrl $validadorUrl,
        private readonly ResolvedorDns $resolvedor,
        private readonly PoliticaIp $politica = new PoliticaIp(),
    ) {
    }

    public function validar(string $url): DestinoValidado
    {
        $destino = $this->validadorUrl->validar($url);

        $ips = $destino->hostEsIp ? [$destino->host] : $this->resolvedor->resolver($destino->host);
        if ($ips === []) {
            throw DestinoRechazado::red('dns_sin_resultado', "El dominio {$destino->host} no existe o no se ha podido resolver.");
        }

        foreach ($ips as $ip) {
            $motivo = $this->politica->motivoBloqueo($ip);
            if ($motivo !== null) {
                throw DestinoRechazado::red(
                    'destino_bloqueado',
                    "No se puede analizar {$destino->host}: apunta a una dirección no permitida ({$motivo}).",
                );
            }
        }

        return new DestinoValidado($destino, $ips, $ips[0]);
    }
}
