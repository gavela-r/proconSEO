<?php

declare(strict_types=1);

namespace ProconSEO\Run;

/**
 * IP de quien hace la petición (SEG-11 y SEG-15). Solo se hace caso a X-Forwarded-For si la
 * petición llega desde un proxy de confianza (PROXIES_DE_CONFIANZA en .env); si no, cualquiera
 * podría inventarse la IP con esa cabecera.
 */
final class IpOrigen
{
    /**
     * @param array<string, mixed> $servidor $_SERVER
     * @param list<string>         $proxiesConfianza
     */
    public static function desde(array $servidor, array $proxiesConfianza = []): ?string
    {
        $remota = self::valida((string) ($servidor['REMOTE_ADDR'] ?? ''));
        if ($remota === null || !in_array($remota, $proxiesConfianza, true)) {
            return $remota;
        }

        // Se recorre de derecha a izquierda: la primera IP que no es un proxy nuestro es la del cliente.
        $cadena = array_reverse(array_map('trim', explode(',', (string) ($servidor['HTTP_X_FORWARDED_FOR'] ?? ''))));
        foreach ($cadena as $ip) {
            $ip = self::valida($ip);
            if ($ip === null) {
                break;
            }
            if (!in_array($ip, $proxiesConfianza, true)) {
                return $ip;
            }
        }

        return $remota;
    }

    /** @param string $lista IPs separadas por comas */
    public static function proxies(string $lista): array
    {
        return array_values(array_filter(array_map(self::valida(...), array_map('trim', explode(',', $lista)))));
    }

    private static function valida(string $ip): ?string
    {
        $ip = filter_var($ip, FILTER_VALIDATE_IP);

        return $ip === false ? null : (string) inet_ntop((string) inet_pton($ip));
    }
}
