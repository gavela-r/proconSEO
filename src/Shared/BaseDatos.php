<?php

declare(strict_types=1);

namespace ProconSEO\Shared;

use PDO;

/**
 * Conexión única a MySQL. Usar siempre sentencias preparadas: nunca concatenar datos en el SQL.
 */
final class BaseDatos
{
    private static ?PDO $conexion = null;

    public static function conexion(): PDO
    {
        if (self::$conexion === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                Config::obtener('DB_HOST', '127.0.0.1'),
                Config::obtener('DB_PORT', '3306'),
                Config::obligatorio('DB_NAME'),
            );

            self::$conexion = new PDO($dsn, Config::obligatorio('DB_USER'), Config::obtener('DB_PASS', ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            self::$conexion->exec("SET time_zone = '+00:00'");
        }

        return self::$conexion;
    }

    /** Solo para tests: permite inyectar otra conexión. */
    public static function usar(?PDO $conexion): void
    {
        self::$conexion = $conexion;
    }
}
