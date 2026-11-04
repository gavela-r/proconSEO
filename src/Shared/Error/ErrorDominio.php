<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Error;

use RuntimeException;

/**
 * Error esperado de la aplicación. El mensaje se muestra al usuario: nunca debe incluir
 * contenido de la web analizada sin sanitizar ni detalles internos.
 *
 * Formato JSON común: {"error": {"codigo": "...", "mensaje": "...", "detalles": {...}}}
 */
class ErrorDominio extends RuntimeException
{
    /**
     * @param array<string, mixed> $detalles
     */
    public function __construct(
        public readonly string $codigo,
        string $mensaje,
        public readonly int $estadoHttp = 400,
        public readonly array $detalles = [],
    ) {
        parent::__construct($mensaje);
    }

    /**
     * @return array{error: array{codigo: string, mensaje: string, detalles?: array<string, mixed>}}
     */
    public function comoArray(): array
    {
        $error = ['codigo' => $this->codigo, 'mensaje' => $this->getMessage()];
        if ($this->detalles !== []) {
            $error['detalles'] = $this->detalles;
        }

        return ['error' => $error];
    }
}
