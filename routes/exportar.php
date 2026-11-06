<?php

declare(strict_types=1);

use ProconSEO\Export\DatosExportacion;
use ProconSEO\Export\ExportadorCsv;
use ProconSEO\Export\ExportadorJson;
use ProconSEO\Projects\RepositorioProyectos;
use ProconSEO\Results\RepositorioResultados;
use ProconSEO\Run\Fabrica;
use ProconSEO\Shared\BaseDatos;
use ProconSEO\Shared\Contract\Catalogo;
use ProconSEO\Shared\Error\ErrorDominio;
use ProconSEO\Shared\Http\Peticion;
use ProconSEO\Shared\Http\Respuesta;
use ProconSEO\Shared\Http\Router;

// Parte 3 (Adrián): exportaciones (especificación 4.7, RF-50 a RF-52; decisión 17: Excel en CSV).
// Solo su responsable edita este fichero.
return static function (Router $r): void {
    $datos = static function (): DatosExportacion {
        $catalogo = null;
        try {
            $catalogo = (new Fabrica())->catalogo();
        } catch (Throwable) {
            // Sin catálogo se exporta el identificador de cada comprobación en lugar de su nombre.
        }

        return new DatosExportacion(new RepositorioResultados(BaseDatos::conexion()), $catalogo instanceof Catalogo ? $catalogo : null);
    };

    /** Descarga de un fichero generado: nunca se muestra en el navegador como página. */
    $descarga = static fn (string $contenido, string $tipo, string $nombre): Respuesta => new Respuesta($contenido, 200, [
        'Content-Type' => $tipo,
        'Content-Disposition' => 'attachment; filename="' . $nombre . '"',
        'Cache-Control' => 'private, no-store',
    ]);

    $analisis = static fn (Peticion $p) => $datos()->analisis((int) $p->parametro('id'))
        ?? throw new ErrorDominio('no_encontrado', 'El análisis no existe.', 404);

    $r->get('/analisis/{id}/exportar/csv', static function (Peticion $p) use ($analisis, $descarga): Respuesta {
        $d = $analisis($p);

        return $descarga(ExportadorCsv::generar($d), 'text/csv; charset=utf-8', DatosExportacion::nombreFichero($d, 'csv'));
    });

    $r->get('/analisis/{id}/exportar/json', static function (Peticion $p) use ($analisis, $descarga): Respuesta {
        $d = $analisis($p);

        return $descarga(ExportadorJson::generar($d), 'application/json; charset=utf-8', DatosExportacion::nombreFichero($d, 'json'));
    });

    // Exportación completa del proyecto (RF-05): el proyecto y todos sus análisis con sus resultados,
    // evidencias y revisiones.
    $r->get('/proyectos/{id}/exportar/json', static function (Peticion $p) use ($datos, $descarga): Respuesta {
        $repo = new RepositorioProyectos(BaseDatos::conexion());
        $proyecto = $repo->obtener((int) $p->parametro('id'))
            ?? throw new ErrorDominio('no_encontrado', 'El proyecto no existe.', 404);
        $exportador = $datos();

        $analisis = [];
        foreach ($repo->historial((int) $proyecto['id']) as $a) {
            $completo = $exportador->analisis((int) $a['id']);
            if ($completo !== null) {
                unset($completo['formato']);
                $analisis[] = $completo;
            }
        }
        $esPrueba = (bool) $proyecto['es_dato_prueba'];

        $contenido = ExportadorJson::generar([
            'formato' => ['nombre' => 'proconSEO', 'version' => DatosExportacion::VERSION_FORMATO, 'tipo' => 'proyecto_completo'],
            'datos_de_prueba' => $esPrueba,
            'aviso' => $esPrueba ? 'DATOS DE PRUEBA: proyecto de demostración; sus resultados no corresponden a ninguna web real.' : null,
            'proyecto' => [
                'id' => (int) $proyecto['id'],
                'nombre' => $proyecto['nombre'],
                'cliente' => $proyecto['cliente'],
                'url_principal' => $proyecto['url_principal'],
                'notas' => $proyecto['descripcion'],
                'estado' => $proyecto['estado'],
                'tipo' => $proyecto['tipo'],
                'sector' => $proyecto['sector'],
                'creado_en_utc' => $proyecto['creado_en'],
            ],
            'analisis' => $analisis,
        ]);

        $nombre = 'proconseo-proyecto-' . (int) $proyecto['id'] . '-' . gmdate('Y-m-d') . ($esPrueba ? '-DATOS-DE-PRUEBA' : '') . '.json';

        return $descarga($contenido, 'application/json; charset=utf-8', $nombre);
    });
};
