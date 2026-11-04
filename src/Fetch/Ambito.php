<?php

declare(strict_types=1);

namespace ProconSEO\Fetch;

/**
 * Hosts autorizados de un análisis (Guía 0.1, política operativa por alcance).
 *
 * La autorización es exacta: autorizar "ejemplo.es" NO incluye "www.ejemplo.es" ni otros
 * subdominios. Estar en el ámbito nunca anula SEG-01/02: un host autorizado que apunta a
 * una IP interna se sigue bloqueando.
 */
final class Ambito
{
    /** @var list<string> */
    private readonly array $hosts;

    /**
     * @param list<string> $hosts Hosts ya normalizados (minúsculas, punycode)
     */
    public function __construct(array $hosts)
    {
        $this->hosts = array_values(array_unique(array_map('strtolower', $hosts)));
    }

    /** Ámbito mínimo: solo el host de la URL analizada. */
    public static function deUrl(DestinoUrl $url): self
    {
        return new self([$url->host]);
    }

    public function permite(DestinoUrl $url): bool
    {
        return in_array(strtolower($url->host), $this->hosts, true);
    }

    /** @return list<string> */
    public function hosts(): array
    {
        return $this->hosts;
    }
}
