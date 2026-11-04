<?php

declare(strict_types=1);

namespace ProconSEO\Run;

/**
 * Límites de los alcances «URL con contexto» y «sitio completo» (perfil, sección 8).
 */
final class LimitesRastreo
{
    /** Variantes de una misma ruta que cambian solo en los parámetros (?a=1, ?a=2…). */
    public const MAX_VARIANTES = 3;
    /** Sitemaps que se leen como mucho (el índice y los que enlaza). */
    public const MAX_SITEMAPS = 10;

    public function __construct(
        public readonly int $maxPaginas = 200,
        public readonly int $maxUrlsContexto = 20,
        public readonly int $maxProfundidad = 5,
        public readonly int $segundosUrl = 120,
        public readonly int $segundosSitio = 1800,
    ) {
    }

    /** @param array<string, mixed> $perfil */
    public static function desdePerfil(array $perfil): self
    {
        $v = static fn (string $grupo, string $clave, int $porDefecto): int => (int) ($perfil[$grupo][$clave]['valor'] ?? $porDefecto);

        return new self(
            maxPaginas: $v('rastreo', 'paginas_sitio_completo', 200),
            maxUrlsContexto: $v('rastreo', 'urls_contexto', 20),
            maxProfundidad: $v('rastreo', 'profundidad', 5),
            segundosUrl: $v('tiempo', 'analisis_url', 120),
            segundosSitio: $v('tiempo', 'analisis_sitio', 1800),
        );
    }
}
