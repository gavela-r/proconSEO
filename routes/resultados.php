<?php

declare(strict_types=1);

use ProconSEO\Results\BorradoAnalisis;
use ProconSEO\Results\ComparacionAnalisis;
use ProconSEO\Results\CompromisosKitDigital;
use ProconSEO\Results\FiltroResultados;
use ProconSEO\Results\Glosario;
use ProconSEO\Results\Informe;
use ProconSEO\Results\RepositorioResultados;
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
    $r->get('/analisis/{id}', static function (Peticion $p) use ($repo, $catalogo, $estilos): Respuesta {
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
                $repositorio->resultados($id),
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
            'revisado' => $p->entrada('revisado') === '1',
        ]));
    });
};
