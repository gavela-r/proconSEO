<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Http;

use ProconSEO\Shared\Error\ErrorDominio;

/**
 * Router mínimo. Las rutas admiten parámetros numéricos entre llaves: /proyectos/{id}.
 */
final class Router
{
    /** @var array<int, array{metodo: string, patron: string, nombres: list<string>, accion: callable}> */
    private array $rutas = [];

    public function get(string $ruta, callable $accion): void
    {
        $this->anadir('GET', $ruta, $accion);
    }

    public function post(string $ruta, callable $accion): void
    {
        $this->anadir('POST', $ruta, $accion);
    }

    public function put(string $ruta, callable $accion): void
    {
        $this->anadir('PUT', $ruta, $accion);
    }

    public function delete(string $ruta, callable $accion): void
    {
        $this->anadir('DELETE', $ruta, $accion);
    }

    /**
     * @param callable(Peticion): Respuesta $accion
     */
    public function anadir(string $metodo, string $ruta, callable $accion): void
    {
        $nombres = [];
        $patron = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            static function (array $m) use (&$nombres): string {
                $nombres[] = $m[1];

                return '(\d+)';
            },
            rtrim($ruta, '/') ?: '/',
        );

        $this->rutas[] = [
            'metodo' => strtoupper($metodo),
            'patron' => '#^' . $patron . '$#',
            'nombres' => $nombres,
            'accion' => $accion,
        ];
    }

    public function despachar(Peticion $peticion): Respuesta
    {
        $rutaExiste = false;

        foreach ($this->rutas as $ruta) {
            if (!preg_match($ruta['patron'], $peticion->ruta, $coincidencias)) {
                continue;
            }
            $rutaExiste = true;
            if ($ruta['metodo'] !== $peticion->metodo) {
                continue;
            }

            $parametros = array_combine($ruta['nombres'], array_slice($coincidencias, 1)) ?: [];

            return ($ruta['accion'])($peticion->conParametros($parametros));
        }

        if ($rutaExiste) {
            throw new ErrorDominio('metodo_no_permitido', 'Método no permitido para esta ruta.', 405);
        }

        throw new ErrorDominio('no_encontrado', 'La página solicitada no existe.', 404);
    }
}
