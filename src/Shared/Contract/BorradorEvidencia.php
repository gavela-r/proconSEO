<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Contract;

use DateTimeImmutable;
use ProconSEO\Shared\Enum\TipoEvidencia;

/**
 * Evidencia que genera el motor. Debe ser mínima: el fragmento justo que demuestra el resultado.
 * La Parte 1 la sanitiza (SEG-09) antes de guardarla.
 */
final class BorradorEvidencia
{
    /** Tamaño máximo del contenido; lo que exceda se trunca y se marca. */
    public const MAXIMO_CARACTERES = 2000;

    public readonly string $contenido;
    public readonly bool $truncado;

    /**
     * @param string $codigo Identificador dentro del análisis, p. ej. "E-001"
     */
    public function __construct(
        public readonly string $codigo,
        public readonly TipoEvidencia $tipo,
        string $contenido,
        public readonly ?string $urlRecurso = null,
        public readonly ?DateTimeImmutable $capturadoEn = null,
    ) {
        $this->truncado = mb_strlen($contenido) > self::MAXIMO_CARACTERES;
        $this->contenido = $this->truncado ? mb_substr($contenido, 0, self::MAXIMO_CARACTERES) : $contenido;
    }
}
