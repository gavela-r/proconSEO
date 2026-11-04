<?php

declare(strict_types=1);

namespace ProconSEO\Shared;

use ProconSEO\Shared\Error\ErrorDominio;
use ProconSEO\Shared\Http\Csrf;
use ProconSEO\Shared\Http\Peticion;
use ProconSEO\Shared\Http\Respuesta;
use ProconSEO\Shared\Http\Router;
use Throwable;

/**
 * Punto de arranque: carga configuración, rutas de cada parte y atiende la petición.
 */
final class Aplicacion
{
    /** Ficheros de rutas: uno por parte para no pisarnos. */
    private const FICHEROS_RUTAS = [
        'comun.php',       // Parte 0
        'run.php',         // Parte 1
        'catalogo.php',    // Parte 2
        'proyectos.php',   // Parte 3
        'resultados.php',  // Parte 3
        'revision.php',    // Parte 3
        'exportar.php',    // Parte 3
    ];

    private function __construct(
        private readonly string $raiz,
        private readonly Router $router,
    ) {
    }

    public static function crear(string $raiz): self
    {
        Config::cargar($raiz);
        Vista::configurar($raiz . '/templates');

        $router = new Router();
        foreach (self::FICHEROS_RUTAS as $fichero) {
            $ruta = $raiz . '/routes/' . $fichero;
            if (is_file($ruta)) {
                (require $ruta)($router);
            }
        }

        return new self($raiz, $router);
    }

    public function atender(): void
    {
        $this->iniciarSesion();
        $peticion = Peticion::desdeGlobales();

        try {
            Csrf::verificar($peticion);
            $respuesta = $this->router->despachar($peticion);
        } catch (ErrorDominio $e) {
            $respuesta = Respuesta::error($e, $peticion->esperaJson());
        } catch (Throwable $e) {
            error_log((string) $e);
            $respuesta = Respuesta::error(
                new ErrorDominio('error_interno', 'Ha ocurrido un error inesperado.', 500),
                $peticion->esperaJson(),
            );
        }

        $respuesta->enviar();
    }

    public function router(): Router
    {
        return $this->router;
    }

    private function iniciarSesion(): void
    {
        if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
            session_start([
                'cookie_httponly' => true,
                'cookie_samesite' => 'Strict',
                'use_strict_mode' => true,
            ]);
        }
    }
}
