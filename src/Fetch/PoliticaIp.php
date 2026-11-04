<?php

declare(strict_types=1);

namespace ProconSEO\Fetch;

/**
 * SEG-02 — Qué direcciones IP no se pueden visitar nunca: la propia máquina, redes privadas,
 * link-local (incluidos los metadatos cloud), rangos reservados y de traducción IPv4/IPv6 que
 * podrían esconder una IP privada.
 *
 * Solo se permiten direcciones públicas normales. Ante la duda, se bloquea.
 */
final class PoliticaIp
{
    /** @var array<string, string> rango CIDR => motivo */
    private const RANGOS_BLOQUEADOS = [
        // IPv4
        '0.0.0.0/8' => 'red "esta red"',
        '10.0.0.0/8' => 'red privada',
        '100.64.0.0/10' => 'red compartida (CGNAT)',
        '127.0.0.0/8' => 'la propia máquina (loopback)',
        '169.254.0.0/16' => 'link-local y metadatos cloud',
        '172.16.0.0/12' => 'red privada',
        '192.0.0.0/24' => 'reservada (IETF)',
        '192.0.2.0/24' => 'reservada para documentación',
        '192.88.99.0/24' => 'reservada (6to4)',
        '192.168.0.0/16' => 'red privada',
        '198.18.0.0/15' => 'reservada para pruebas de red',
        '198.51.100.0/24' => 'reservada para documentación',
        '203.0.113.0/24' => 'reservada para documentación',
        '224.0.0.0/4' => 'multicast',
        '240.0.0.0/4' => 'reservada y broadcast',
        // IPv6
        '::/128' => 'dirección sin especificar',
        '::1/128' => 'la propia máquina (loopback)',
        '::ffff:0:0/96' => 'IPv4 mapeada en IPv6',
        '::/96' => 'IPv4 compatible (obsoleta)',
        '64:ff9b::/96' => 'traducción NAT64',
        '64:ff9b:1::/48' => 'traducción NAT64 local',
        '100::/64' => 'descarte',
        '2001::/23' => 'reservada (IETF, Teredo…)',
        '2001:db8::/32' => 'reservada para documentación',
        '2002::/16' => 'túnel 6to4',
        'fc00::/7' => 'red privada IPv6 (ULA) y metadatos cloud',
        'fe80::/10' => 'link-local',
        'fec0::/10' => 'site-local (obsoleta)',
        'ff00::/8' => 'multicast',
    ];

    /**
     * Devuelve el motivo del bloqueo, o null si la IP es pública y se puede visitar.
     */
    public function motivoBloqueo(string $ip): ?string
    {
        $binaria = @inet_pton($ip);
        if ($binaria === false) {
            return 'dirección IP no válida';
        }

        foreach (self::RANGOS_BLOQUEADOS as $rango => $motivo) {
            if ($this->estaEnRango($binaria, $rango)) {
                return $motivo;
            }
        }

        return null;
    }

    public function permite(string $ip): bool
    {
        return $this->motivoBloqueo($ip) === null;
    }

    private function estaEnRango(string $ipBinaria, string $cidr): bool
    {
        [$red, $bits] = explode('/', $cidr);
        $redBinaria = (string) inet_pton($red);

        // IPv4 e IPv6 solo se comparan con rangos de su misma familia.
        if (strlen($redBinaria) !== strlen($ipBinaria)) {
            return false;
        }

        $bits = (int) $bits;
        $bytesCompletos = intdiv($bits, 8);
        if (strncmp($ipBinaria, $redBinaria, $bytesCompletos) !== 0) {
            return false;
        }

        $resto = $bits % 8;
        if ($resto === 0) {
            return true;
        }
        $mascara = (0xff << (8 - $resto)) & 0xff;

        return (ord($ipBinaria[$bytesCompletos]) & $mascara) === (ord($redBinaria[$bytesCompletos]) & $mascara);
    }
}
