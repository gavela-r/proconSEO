<?php

declare(strict_types=1);

use ProconSEO\Export\DatosExportacion;
use ProconSEO\Export\ExportadorCsv;
use ProconSEO\Export\ExportadorJson;
use ProconSEO\Export\ExportadorPdf;
use ProconSEO\Projects\Formato;
use ProconSEO\Projects\RepositorioProyectos;
use ProconSEO\Results\Informe;
use ProconSEO\Results\RepositorioResultados;
use ProconSEO\Run\Fabrica;
use ProconSEO\Shared\BaseDatos;
use ProconSEO\Shared\Contract\Catalogo;
use ProconSEO\Shared\Error\ErrorDominio;
use ProconSEO\Shared\Http\Peticion;
use ProconSEO\Shared\Http\Respuesta;
use ProconSEO\Shared\Http\Router;
use ProconSEO\Shared\Vista;

// Parte 3 (Adrián): exportaciones (especificación 4.7, RF-50 a RF-52; decisión 17: Excel en CSV).
// Solo su responsable edita este fichero.
return static function (Router $r): void {
    $catalogo = static function (): ?Catalogo {
        try {
            return (new Fabrica())->catalogo();
        } catch (Throwable) {
            // Sin catálogo se exporta el identificador de cada comprobación en lugar de su nombre.
            return null;
        }
    };

    $datos = static fn (): DatosExportacion => new DatosExportacion(new RepositorioResultados(BaseDatos::conexion()), $catalogo());

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

    // PDF en el orden de la especificación 4.4, sin recursos remotos.
    $r->get('/analisis/{id}/exportar/pdf', static function (Peticion $p) use ($analisis, $catalogo, $descarga): Respuesta {
        // Un análisis con cientos de resultados tarda varios segundos en maquetarse.
        set_time_limit(120);
        // Un sitio completo de cientos de páginas necesita más memoria que el límite por defecto (128 MB).
        ini_set('memory_limit', '512M');
        $d = $analisis($p);
        $id = (int) $d['analisis']['id'];
        $repo = new RepositorioResultados(BaseDatos::conexion());

        $html = Vista::render('results/pdf', [
            'analisis' => $repo->analisis($id),
            'informe' => new Informe($repo->resultados($id), $repo->recursos($id), $repo->fases($id), $catalogo()),
            'exportacion' => $d,
            'generado' => Formato::fecha(gmdate('Y-m-d H:i:s')),
        ], false);

        return $descarga(ExportadorPdf::generar($html), 'application/pdf', DatosExportacion::nombreFichero($d, 'pdf'));
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
