<?php

declare(strict_types=1);

/**
 * Aplica las migraciones pendientes de db/migrations/ en orden y registra el perfil de ejecución.
 *
 *   php bin/migrar.php          aplica migraciones
 *   php bin/migrar.php --demo   además carga los datos de demostración (db/seeds/demo.sql)
 */

use ProconSEO\Shared\BaseDatos;
use ProconSEO\Shared\Config;

require dirname(__DIR__) . '/vendor/autoload.php';

$raiz = dirname(__DIR__);
Config::cargar($raiz);
$bd = BaseDatos::conexion();

$bd->exec('CREATE TABLE IF NOT EXISTS migraciones (
    fichero VARCHAR(255) PRIMARY KEY,
    aplicada_en DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$aplicadas = $bd->query('SELECT fichero FROM migraciones')->fetchAll(PDO::FETCH_COLUMN);
$ficheros = glob($raiz . '/db/migrations/*.sql') ?: [];
sort($ficheros);

foreach ($ficheros as $ruta) {
    $nombre = basename($ruta);
    if (in_array($nombre, $aplicadas, true)) {
        continue;
    }
    echo "Aplicando {$nombre}…\n";
    ejecutarSql($bd, (string) file_get_contents($ruta));
    $bd->prepare('INSERT INTO migraciones (fichero) VALUES (?)')->execute([$nombre]);
}

// Registra los perfiles de config/perfiles/ que aún no estén en la base de datos.
foreach (glob($raiz . '/config/perfiles/*.json') ?: [] as $rutaPerfil) {
    $json = (string) file_get_contents($rutaPerfil);
    $perfil = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
    $bd->prepare('INSERT IGNORE INTO perfiles_ejecucion (version, parametros) VALUES (?, ?)')
        ->execute([$perfil['version'], $json]);
}

if (in_array('--demo', $argv, true)) {
    echo "Cargando datos de demostración…\n";
    ejecutarSql($bd, (string) file_get_contents($raiz . '/db/seeds/demo.sql'));
}

echo "Base de datos al día.\n";

/** Ejecuta un fichero SQL sentencia a sentencia (separadas por ";" al final de línea). */
function ejecutarSql(PDO $bd, string $sql): void
{
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    foreach (preg_split('/;\s*$/m', $sql) ?: [] as $sentencia) {
        if (trim($sentencia) !== '') {
            $bd->exec($sentencia);
        }
    }
}
