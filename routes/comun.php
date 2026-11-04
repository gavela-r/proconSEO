<?php

declare(strict_types=1);

use ProconSEO\Shared\BaseDatos;
use ProconSEO\Shared\Enum;
use ProconSEO\Shared\Http\Peticion;
use ProconSEO\Shared\Http\Respuesta;
use ProconSEO\Shared\Http\Router;
use ProconSEO\Shared\Vista;

// Rutas comunes (Parte 0). Cambios con aprobación de los tres.
return static function (Router $r): void {
    $r->get('/', static function (Peticion $p): Respuesta {
        try {
            BaseDatos::conexion()->query('SELECT 1');
            $ok = true;
        } catch (Throwable) {
            $ok = false;
        }

        return Respuesta::html(Vista::render('layout/inicio', ['titulo' => 'Inicio', 'baseDatosOk' => $ok]));
    });

    // Comprobación rápida de que la aplicación y la base de datos funcionan.
    $r->get('/api/salud', static function (Peticion $p): Respuesta {
        try {
            BaseDatos::conexion()->query('SELECT 1');

            return Respuesta::json(['estado' => 'ok']);
        } catch (Throwable) {
            return Respuesta::json(['estado' => 'sin_base_datos'], 503);
        }
    });

    // Valores y etiquetas de los enums, para que el JavaScript use los mismos que PHP.
    $r->get('/api/meta/enums', static fn (Peticion $p): Respuesta => Respuesta::json([
        'estado_resultado' => Enum\EstadoResultado::opciones(),
        'ejecucion' => Enum\Ejecucion::opciones(),
        'cobertura' => Enum\Cobertura::opciones(),
        'tipo_conclusion' => Enum\TipoConclusion::opciones(),
        'gravedad' => Enum\Gravedad::opciones(),
        'esfuerzo' => Enum\Esfuerzo::opciones(),
        'bloque' => Enum\Bloque::opciones(),
        'alcance' => Enum\Alcance::opciones(),
        'estado_analisis' => Enum\EstadoAnalisis::opciones(),
        'fase' => Enum\Fase::opciones(),
        'tipo_proyecto' => Enum\TipoProyecto::opciones(),
        'sector' => Enum\Sector::opciones(),
        'estado_proyecto' => Enum\EstadoProyecto::opciones(),
    ]));
};
