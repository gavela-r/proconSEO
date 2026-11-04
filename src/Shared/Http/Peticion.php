<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Http;

final class Peticion
{
    /**
     * @param array<string, mixed>  $consulta  Parámetros de la URL (?a=b)
     * @param array<string, mixed>  $cuerpo    Datos del formulario o del JSON recibido
     * @param array<string, string> $cabeceras Cabeceras en minúsculas
     * @param array<string, string> $parametros Parámetros de la ruta ({id})
     */
    public function __construct(
        public readonly string $metodo,
        public readonly string $ruta,
        public readonly array $consulta = [],
        public readonly array $cuerpo = [],
        public readonly array $cabeceras = [],
        public readonly array $parametros = [],
    ) {
    }

    public static function desdeGlobales(): self
    {
        $metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $ruta = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        $cabeceras = [];
        foreach ($_SERVER as $clave => $valor) {
            if (str_starts_with($clave, 'HTTP_')) {
                $cabeceras[strtolower(str_replace('_', '-', substr($clave, 5)))] = (string) $valor;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $cabeceras['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $cuerpo = $_POST;
        if (str_contains($cabeceras['content-type'] ?? '', 'application/json')) {
            $json = json_decode((string) file_get_contents('php://input'), true, 32);
            $cuerpo = is_array($json) ? $json : [];
        }

        // Permite PUT y DELETE desde formularios HTML con un campo oculto _metodo.
        if ($metodo === 'POST' && isset($cuerpo['_metodo'])) {
            $alternativo = strtoupper((string) $cuerpo['_metodo']);
            if (in_array($alternativo, ['PUT', 'PATCH', 'DELETE'], true)) {
                $metodo = $alternativo;
            }
        }

        return new self($metodo, rtrim($ruta, '/') ?: '/', $_GET, $cuerpo, $cabeceras);
    }

    public function conParametros(array $parametros): self
    {
        return new self($this->metodo, $this->ruta, $this->consulta, $this->cuerpo, $this->cabeceras, $parametros);
    }

    public function parametro(string $nombre): ?string
    {
        return $this->parametros[$nombre] ?? null;
    }

    public function entrada(string $nombre, mixed $porDefecto = null): mixed
    {
        return $this->cuerpo[$nombre] ?? $this->consulta[$nombre] ?? $porDefecto;
    }

    public function esperaJson(): bool
    {
        return str_starts_with($this->ruta, '/api/')
            || str_contains($this->cabeceras['accept'] ?? '', 'application/json');
    }
}
