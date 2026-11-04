<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Contract;

use DateTimeImmutable;
use ProconSEO\Shared\Enum\ResultadoDescarga;

/**
 * Un recurso (página, robots.txt, sitemap…) ya descargado por la Parte 1, con las
 * comprobaciones de seguridad aplicadas. La Parte 2 lo recibe y no hace red por su cuenta.
 *
 * Si $resultado no es Correcto, $cuerpo puede estar vacío: las comprobaciones que dependan
 * de él deben devolver no_verificable, nunca un problema por datos ausentes.
 */
final class RecursoDescargado
{
    /**
     * @param list<array{url: string, estado: int, destino: ?string}> $redirecciones Cada salto seguido, en orden
     * @param array<string, string> $cabeceras Cabeceras de respuesta en minúsculas, ya sanitizadas (sin Set-Cookie)
     */
    public function __construct(
        public readonly string $urlSolicitada,
        public readonly string $urlFinal,
        public readonly ResultadoDescarga $resultado,
        public readonly ?int $estadoHttp = null,
        public readonly array $redirecciones = [],
        public readonly array $cabeceras = [],
        public readonly ?string $tipoContenido = null,
        public readonly string $cuerpo = '',
        public readonly bool $truncado = false,
        public readonly int $bytes = 0,
        public readonly bool $tlsVerificado = false,
        public readonly ?string $motivo = null,
        public readonly ?DateTimeImmutable $descargadoEn = null,
        public readonly ?int $idRecurso = null,
    ) {
    }

    public function esCorrecto(): bool
    {
        return $this->resultado === ResultadoDescarga::Correcto;
    }

    public function cabecera(string $nombre): ?string
    {
        return $this->cabeceras[strtolower($nombre)] ?? null;
    }
}
