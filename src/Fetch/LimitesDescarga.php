<?php

declare(strict_types=1);

namespace ProconSEO\Fetch;

/**
 * Límites de red y descarga del perfil de ejecución (config/perfiles/v1.json, sección 8 de la
 * especificación). No se amplían nunca en silencio para completar un análisis (SEG-05).
 */
final class LimitesDescarga
{
    /**
     * @param list<int>   $puertosPermitidos
     * @param list<float> $esperaReintentos Segundos de espera antes de cada reintento
     */
    public function __construct(
        public readonly int $maxRedirecciones = 10,
        public readonly int $maxBytesHtml = 5_242_880,
        public readonly int $maxBytesRobots = 512_000,
        public readonly int $maxBytesSitemap = 10_485_760,
        public readonly int $maxBytesAnalisis = 209_715_200,
        public readonly int $segundosConexion = 5,
        public readonly int $segundosPeticion = 15,
        public readonly int $reintentos = 2,
        public readonly array $esperaReintentos = [2.0, 4.0],
        public readonly float $peticionesPorSegundoHost = 1.0,
        public readonly array $puertosPermitidos = [80, 443],
        public readonly string $userAgent = 'proconSEO/1.0 (+herramienta interna de Proconsi)',
        public readonly string $idioma = 'es-ES',
        /** Si el servidor pide esperar (429 Retry-After) más que esto, se cierra como limitado. */
        public readonly int $maxEsperaRetryAfter = 30,
    ) {
    }

    /**
     * @param array<string, mixed> $perfil Contenido de config/perfiles/vN.json
     */
    public static function desdePerfil(array $perfil): self
    {
        $v = static fn (string $grupo, string $clave, mixed $porDefecto): mixed => $perfil[$grupo][$clave]['valor'] ?? $porDefecto;

        return new self(
            maxRedirecciones: (int) $v('rastreo', 'redirecciones', 10),
            maxBytesHtml: (int) $v('descarga', 'html_max_bytes', 5_242_880),
            maxBytesRobots: (int) $v('descarga', 'robots_max_bytes', 512_000),
            maxBytesSitemap: (int) $v('descarga', 'sitemap_max_bytes', 10_485_760),
            maxBytesAnalisis: (int) $v('descarga', 'total_analisis_bytes', 209_715_200),
            segundosConexion: (int) $v('tiempo', 'conexion', 5),
            segundosPeticion: (int) $v('tiempo', 'peticion', 15),
            reintentos: (int) $v('tiempo', 'reintentos', 2),
            esperaReintentos: array_map('floatval', (array) $v('tiempo', 'espera_reintentos', [2, 4])),
            peticionesPorSegundoHost: (float) $v('trafico', 'peticiones_por_segundo_host', 1),
            puertosPermitidos: array_map('intval', (array) $v('trafico', 'puertos_permitidos', [80, 443])),
            userAgent: (string) $v('observacion', 'user_agent', 'proconSEO/1.0 (+herramienta interna de Proconsi)'),
            idioma: (string) $v('observacion', 'idioma', 'es-ES'),
        );
    }
}
