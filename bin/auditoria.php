<?php

declare(strict_types=1);

/**
 * Muestra las últimas entradas del registro de auditoría (SEG-15).
 *
 *   php bin/auditoria.php        las 50 últimas
 *   php bin/auditoria.php 200    las 200 últimas
 */

use ProconSEO\Run\Fabrica;
use ProconSEO\Shared\Config;

require dirname(__DIR__) . '/vendor/autoload.php';

Config::cargar(dirname(__DIR__));

foreach (array_reverse((new Fabrica())->auditoria()->ultimas((int) ($argv[1] ?? 50))) as $fila) {
    echo implode(' | ', array_filter([
        $fila['creado_en'],
        $fila['accion'],
        $fila['regla'],
        $fila['ip_origen'] === null ? null : 'IP ' . $fila['ip_origen'],
        $fila['analisis_id'] === null ? null : 'análisis ' . $fila['analisis_id'],
        $fila['url'],
        $fila['detalle'],
    ], static fn ($v): bool => $v !== null && $v !== '')) . PHP_EOL;
}
