<?php

declare(strict_types=1);

use ProconSEO\Results\BorradoAnalisis;
use ProconSEO\Results\ComparacionAnalisis;
use ProconSEO\Results\CompromisosKitDigital;
use ProconSEO\Results\FiltroResultados;
use ProconSEO\Results\Glosario;
use ProconSEO\Results\GuiaWcag;
use ProconSEO\Results\Informe;
use ProconSEO\Results\RepositorioResultados;
use ProconSEO\Results\RevisionesManuales;
use ProconSEO\Results\PanelesSitio;
use ProconSEO\Results\Seguimientos;
use ProconSEO\Results\VistaPaginas;
use ProconSEO\Results\VistaWcag;
use ProconSEO\Review\CapturaSegura;
use ProconSEO\Run\Fabrica;
use ProconSEO\Shared\BaseDatos;
use ProconSEO\Shared\Config;
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
    $seguimientos = static fn (): Seguimientos => new Seguimientos(BaseDatos::conexion());

    // Añade a cada resultado su seguimiento actual (por proyecto y comprobación) para mostrarlo y filtrar.
    $conSeguimiento = static function (array $resultados, array $actuales): array {
        return array_map(static function (array $r) use ($actuales): array {
            $s = $actuales[(string) $r['id_comprobacion']] ?? null;

            return $r + ['seguimiento' => $s === null ? Seguimientos::PENDIENTE : (string) $s['estado'], 'seguimiento_actual' => $s];
        }, $resultados);
    };

    // El catálogo solo aporta nombres y explicaciones: si no se puede cargar, se muestran los identificadores.
    $catalogo = static function (): ?Catalogo {
        try {
            return (new Fabrica())->catalogo();
        } catch (Throwable $e) {
            error_log('Catálogo no disponible en resultados: ' . $e->getMessage());

            return null;
        }
    };

    // Glosario de los términos técnicos que aparecen en los resultados.
    $r->get('/glosario', static fn (): Respuesta => Respuesta::html(Vista::render('results/glosario', [
        'titulo' => 'Glosario',
        'estilos' => [...$estilos, '/assets/css/glosario.css'],
        'glosario' => Glosario::comun(),
    ])));

    // Dashboard del análisis en el orden de la especificación (4.4, RF-20, RF-21).
    $r->get('/analisis/{id}', static function (Peticion $p) use ($repo, $catalogo, $estilos, $seguimientos, $conSeguimiento): Respuesta {
        $repositorio = $repo();
        $id = (int) $p->parametro('id');
        $analisis = $repositorio->analisis($id) ?? throw new ErrorDominio('no_encontrado', 'El análisis no existe.', 404);
        $cat = $catalogo();
        // Qué ha cambiado respecto a otro análisis del histórico: por defecto, el anterior de la misma
        // dirección y alcance; con ?comparar={id}, cualquiera anterior del mismo proyecto.
        $candidatos = $repositorio->analisisParaComparar($analisis);
        $elegido = (int) $p->entrada('comparar', 0);
        $anterior = $elegido > 0
            ? (array_values(array_filter($candidatos, static fn (array $a): bool => (int) $a['id'] === $elegido))[0] ?? null)
            : $repositorio->analisisAnterior($analisis);
        $comparacion = $anterior === null ? null : new ComparacionAnalisis(
            $repositorio->resultados((int) $anterior['id']),
            Informe::conTitulos($repositorio->resultados($id), $cat),
        );

        return Respuesta::html(Vista::render('results/dashboard', [
            'titulo' => 'Resultados del análisis',
            'estilos' => $estilos,
            'analisis' => $analisis,
            'informe' => new Informe(
                $conSeguimiento($repositorio->resultados($id), $seguimientos()->actuales((int) $analisis['proyecto_id'])),
                $repositorio->recursos($id),
                $repositorio->fases($id),
                $cat,
                FiltroResultados::desdeConsulta($p->consulta), // RF-23
            ),
            'anterior' => $anterior,
            'comparacion' => $comparacion,
            'candidatos' => $candidatos,
            'eventos' => $repositorio->eventosSeguridad($id),
            'conKitDigital' => CompromisosKitDigital::aplica($analisis['proyecto_tipo']),
        ]));
    });

    // Vista por páginas e inventario de lo descargado: qué páginas y qué zonas concentran los problemas.
    $r->get('/analisis/{id}/paginas', static function (Peticion $p) use ($repo, $estilos): Respuesta {
        $repositorio = $repo();
        $id = (int) $p->parametro('id');
        $analisis = $repositorio->analisis($id) ?? throw new ErrorDominio('no_encontrado', 'El análisis no existe.', 404);
        $orden = (string) $p->entrada('orden', 'problemas');
        $orden = array_key_exists($orden, VistaPaginas::ORDENES) ? $orden : 'problemas';

        return Respuesta::html(Vista::render('results/paginas', [
            'titulo' => 'Páginas analizadas',
            'estilos' => $estilos,
            'analisis' => $analisis,
            'paginas' => new VistaPaginas($resultados = $repositorio->resultados($id), $repositorio->recursos($id), $orden),
            'paneles' => new PanelesSitio($resultados),
            'orden' => $orden,
            'conKitDigital' => CompromisosKitDigital::aplica($analisis['proyecto_tipo']),
        ]));
    });

    // Criterios WCAG 2.2 A y AA con lo que dice el análisis de cada uno (para la declaración de accesibilidad).
    $r->get('/analisis/{id}/wcag', static function (Peticion $p) use ($repo, $catalogo, $estilos): Respuesta {
        $repositorio = $repo();
        $id = (int) $p->parametro('id');
        $analisis = $repositorio->analisis($id) ?? throw new ErrorDominio('no_encontrado', 'El análisis no existe.', 404);

        return Respuesta::html(Vista::render('results/wcag', [
            'titulo' => 'Criterios WCAG 2.2',
            'estilos' => $estilos,
            'analisis' => $analisis,
            'wcag' => new VistaWcag(Informe::conTitulos($repositorio->resultados($id), $catalogo()), (new RevisionesManuales(BaseDatos::conexion()))->vigentes($id)),
            'conKitDigital' => CompromisosKitDigital::aplica($analisis['proyecto_tipo']),
        ]));
    });

    // Revisión manual guiada de un criterio WCAG: qué ha visto la herramienta, cómo revisarlo y dónde apuntarlo.
    $criterioWcag = static function (Peticion $p, ?string $error = null, array $valores = [], int $estado = 200) use ($repo, $catalogo, $estilos): Respuesta {
        $repositorio = $repo();
        $id = (int) $p->parametro('id');
        $analisis = $repositorio->analisis($id) ?? throw new ErrorDominio('no_encontrado', 'El análisis no existe.', 404);
        $numero = (string) $p->entrada('criterio', '');
        if (!RevisionesManuales::esCriterio($numero)) {
            throw new ErrorDominio('no_encontrado', 'Ese criterio no es de WCAG 2.2 A o AA.', 404);
        }
        $manuales = new RevisionesManuales(BaseDatos::conexion());
        $wcag = new VistaWcag(Informe::conTitulos($repositorio->resultados($id), $catalogo()), $manuales->vigentes($id));
        $criterio = array_values(array_filter($wcag->criterios, static fn (array $c): bool => $c['numero'] === $numero))[0];

        return Respuesta::html(Vista::render('results/wcag_criterio', [
            'titulo' => 'WCAG ' . $numero . ' · ' . $criterio['nombre'],
            'estilos' => $estilos,
            'analisis' => $analisis,
            'criterio' => $criterio,
            'pasos' => GuiaWcag::pasos($numero),
            'historial' => $manuales->historial($id, $numero),
            'error' => $error,
            'valores' => $valores,
            'guardado' => $p->entrada('guardado') === '1',
        ]), $estado);
    };

    // El criterio (2.1.1) va en ?criterio=: el router común solo admite parámetros numéricos.
    $r->get('/analisis/{id}/wcag/revisar', static fn (Peticion $p): Respuesta => $criterioWcag($p));

    // Solo inserta: cada revisión manual es una fila nueva con quién, qué miró y qué vio.
    $r->post('/analisis/{id}/wcag/revisiones', static function (Peticion $p) use ($repo, $criterioWcag): Respuesta {
        $id = (int) $p->parametro('id');
        $analisis = $repo()->analisis($id) ?? throw new ErrorDominio('no_encontrado', 'El análisis no existe.', 404);
        $numero = (string) $p->entrada('criterio', '');
        $valores = array_map(
            static fn (mixed $v): string => is_string($v) ? $v : '',
            array_intersect_key($p->cuerpo, array_flip(['resultado', 'revisor', 'paginas', 'notas'])),
        ) + ['resultado' => '', 'revisor' => '', 'paginas' => '', 'notas' => ''];

        try {
            $revision = (new RevisionesManuales(BaseDatos::conexion()))->registrar($id, $numero, $valores['resultado'], $valores['revisor'], $valores['paginas'], $valores['notas']);
        } catch (ErrorDominio $e) {
            if ($e->codigo !== 'revision_manual_no_valida') {
                throw $e;
            }

            return $criterioWcag($p, $e->getMessage(), $valores, 422);
        }

        (new Fabrica())->auditoria()->registrar(
            'revision_manual_guardada',
            $id,
            (int) $analisis['proyecto_id'],
            Fabrica::ipOrigen(),
            null,
            null,
            "Revisión manual {$revision} de WCAG {$numero}: {$valores['resultado']}",
        );

        return Respuesta::redirigir('/analisis/' . $id . '/wcag/revisar?' . http_build_query(['criterio' => $numero, 'guardado' => '1']) . '#t-revisar');
    });

    // Borrado definitivo de un análisis con confirmación previa (RF-16; decisión 16).
    $borrado = static fn (): BorradoAnalisis => new BorradoAnalisis(
        BaseDatos::conexion(),
        new CapturaSegura(Config::raiz() . '/storage/capturas'),
        (new Fabrica())->auditoria(),
    );

    $confirmarBorrado = static function (Peticion $p, ?string $error = null, int $estado = 200) use ($repo, $borrado, $estilos): Respuesta {
        $analisis = $repo()->analisis((int) $p->parametro('id'))
            ?? throw new ErrorDominio('no_encontrado', 'El análisis no existe.', 404);

        return Respuesta::html(Vista::render('results/borrar', [
            'titulo' => 'Borrar análisis',
            'estilos' => $estilos,
            'analisis' => $analisis,
            'resumen' => $borrado()->resumen((int) $analisis['id']),
            'enMarcha' => BorradoAnalisis::enMarcha((string) $analisis['estado']),
            'error' => $error,
        ]), $estado);
    };

    $r->get('/analisis/{id}/borrar', static fn (Peticion $p): Respuesta => $confirmarBorrado($p));

    $r->delete('/analisis/{id}', static function (Peticion $p) use ($borrado, $confirmarBorrado): Respuesta {
        if ($p->entrada('confirmo') !== '1') {
            return $confirmarBorrado($p, 'Marca la casilla para confirmar que quieres borrarlo para siempre.', 422);
        }

        try {
            $proyectoId = $borrado()->borrar((int) $p->parametro('id'), Fabrica::ipOrigen());
        } catch (ErrorDominio $e) {
            if ($e->codigo !== 'analisis_en_marcha') {
                throw $e;
            }

            return $confirmarBorrado($p, $e->getMessage(), 409);
        }

        return Respuesta::redirigir("/proyectos/{$proyectoId}?borrado=1");
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
    // Detalle de un resultado. Con $error y $valores, el formulario de seguimiento vuelve con lo escrito.
    $detalle = static function (Peticion $p, ?string $error = null, array $valores = [], int $estado = 200) use ($repo, $catalogo, $estilos, $seguimientos): Respuesta {
        $repositorio = $repo();
        $resultado = $repositorio->resultado((int) $p->parametro('id'))
            ?? throw new ErrorDominio('no_encontrado', 'El resultado no existe.', 404);
        $cat = $catalogo();
        $ficha = $cat?->obtener((string) $resultado['id_comprobacion']);
        $resultado = Informe::conTitulos([$resultado], $cat)[0];
        $analisis = $repositorio->analisis((int) $resultado['analisis_id']);
        $seg = $seguimientos();
        $proyectoId = $analisis !== null ? (int) $analisis['proyecto_id'] : 0;
        // La misma comprobación en las otras páginas del análisis, para ir de una a otra.
        $otrasPaginas = array_values(array_filter(
            $repositorio->resultados((int) $resultado['analisis_id']),
            static fn (array $o): bool => $o['id_comprobacion'] === $resultado['id_comprobacion'] && (int) $o['id'] !== (int) $resultado['id'],
        ));

        return Respuesta::html(Vista::render('results/detalle', [
            'titulo' => $ficha?->nombre ?? (string) $resultado['id_comprobacion'],
            'estilos' => $estilos,
            'resultado' => $resultado,
            'ficha' => $ficha,
            'analisis' => $analisis,
            'seguimiento' => $proyectoId > 0 ? ($seg->actuales($proyectoId)[(string) $resultado['id_comprobacion']] ?? null) : null,
            'historialSeguimiento' => $proyectoId > 0 ? $seg->historial($proyectoId, (string) $resultado['id_comprobacion']) : [],
            'errorSeguimiento' => $error,
            'valoresSeguimiento' => $valores,
            'seguimientoGuardado' => $p->entrada('seguimiento') === '1',
            'evidencias' => $repositorio->evidencias((int) $resultado['id']),
            'revisiones' => $repositorio->revisiones((int) $resultado['id']),
            'revisado' => $p->entrada('revisado') === '1',
            'enBloque' => max(0, min(10000, (int) $p->entrada('en_bloque', 0))),
            'otrasPaginas' => $otrasPaginas,
        ]), $estado);
    };

    $r->get('/resultados/{id}', static fn (Peticion $p): Respuesta => $detalle($p));

    // Seguimiento del hallazgo en el proyecto: solo inserta una fila nueva; el resultado no cambia (RF-42).
    $r->post('/resultados/{id}/seguimiento', static function (Peticion $p) use ($repo, $seguimientos, $detalle): Respuesta {
        $repositorio = $repo();
        $resultado = $repositorio->resultado((int) $p->parametro('id'))
            ?? throw new ErrorDominio('no_encontrado', 'El resultado no existe.', 404);
        $analisis = $repositorio->analisis((int) $resultado['analisis_id'])
            ?? throw new ErrorDominio('no_encontrado', 'El análisis no existe.', 404);
        $valores = array_map(
            static fn (mixed $v): string => is_string($v) ? $v : '',
            array_intersect_key($p->cuerpo, array_flip(['estado', 'autor', 'motivo'])),
        ) + ['estado' => '', 'autor' => '', 'motivo' => ''];

        try {
            $seguimientos()->registrar((int) $analisis['proyecto_id'], (string) $resultado['id_comprobacion'], $valores['estado'], $valores['autor'], $valores['motivo'], (int) $analisis['id']);
        } catch (ErrorDominio $e) {
            if ($e->codigo !== 'seguimiento_no_valido') {
                throw $e;
            }

            return $detalle($p, $e->getMessage(), $valores, 422);
        }

        (new Fabrica())->auditoria()->registrar(
            'seguimiento_guardado',
            (int) $analisis['id'],
            (int) $analisis['proyecto_id'],
            Fabrica::ipOrigen(),
            null,
            null,
            "Seguimiento de {$resultado['id_comprobacion']}: {$valores['estado']}",
        );

        return Respuesta::redirigir('/resultados/' . (int) $resultado['id'] . '?seguimiento=1#t-seguimiento');
    });
};
