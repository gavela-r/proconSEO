<?php

declare(strict_types=1);

/**
 * Comprueba que la instalación tiene todo lo necesario. Pensado para sistemas, al instalar
 * y después de cada actualización. No enseña contraseñas ni claves.
 *
 *   php bin/comprobar.php               comprobación normal
 *   php bin/comprobar.php --produccion  además, los ajustes de producción (APP_ENV, php.ini)
 *   php bin/comprobar.php --red         además, una petición https real a example.com
 *
 * Termina con código 1 si hay algún error, para usarlo en scripts de despliegue.
 */

use ProconSEO\Run\ComprobacionInstalacion;
use ProconSEO\Shared\Config;

require dirname(__DIR__) . '/vendor/autoload.php';

$raiz = dirname(__DIR__);
Config::cargar($raiz);

$lineas = (new ComprobacionInstalacion($raiz, in_array('--produccion', $argv, true), in_array('--red', $argv, true)))->ejecutar();

$marcas = [ComprobacionInstalacion::OK => '[ OK ]', ComprobacionInstalacion::AVISO => '[AVISO]', ComprobacionInstalacion::ERROR => '[ERROR]'];
foreach ($lineas as $l) {
    echo $marcas[$l['nivel']] . ' ' . $l['texto'] . PHP_EOL;
}

$errores = count(array_filter($lineas, static fn (array $l): bool => $l['nivel'] === ComprobacionInstalacion::ERROR));
$avisos = count(array_filter($lineas, static fn (array $l): bool => $l['nivel'] === ComprobacionInstalacion::AVISO));
echo PHP_EOL . ($errores === 0
    ? 'Todo listo' . ($avisos > 0 ? " (" . ($avisos === 1 ? '1 aviso' : "{$avisos} avisos") . ": funciona, pero conviene revisar" . ($avisos === 1 ? 'lo' : 'los') . ")." : '.')
    : ($errores === 1 ? 'Hay 1 error: la herramienta no funcionará bien hasta arreglarlo.' : "Hay {$errores} errores: la herramienta no funcionará bien hasta arreglarlos.")) . PHP_EOL;

exit($errores === 0 ? 0 : 1);
