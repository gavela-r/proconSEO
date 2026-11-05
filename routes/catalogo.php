<?php

declare(strict_types=1);

use ProconSEO\Engine\Catalogo\VistaCatalogo;
use ProconSEO\Shared\Enum\Bloque;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Http\Peticion;
use ProconSEO\Shared\Http\Respuesta;
use ProconSEO\Shared\Http\Router;
use ProconSEO\Shared\Vista;

// Parte 2 (José María): catálogo y cobertura.
// Solo su responsable edita este fichero.
return static function (Router $r): void {
    // Página «Catálogo y cobertura» (RF-60): todas las subcomprobaciones con su cobertura.
    $r->get('/catalogo', static function (Peticion $p): Respuesta {
        $vista = VistaCatalogo::actual();
        $filtros = VistaCatalogo::filtros($p->entrada('bloque'), $p->entrada('cobertura'), $p->entrada('texto'));

        return Respuesta::html(Vista::render('catalog/indice', [
            'titulo' => 'Catálogo y cobertura',
            'estilos' => ['/assets/css/catalogo.css'],
            'version' => $vista->version(),
            'resumen' => $vista->resumen(),
            'criterios' => $vista->criterios($filtros),
            'filtros' => $filtros,
            'total' => $vista->total(),
            'bloques' => Bloque::cases(),
            'coberturas' => Cobertura::cases(),
        ]));
    });

    // La misma cobertura en JSON, por ejemplo para el resumen del dashboard (RF-20).
    $r->get('/api/catalogo/cobertura', static function (Peticion $p): Respuesta {
        $vista = VistaCatalogo::actual();

        return Respuesta::json(['version' => $vista->version(), 'resumen' => $vista->resumen()]);
    });
};
