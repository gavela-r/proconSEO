<?php

declare(strict_types=1);

/**
 * Interruptor general (SEG-11): detiene todos los análisis y no deja lanzar más.
 * Solo desde el servidor, no desde la web.
 *
 *   php bin/interruptor.php activar "motivo"
 *   php bin/interruptor.php desactivar
 *   php bin/interruptor.php estado
 */

use ProconSEO\Run\Fabrica;
use ProconSEO\Shared\Config;

require dirname(__DIR__) . '/vendor/autoload.php';

Config::cargar(dirname(__DIR__));
$interruptor = (new Fabrica())->interruptor();
$quien = 'línea de órdenes (' . get_current_user() . ')';

switch ($argv[1] ?? 'estado') {
    case 'activar':
        $detenidos = $interruptor->activar($argv[2] ?? '', $quien);
        echo "Interruptor ACTIVADO. Análisis detenidos: {$detenidos}. No se pueden lanzar nuevos." . PHP_EOL;
        break;
    case 'desactivar':
        $interruptor->desactivar($quien);
        echo 'Interruptor desactivado. Ya se pueden lanzar análisis.' . PHP_EOL;
        break;
    case 'estado':
        $motivo = $interruptor->motivo();
        echo ($motivo === null ? 'Desactivado: los análisis funcionan con normalidad.' : "ACTIVADO: {$motivo}") . PHP_EOL;
        break;
    default:
        fwrite(STDERR, "Uso: php bin/interruptor.php activar \"motivo\" | desactivar | estado\n");
        exit(1);
}
