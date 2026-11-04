<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Fetch;

use ProconSEO\Fetch\ResolvedorDns;

final class DnsFalso implements ResolvedorDns
{
    /** @param array<string, list<string>> $tabla */
    public function __construct(public array $tabla)
    {
    }

    public function resolver(string $host): array
    {
        return $this->tabla[$host] ?? [];
    }
}
