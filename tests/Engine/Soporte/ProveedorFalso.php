<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Engine\Soporte;

use ProconSEO\Shared\Contract\ProveedorRecursos;
use ProconSEO\Shared\Contract\RecursoDescargado;
use ProconSEO\Shared\Enum\ResultadoDescarga;

/**
 * Implementación falsa de ProveedorRecursos para los tests de la Parte 2: no hace red.
 * Devuelve las respuestas de ejemplo asignadas a cada URL y registra todas las peticiones
 * (URL, motivo y comprobación) para poder comprobar qué pidió el motor y por qué.
 *
 * Una URL sin respuesta asignada devuelve fuera_de_ambito, igual que haría la Parte 1 con
 * una URL que no se puede consultar.
 */
final class ProveedorFalso implements ProveedorRecursos
{
    /** @var list<array{url: string, motivo: string, idComprobacion: ?string}> */
    private array $peticiones = [];

    /**
     * @param array<string, string|RecursoDescargado> $respuestas URL => nombre de la respuesta de ejemplo o recurso ya construido
     */
    public function __construct(private readonly array $respuestas = [])
    {
    }

    public function obtener(string $url, string $motivo, ?string $idComprobacion = null): RecursoDescargado
    {
        $this->peticiones[] = ['url' => $url, 'motivo' => $motivo, 'idComprobacion' => $idComprobacion];

        $respuesta = $this->respuestas[$url] ?? null;
        if ($respuesta instanceof RecursoDescargado) {
            return $respuesta;
        }
        if (is_string($respuesta)) {
            return Fixtures::recurso($respuesta);
        }

        return new RecursoDescargado(
            urlSolicitada: $url,
            urlFinal: $url,
            resultado: ResultadoDescarga::FueraDeAmbito,
            motivo: 'URL sin respuesta de ejemplo en ProveedorFalso',
        );
    }

    /** @return list<array{url: string, motivo: string, idComprobacion: ?string}> */
    public function peticiones(): array
    {
        return $this->peticiones;
    }

    /** @return list<string> */
    public function urlsPedidas(): array
    {
        return array_column($this->peticiones, 'url');
    }
}
