<?php

declare(strict_types=1);

/**
 * Worker: coge análisis en cola y los ejecuta, respetando el máximo de análisis simultáneos
 * del perfil. Se puede lanzar varias veces en paralelo: cada análisis lo reserva uno solo.
 *
 * Cada minuto pone en cola los análisis programados a los que les toca, y al terminar uno
 * programado crea los avisos si ha encontrado algo grave.
 *
 *   php bin/worker.php            se queda esperando trabajo (Ctrl+C para parar)
 *   php bin/worker.php --una-vez  ejecuta lo que haya en cola y termina (útil con cron)
 *
 * En el servidor debe ejecutarse con un usuario sin privilegios (SEG-13).
 */

use ProconSEO\Fetch\LimitesDescarga;
use ProconSEO\Run\Fabrica;
use ProconSEO\Shared\Config;

require dirname(__DIR__) . '/vendor/autoload.php';

Config::cargar(dirname(__DIR__));
$unaVez = in_array('--una-vez', $argv, true);
$fabrica = new Fabrica();

$perfil = $fabrica->repositorio()->perfilVigente() ?? exit("No hay perfil de ejecución. Ejecuta composer migrar.\n");
$maxSimultaneos = (int) ($perfil['parametros']['trafico']['analisis_simultaneos']['valor'] ?? 2);

echo '[' . date('H:i:s') . "] Worker en marcha (máximo {$maxSimultaneos} análisis a la vez)." . PHP_EOL;

// Margen para dar por perdido un análisis «en curso»: el doble del máximo de un sitio completo
// (el orquestador ya corta al llegar a su límite, así que pasado esto no puede seguir vivo).
$margenHuerfanos = 2 * (int) ($perfil['parametros']['tiempo']['analisis_sitio']['valor'] ?? 1800);

$ultimaRevision = 0;
while (true) {
    // Análisis programados: como mucho una revisión por minuto. Un fallo aquí no para el worker.
    if (time() - $ultimaRevision >= 60) {
        $ultimaRevision = time();
        try {
            foreach ($fabrica->repositorio()->cerrarHuerfanos($margenHuerfanos) as $huerfano) {
                echo '[' . date('H:i:s') . "] Análisis {$huerfano}: cerrado (se quedó sin terminar)." . PHP_EOL;
            }
        } catch (Throwable $e) {
            error_log('Análisis sin terminar: ' . (string) $e);
        }
        try {
            foreach ($fabrica->programaciones()->lanzarPendientes() as $programado) {
                echo '[' . date('H:i:s') . "] Análisis {$programado}: en cola (programado)." . PHP_EOL;
            }
        } catch (Throwable $e) {
            error_log('Análisis programados: ' . (string) $e);
        }
    }

    // Con el interruptor general activado no se empieza nada nuevo (SEG-11).
    $id = $fabrica->interruptor()->activo() ? null : $fabrica->repositorio()->reservarSiguiente($maxSimultaneos);

    if ($id === null) {
        if ($unaVez) {
            break;
        }
        sleep(2);
        continue;
    }

    echo '[' . date('H:i:s') . "] Análisis {$id}: empieza." . PHP_EOL;
    // Un fallo en un análisis no debe parar el worker: se registra, se cierra ese análisis (si
    // todavía existe) y se sigue con los demás.
    try {
        $estado = $fabrica->orquestador()->ejecutar($id);
        echo '[' . date('H:i:s') . "] Análisis {$id}: {$estado->etiqueta()}." . PHP_EOL;
    } catch (Throwable $e) {
        error_log("Análisis {$id}: " . (string) $e);
        echo '[' . date('H:i:s') . "] Análisis {$id}: detenido por un error inesperado (detalle en el registro de errores)." . PHP_EOL;
        try {
            $fabrica->repositorio()->cerrarPorError($id, 'El análisis se detuvo por un error inesperado. Se conservan los resultados obtenidos.');
        } catch (Throwable) {
            // Si ni siquiera se puede cerrar (p. ej. ya no existe), lo recogerá cerrarHuerfanos().
        }
        continue;
    }

    try {
        foreach ($fabrica->avisos()->trasAnalisis($id) as $aviso) {
            echo '[' . date('H:i:s') . "] Aviso {$aviso} creado." . PHP_EOL;
        }
    } catch (Throwable $e) {
        error_log("Avisos del análisis {$id}: " . (string) $e);
    }
}
