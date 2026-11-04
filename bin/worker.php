<?php

declare(strict_types=1);

/**
 * Worker: coge análisis en cola y los ejecuta, respetando el máximo de análisis simultáneos
 * del perfil. Se puede lanzar varias veces en paralelo: cada análisis lo reserva uno solo.
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

while (true) {
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
    $estado = $fabrica->orquestador()->ejecutar($id);
    echo '[' . date('H:i:s') . "] Análisis {$id}: {$estado->etiqueta()}." . PHP_EOL;
}
