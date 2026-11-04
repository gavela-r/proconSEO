<?php

declare(strict_types=1);

namespace ProconSEO\Shared;

/**
 * Configuración leída de .env. Las variables de entorno reales tienen prioridad.
 */
final class Config
{
    /** @var array<string, string> */
    private static array $valores = [];
    private static string $raiz = '';

    public static function cargar(string $raiz): void
    {
        self::$raiz = $raiz;
        self::$valores = [];

        $fichero = $raiz . '/.env';
        if (!is_file($fichero)) {
            return;
        }

        foreach (file($fichero, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
            $linea = trim($linea);
            if ($linea === '' || str_starts_with($linea, '#') || !str_contains($linea, '=')) {
                continue;
            }
            [$clave, $valor] = array_map('trim', explode('=', $linea, 2));
            self::$valores[$clave] = trim($valor, "\"'");
        }
    }

    public static function obtener(string $clave, ?string $porDefecto = null): ?string
    {
        $entorno = getenv($clave);
        if ($entorno !== false) {
            return $entorno;
        }

        return self::$valores[$clave] ?? $porDefecto;
    }

    public static function obligatorio(string $clave): string
    {
        $valor = self::obtener($clave);
        if ($valor === null || $valor === '') {
            throw new \RuntimeException("Falta la variable de configuración {$clave} en .env");
        }

        return $valor;
    }

    public static function raiz(): string
    {
        return self::$raiz;
    }

    public static function esDesarrollo(): bool
    {
        return self::obtener('APP_ENV', 'local') === 'local';
    }
}
