<?php

declare(strict_types=1);

use ProconSEO\Projects\ErroresFormulario;
use ProconSEO\Results\Informe;
use ProconSEO\Results\RepositorioResultados;
use ProconSEO\Review\CapturaSegura;
use ProconSEO\Review\DatosRevision;
use ProconSEO\Review\RepositorioRevisiones;
use ProconSEO\Run\Fabrica;
use ProconSEO\Run\RegistroAuditoria;
use ProconSEO\Shared\BaseDatos;
use ProconSEO\Shared\Config;
use ProconSEO\Shared\Enum\DecisionRevision;
use ProconSEO\Shared\Enum\EstadoResultado;
use ProconSEO\Shared\Error\ErrorDominio;
use ProconSEO\Shared\Http\Peticion;
use ProconSEO\Shared\Http\Respuesta;
use ProconSEO\Shared\Http\Router;
use ProconSEO\Shared\Vista;

// Parte 3 (Adrián): revisión humana (especificación 4.6, RF-40 a RF-44) y capturas (SEG-14).
// Solo su responsable edita este fichero.
return static function (Router $r): void {
    $estilos = ['/assets/css/parte3.css'];
    // Fuera de public/: las capturas solo se sirven a través de /evidencias/{id}/captura.
    $capturas = static fn (): CapturaSegura => new CapturaSegura(Config::raiz() . '/storage/capturas');

    $resultado = static function (Peticion $p): array {
        $repo = new RepositorioResultados(BaseDatos::conexion());
        $fila = $repo->resultado((int) $p->parametro('id'))
            ?? throw new ErrorDominio('no_encontrado', 'El resultado no existe.', 404);
        $catalogo = null;
        try {
            $catalogo = (new Fabrica())->catalogo();
        } catch (Throwable) {
            // Sin catálogo se muestra el identificador de la comprobación.
        }

        return [
            Informe::conTitulos([$fila], $catalogo)[0],
            $repo->analisis((int) $fila['analisis_id']),
            $repo->evidencias((int) $fila['id']),
            $repo->revisiones((int) $fila['id']),
        ];
    };

    $formulario = static function (Peticion $p, array $valores = [], array $errores = [], int $estado = 200) use ($resultado, $estilos): Respuesta {
        [$fila, $analisis, $evidencias, $revisiones] = $resultado($p);

        return Respuesta::html(Vista::render('results/revision', [
            'titulo' => 'Revisar resultado',
            'estilos' => $estilos,
            'resultado' => $fila,
            'analisis' => $analisis,
            'evidencias' => $evidencias,
            'revisiones' => $revisiones,
            'valores' => $valores,
            'errores' => $errores,
            'decisiones' => DecisionRevision::opciones(),
            'estados' => EstadoResultado::opciones(),
            'maxCaptura' => CapturaSegura::MAX_BYTES,
        ]), $estado);
    };

    $r->get('/resultados/{id}/revisar', static fn (Peticion $p): Respuesta => $formulario($p));

    // Solo inserta: el resultado original nunca se modifica (RF-42).
    $r->post('/resultados/{id}/revisiones', static function (Peticion $p) use ($resultado, $formulario, $capturas): Respuesta {
        [$fila, $analisis] = $resultado($p);
        $valores = array_map(
            static fn (mixed $v): string => is_string($v) ? $v : '',
            array_intersect_key($p->cuerpo, array_flip(['revisor', 'motivo', 'decision', 'nuevo_estado', 'justificacion', 'evidencia_texto'])),
        );

        // $_FILES no pasa por Peticion (Parte 0): se lee aquí y se valida en CapturaSegura.
        $fichero = $_FILES['captura'] ?? null;
        $almacen = $capturas();
        $captura = null;
        try {
            $captura = $almacen->guardar($fichero, 'analisis-' . (int) $fila['analisis_id']);
            $datos = DatosRevision::desdeFormulario($p->cuerpo, (string) $fila['estado_vigente'], $captura !== null);
            $revisionId = (new RepositorioRevisiones(BaseDatos::conexion()))->guardar((int) $fila['id'], (int) $fila['analisis_id'], $datos, $captura);
        } catch (ErroresFormulario $e) {
            $borrar = $captura !== null ? $almacen->rutaAbsoluta($captura['ruta']) : null;
            if ($borrar !== null) {
                @unlink($borrar);
            }

            return $formulario($p, $valores, $e->errores + ($captura !== null ? ['captura' => 'Vuelve a elegir la captura: por seguridad no se guarda hasta que el formulario es correcto.'] : []), 422);
        } catch (ErrorDominio $e) {
            if ($e->codigo !== 'captura_no_valida') {
                throw $e;
            }

            return $formulario($p, $valores, ['captura' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            $borrar = $captura !== null ? $almacen->rutaAbsoluta($captura['ruta']) : null;
            if ($borrar !== null) {
                @unlink($borrar);
            }
            throw $e;
        }

        (new Fabrica())->auditoria()->registrar(
            'revision_guardada',
            (int) $fila['analisis_id'],
            $analisis !== null ? (int) $analisis['proyecto_id'] : null,
            Fabrica::ipOrigen(),
            null,
            null,
            "Revisión {$revisionId} de {$fila['id_comprobacion']}: {$datos->decision->value}" . ($datos->nuevoEstado !== null ? " → {$datos->nuevoEstado->value}" : ''),
        );

        return Respuesta::redirigir('/resultados/' . (int) $fila['id'] . '?revisado=1#t-revision');
    });

    // Captura añadida por un revisor. Se sirve desde fuera de public/ y solo si es una captura guardada por la aplicación.
    $r->get('/evidencias/{id}/captura', static function (Peticion $p) use ($capturas): Respuesta {
        $evidencia = (new RepositorioRevisiones(BaseDatos::conexion()))->captura((int) $p->parametro('id'))
            ?? throw new ErrorDominio('no_encontrado', 'La captura no existe.', 404);
        $ruta = $capturas()->rutaAbsoluta($evidencia['ruta_fichero'])
            ?? throw new ErrorDominio('no_encontrado', 'La captura no existe.', 404);
        $mime = in_array($evidencia['tipo_mime'], ['image/png', 'image/jpeg'], true) ? $evidencia['tipo_mime'] : 'application/octet-stream';

        return new Respuesta((string) file_get_contents($ruta), 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="captura-' . (int) $p->parametro('id') . ($mime === 'image/png' ? '.png' : '.jpg') . '"',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    });
};
