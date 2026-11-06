<?php

declare(strict_types=1);

use ProconSEO\Projects\DatosProyecto;
use ProconSEO\Projects\ErroresFormulario;
use ProconSEO\Projects\RepositorioProyectos;
use ProconSEO\Shared\BaseDatos;
use ProconSEO\Shared\Enum\EstadoProyecto;
use ProconSEO\Shared\Enum\Sector;
use ProconSEO\Shared\Enum\TipoProyecto;
use ProconSEO\Shared\Error\ErrorDominio;
use ProconSEO\Shared\Http\Peticion;
use ProconSEO\Shared\Http\Respuesta;
use ProconSEO\Shared\Http\Router;
use ProconSEO\Shared\Vista;

// Parte 3 (Adrián): proyectos e histórico.
// Solo su responsable edita este fichero.
return static function (Router $r): void {
    $repo = static fn (): RepositorioProyectos => new RepositorioProyectos(BaseDatos::conexion());
    $estilos = ['/assets/css/parte3.css'];

    $proyecto = static fn (Peticion $p): array => $repo()->obtener((int) $p->parametro('id'))
        ?? throw new ErrorDominio('no_encontrado', 'El proyecto no existe.', 404);

    /** Campos del formulario tal como llegan, para volver a mostrarlos si hay errores. */
    $valoresFormulario = static fn (Peticion $p): array => array_map(
        static fn (mixed $v): string => is_string($v) ? $v : '',
        array_intersect_key($p->cuerpo, array_flip(['nombre', 'cliente', 'url_principal', 'descripcion', 'estado', 'tipo', 'sector'])),
    );

    $formulario = static function (string $titulo, string $accion, ?int $id, array $valores, array $errores = [], int $estado = 200, bool $esPrueba = false) use ($estilos): Respuesta {
        return Respuesta::html(Vista::render('projects/formulario', [
            'titulo' => $titulo,
            'estilos' => $estilos,
            'accion' => $accion,
            'id' => $id,
            'valores' => $valores,
            'errores' => $errores,
            'esPrueba' => $esPrueba,
            'tipos' => TipoProyecto::opciones(),
            'sectores' => Sector::opciones(),
            'estados' => EstadoProyecto::opciones(),
        ]), $estado);
    };

    // Lista y búsqueda (RF-01).
    $r->get('/proyectos', static function (Peticion $p) use ($repo, $estilos): Respuesta {
        $texto = mb_substr(trim((string) $p->entrada('q', '')), 0, 200);
        $tipo = TipoProyecto::tryFrom((string) $p->entrada('tipo', ''));
        $estado = EstadoProyecto::tryFrom((string) $p->entrada('estado', ''));

        return Respuesta::html(Vista::render('projects/lista', [
            'titulo' => 'Proyectos',
            'estilos' => $estilos,
            'proyectos' => $repo()->buscar($texto, $tipo, $estado),
            'limite' => RepositorioProyectos::LIMITE_BUSQUEDA,
            'filtros' => ['q' => $texto, 'tipo' => $tipo?->value ?? '', 'estado' => $estado?->value ?? ''],
            'tipos' => TipoProyecto::opciones(),
            'estados' => EstadoProyecto::opciones(),
        ]));
    });

    $r->get('/proyectos/nuevo', static fn (Peticion $p): Respuesta => $formulario(
        'Nuevo proyecto',
        '/proyectos',
        null,
        ['estado' => EstadoProyecto::Activo->value],
    ));

    $r->post('/proyectos', static function (Peticion $p) use ($repo, $formulario, $valoresFormulario): Respuesta {
        try {
            $id = $repo()->crear(DatosProyecto::desdeFormulario($p->cuerpo));
        } catch (ErroresFormulario $e) {
            return $formulario('Nuevo proyecto', '/proyectos', null, $valoresFormulario($p), $e->errores, 422);
        }

        return Respuesta::redirigir("/proyectos/{$id}");
    });

    // Ficha con el histórico de análisis (RF-04).
    $r->get('/proyectos/{id}', static function (Peticion $p) use ($repo, $proyecto, $estilos): Respuesta {
        $datos = $proyecto($p);

        return Respuesta::html(Vista::render('projects/ficha', [
            'titulo' => (string) $datos['nombre'],
            'estilos' => $estilos,
            'proyecto' => $datos,
            'historial' => $repo()->historial((int) $datos['id']),
            'borrado' => $p->entrada('borrado') === '1',
        ]));
    });

    $r->get('/proyectos/{id}/editar', static function (Peticion $p) use ($proyecto, $formulario): Respuesta {
        $datos = $proyecto($p);
        $valores = array_map(static fn (mixed $v): string => (string) $v, $datos);

        return $formulario('Editar proyecto', '/proyectos/' . (int) $datos['id'], (int) $datos['id'], $valores, esPrueba: (bool) $datos['es_dato_prueba']);
    });

    $r->put('/proyectos/{id}', static function (Peticion $p) use ($repo, $proyecto, $formulario, $valoresFormulario): Respuesta {
        $datos = $proyecto($p);
        $id = (int) $datos['id'];

        try {
            $repo()->actualizar($id, DatosProyecto::desdeFormulario($p->cuerpo));
        } catch (ErroresFormulario $e) {
            return $formulario('Editar proyecto', "/proyectos/{$id}", $id, $valoresFormulario($p), $e->errores, 422, (bool) $datos['es_dato_prueba']);
        }

        return Respuesta::redirigir("/proyectos/{$id}");
    });
};
