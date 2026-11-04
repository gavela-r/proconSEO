<?php

declare(strict_types=1);

namespace ProconSEO\Fetch;

/**
 * Traduce un dominio a sus direcciones IP. Es una interfaz para poder simular respuestas
 * DNS en los tests (por ejemplo, un dominio público que apunta a 127.0.0.1).
 */
interface ResolvedorDns
{
    /**
     * @return list<string> Todas las IPv4 e IPv6 del dominio; vacío si no resuelve
     */
    public function resolver(string $host): array;
}
