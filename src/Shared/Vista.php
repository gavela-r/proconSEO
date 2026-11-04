<?php

declare(strict_types=1);

namespace ProconSEO\Shared;

/**
 * Renderiza plantillas PHP de templates/ dentro de la plantilla base (templates/layout/base.php).
 * Dentro de una plantilla, escapa SIEMPRE los datos con e().
 */
final class Vista
{
    private static string $directorio = '';

    public static function configurar(string $directorio): void
    {
        self::$directorio = rtrim($directorio, '/\\');
    }

    /**
     * @param array<string, mixed> $datos Variables disponibles en la plantilla
     */
    public static function render(string $plantilla, array $datos = [], bool $conLayout = true): string
    {
        $contenido = self::incluir($plantilla, $datos);
        if (!$conLayout) {
            return $contenido;
        }

        return self::incluir('layout/base', $datos + ['contenido' => $contenido]);
    }

    /**
     * @param array<string, mixed> $datos
     */
    private static function incluir(string $plantilla, array $datos): string
    {
        if (!preg_match('#^[a-z0-9_\-/]+$#', $plantilla) || str_contains($plantilla, '..')) {
            throw new \InvalidArgumentException('Nombre de plantilla no válido.');
        }

        $fichero = self::$directorio . '/' . $plantilla . '.php';
        if (!is_file($fichero)) {
            throw new \RuntimeException("No existe la plantilla {$plantilla}");
        }

        extract($datos, EXTR_SKIP);
        ob_start();
        require $fichero;

        return (string) ob_get_clean();
    }
}
