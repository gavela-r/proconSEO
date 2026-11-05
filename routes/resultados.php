<?php

declare(strict_types=1);

use ProconSEO\Results\CompromisosKitDigital;
use ProconSEO\Results\FiltroResultados;
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

// Parte 3 (Adrián): dashboard del análisis y detalle de cada resultado.
// Solo su responsable edita este fichero.
return static function (Router $r): void {
    $repo = static fn (): RepositorioResultados => new RepositorioResultados(BaseDatos::conexion());
    $estilos = ['/assets/css/parte3.css'];

    // El catálogo solo aporta nombres y explicaciones: si no se puede cargar, se muestran los identificadores.
    $catalogo = static function (): ?Catalogo {
        try {
            return (new Fabrica())->catalogo();
        } catch (Throwable $e) {
            error_log('Catálogo no disponible en resultados: ' . $e->getMessage());

            return null;
        }
    };

    // Dashboard del análisis en el orden de la especificación (4.4, RF-20, RF-21).
    $r->get('/analisis/{id}', static function (Peticion $p) use ($repo, $catalogo, $estilos): Respuesta {
        $repositorio = $repo();
        $id = (int) $p->parametro('id');
        $analisis = $repositorio->analisis($id) ?? throw new ErrorDominio('no_encontrado', 'El análisis no existe.', 404);

        return Respuesta::html(Vista::render('results/dashboard', [
            'titulo' => 'Resultados del análisis',
            'estilos' => $estilos,
            'analisis' => $analisis,
            'informe' => new Informe(
                $repositorio->resultados($id),
                $repositorio->recursos($id),
                $repositorio->fases($id),
                $catalogo(),
                FiltroResultados::desdeConsulta($p->consulta), // RF-23
            ),
            'eventos' => $repositorio->eventosSeguridad($id),
            'conKitDigital' => CompromisosKitDigital::aplica($analisis['proyecto_tipo']),
        ]));
    });

    // Vista "Compromisos Kit Digital" (especificación 6.7, RF-07): solo en proyectos Kit Digital.
    $r->get('/analisis/{id}/kit-digital', static function (Peticion $p) use ($repo, $catalogo, $estilos): Respuesta {
        $repositorio = $repo();
        $id = (int) $p->parametro('id');
        $analisis = $repositorio->analisis($id) ?? throw new ErrorDominio('no_encontrado', 'El análisis no existe.', 404);
        if (!CompromisosKitDigital::aplica($analisis['proyecto_tipo'])) {
            throw new ErrorDominio('no_encontrado', 'La vista «Compromisos Kit Digital» solo existe en proyectos de tipo Web Kit Digital o Tienda Kit Digital.', 404);
        }

        $cat = $catalogo();
        $resultados = Informe::conTitulos($repositorio->resultados($id), $cat);

        return Respuesta::html(Vista::render('results/kit_digital', [
            'titulo' => 'Compromisos Kit Digital',
            'estilos' => $estilos,
            'analisis' => $analisis,
            'compromisos' => $cat === null ? null : CompromisosKitDigital::agrupar($resultados, $cat, $analisis['proyecto_tipo']),
            'hayResultados' => $resultados !== [],
        ]));
    });

    // Detalle de un resultado con todos los campos de la sección 5 (RF-22).
    $r->get('/resultados/{id}', static function (Peticion $p) use ($repo, $catalogo, $estilos): Respuesta {
        $repositorio = $repo();
        $resultado = $repositorio->resultado((int) $p->parametro('id'))
            ?? throw new ErrorDominio('no_encontrado', 'El resultado no existe.', 404);
        $cat = $catalogo();
        $ficha = $cat?->obtener((string) $resultado['id_comprobacion']);
        $resultado = Informe::conTitulos([$resultado], $cat)[0];

        return Respuesta::html(Vista::render('results/detalle', [
            'titulo' => $ficha?->nombre ?? (string) $resultado['id_comprobacion'],
            'estilos' => $estilos,
            'resultado' => $resultado,
            'ficha' => $ficha,
            'analisis' => $repositorio->analisis((int) $resultado['analisis_id']),
            'evidencias' => $repositorio->evidencias((int) $resultado['id']),
            'revisiones' => $repositorio->revisiones((int) $resultado['id']),
        ]));
    });
};
