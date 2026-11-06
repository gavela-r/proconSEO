<?php

declare(strict_types=1);

use ProconSEO\Run\Fabrica;
use ProconSEO\Run\Programaciones;
use ProconSEO\Run\RegistroAuditoria;
use ProconSEO\Shared\BaseDatos;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\EstadoAnalisis;
use ProconSEO\Shared\Enum\EstadoFase;
use ProconSEO\Shared\Enum\Fase;
use ProconSEO\Shared\Error\ErrorDominio;
use ProconSEO\Shared\Http\Peticion;
use ProconSEO\Shared\Http\Respuesta;
use ProconSEO\Shared\Http\Router;
use ProconSEO\Shared\Vista;

// Parte 1 (Amilivia): nuevo análisis, progreso y cancelación.
// Solo su responsable edita este fichero.
return static function (Router $r): void {
    $proyectos = static fn (): array => BaseDatos::conexion()
        ->query('SELECT id, nombre, url_principal, es_dato_prueba FROM proyectos WHERE estado <> \'archivado\' ORDER BY nombre')
        ->fetchAll();

    $formulario = static function (array $valores = [], ?string $error = null, int $estado = 200) use ($proyectos): Respuesta {
        return Respuesta::html(Vista::render('run/nuevo', [
            'titulo' => 'Nuevo análisis',
            'estilos' => ['/assets/css/run.css'],
            'proyectos' => $proyectos(),
            'alcances' => Alcance::opciones(),
            'valores' => $valores,
            'error' => $error,
            'detenidos' => (new Fabrica())->interruptor()->activo(),
        ]), $estado);
    };

    // Formulario. Desde la ficha de un proyecto se enlaza con /analisis/nuevo?proyecto={id}.
    $r->get('/analisis/nuevo', static function (Peticion $p) use ($formulario, $proyectos): Respuesta {
        $proyectoId = (int) $p->entrada('proyecto', 0);
        $url = '';
        foreach ($proyectos() as $proyecto) {
            if ((int) $proyecto['id'] === $proyectoId) {
                $url = (string) $proyecto['url_principal'];
            }
        }

        return $formulario(['proyecto' => $proyectoId, 'url' => $url, 'alcance' => Alcance::UrlIndividual->value]);
    });

    $r->post('/analisis', static function (Peticion $p) use ($formulario): Respuesta {
        $valores = [
            'proyecto' => (int) $p->entrada('proyecto', 0),
            'url' => (string) $p->entrada('url', ''),
            'alcance' => (string) $p->entrada('alcance', ''),
            'objetivo' => (string) $p->entrada('objetivo', ''),
            'hosts_extra' => (string) $p->entrada('hosts_extra', ''),
        ];

        try {
            $id = (new Fabrica())->lanzador()->lanzar($valores['proyecto'], $valores['url'], $valores['alcance'], $valores['objetivo'], $valores['hosts_extra'], Fabrica::ipOrigen());
        } catch (ErrorDominio $e) {
            return $formulario($valores, $e->getMessage(), $e->estadoHttp);
        }

        return Respuesta::redirigir("/analisis/{$id}/progreso");
    });

    $r->get('/analisis/{id}/progreso', static function (Peticion $p): Respuesta {
        $repo = (new Fabrica())->repositorio();
        $analisis = $repo->obtener((int) $p->parametro('id'))
            ?? throw new ErrorDominio('no_encontrado', 'El análisis no existe.', 404);

        return Respuesta::html(Vista::render('run/progreso', [
            'titulo' => 'Progreso del análisis',
            'estilos' => ['/assets/css/run.css'],
            'analisis' => $analisis,
            'proyecto' => $repo->proyecto((int) $analisis['proyecto_id']),
            'fases' => $repo->fases((int) $analisis['id']),
        ]));
    });

    // Estado para la pantalla de progreso (la consulta cada pocos segundos).
    $r->get('/api/analisis/{id}/progreso', static function (Peticion $p): Respuesta {
        $repo = (new Fabrica())->repositorio();
        $id = (int) $p->parametro('id');
        $analisis = $repo->obtener($id) ?? throw new ErrorDominio('no_encontrado', 'El análisis no existe.', 404);
        $estado = EstadoAnalisis::from((string) $analisis['estado']);

        return Respuesta::json([
            'estado' => $estado->value,
            'estado_texto' => $estado->etiqueta(),
            'terminado' => !in_array($estado, [EstadoAnalisis::EnCola, EstadoAnalisis::EnCurso, EstadoAnalisis::Cancelando], true),
            'motivo' => $analisis['motivo_cierre'],
            'fases' => array_map(static fn (array $f): array => [
                'fase' => $f['fase'],
                'nombre' => Fase::from((string) $f['fase'])->etiqueta(),
                'estado' => $f['estado'],
                'estado_texto' => EstadoFase::from((string) $f['estado'])->etiqueta(),
                'hechos' => (int) $f['hechos'],
                'total' => (int) $f['total'],
                'mensaje' => $f['mensaje'],
            ], $repo->fases($id)),
        ]);
    });

    $cancelar = static function (Peticion $p): int {
        $fabrica = new Fabrica();
        $repo = $fabrica->repositorio();
        $id = (int) $p->parametro('id');
        $analisis = $repo->obtener($id) ?? throw new ErrorDominio('no_encontrado', 'El análisis no existe.', 404);
        $repo->pedirCancelacion($id);
        $fabrica->auditoria()->registrar(RegistroAuditoria::CANCELACION_PEDIDA, $id, (int) $analisis['proyecto_id'], Fabrica::ipOrigen());

        return $id;
    };

    $r->post('/api/analisis/{id}/cancelar', static fn (Peticion $p): Respuesta => Respuesta::json(['cancelacion_pedida' => $cancelar($p) > 0]));

    // Sin JavaScript: el formulario de cancelar funciona igual.
    $r->post('/analisis/{id}/cancelar', static fn (Peticion $p): Respuesta => Respuesta::redirigir('/analisis/' . $cancelar($p) . '/progreso'));

    // Análisis programados de un proyecto.
    $programados = static function (int $proyectoId, array $valores = [], ?string $error = null, int $estado = 200): Respuesta {
        $fabrica = new Fabrica();
        $proyecto = $fabrica->repositorio()->proyecto($proyectoId)
            ?? throw new ErrorDominio('no_encontrado', 'El proyecto no existe.', 404);

        return Respuesta::html(Vista::render('run/programados', [
            'titulo' => 'Análisis programados',
            'estilos' => ['/assets/css/run.css'],
            'proyecto' => $proyecto,
            'programaciones' => $fabrica->programaciones()->deProyecto($proyectoId),
            'alcances' => Alcance::opciones(),
            'periodos' => Programaciones::PERIODOS,
            'valores' => $valores + ['url' => (string) ($proyecto['url_principal'] ?? ''), 'alcance' => Alcance::UrlIndividual->value, 'cada_dias' => 7],
            'error' => $error,
        ]), $estado);
    };

    $r->get('/proyectos/{id}/programados', static fn (Peticion $p): Respuesta => $programados((int) $p->parametro('id')));

    $r->post('/proyectos/{id}/programados', static function (Peticion $p) use ($programados): Respuesta {
        $proyectoId = (int) $p->parametro('id');
        $valores = [
            'url' => (string) $p->entrada('url', ''),
            'alcance' => (string) $p->entrada('alcance', ''),
            'cada_dias' => (int) $p->entrada('cada_dias', 0),
            'hosts_extra' => (string) $p->entrada('hosts_extra', ''),
        ];
        try {
            (new Fabrica())->programaciones()->crear($proyectoId, $valores['url'], $valores['alcance'], $valores['cada_dias'], $valores['hosts_extra']);
        } catch (ErrorDominio $e) {
            return $programados($proyectoId, $valores, $e->getMessage(), $e->estadoHttp);
        }

        return Respuesta::redirigir("/proyectos/{$proyectoId}/programados");
    });

    $cambiarProgramacion = static function (Peticion $p, string $accion): Respuesta {
        $programaciones = (new Fabrica())->programaciones();
        $id = (int) $p->parametro('id');
        $programacion = $programaciones->obtener($id) ?? throw new ErrorDominio('no_encontrado', 'La programación no existe.', 404);
        match ($accion) {
            'pausar' => $programaciones->activar($id, false),
            'reanudar' => $programaciones->activar($id, true),
            default => $programaciones->borrar($id),
        };

        return Respuesta::redirigir('/proyectos/' . (int) $programacion['proyecto_id'] . '/programados');
    };
    foreach (['pausar', 'reanudar', 'borrar'] as $accion) {
        $r->post('/programados/{id}/' . $accion, static fn (Peticion $p): Respuesta => $cambiarProgramacion($p, $accion));
    }

    // Avisos de los análisis programados.
    $r->get('/avisos', static function (Peticion $p): Respuesta {
        $avisos = (new Fabrica())->avisos();
        $soloSinLeer = $p->entrada('todos', '') !== '1';

        return Respuesta::html(Vista::render('run/avisos', [
            'titulo' => 'Avisos',
            'estilos' => ['/assets/css/run.css'],
            'avisos' => $avisos->lista($soloSinLeer),
            'sinLeer' => $avisos->sinLeer(),
            'soloSinLeer' => $soloSinLeer,
        ]));
    });

    $r->post('/avisos/leidos', static function (): Respuesta {
        (new Fabrica())->avisos()->marcarTodosLeidos();

        return Respuesta::redirigir('/avisos');
    });

    $r->post('/avisos/{id}/leido', static function (Peticion $p): Respuesta {
        (new Fabrica())->avisos()->marcarLeido((int) $p->parametro('id'));

        return Respuesta::redirigir('/avisos');
    });
};
