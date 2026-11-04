<?php

declare(strict_types=1);

namespace ProconSEO\Fetch;

/**
 * Resolvedor real: pide al sistema los registros A (IPv4) y AAAA (IPv6).
 */
final class ResolvedorDnsSistema implements ResolvedorDns
{
    public function resolver(string $host): array
    {
        $ips = [];

        $registros = @dns_get_record($host, DNS_A | DNS_AAAA);
        foreach ($registros ?: [] as $registro) {
            if (isset($registro['ip'])) {
                $ips[] = $registro['ip'];
            }
            if (isset($registro['ipv6'])) {
                $ips[] = $registro['ipv6'];
            }
        }

        // Respaldo por si dns_get_record no está disponible o falla en este sistema.
        if ($ips === []) {
            $ips = @gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($ips));
    }
}
