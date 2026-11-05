<?php

declare(strict_types=1);

namespace ProconSEO\Tests\Results;

/**
 * Filas de ejemplo con la forma que devuelve RepositorioResultados, para tests sin base de datos.
 */
final class Filas
{
    private static int $id = 1000;

    public static function resultado(array $campos = []): array
    {
        $estado = $campos['estado'] ?? 'correcto';

        return $campos + [
            'id' => ++self::$id,
            'analisis_id' => 1,
            'id_comprobacion' => 'SEO-03.title-presente',
            'id_criterio' => 'SEO-03',
            'bloque' => 'seo',
            'estado' => $estado,
            'estado_vigente' => $estado,
            'ejecucion' => 'ejecutada',
            'cobertura' => 'implementada',
            'tipo_conclusion' => 'hecho',
            'modo' => null,
            'url_recurso' => 'https://ejemplo.es/',
            'elemento' => null,
            'valor_observado' => null,
            'consecuencia' => null,
            'correccion' => null,
            'correccion_cms' => null,
            'explicacion_sencilla' => 'Explicación de prueba.',
            'limitacion' => null,
            'requiere_revision_humana' => false,
            'motivo_revision' => null,
            'wcag_criterio' => null,
            'wcag_nombre' => null,
            'wcag_nivel' => null,
            'wcag_version' => null,
            'gravedad' => null,
            'esfuerzo' => 'bajo',
            'grupo_causa_id' => null,
            'causa_codigo' => null,
            'causa_descripcion' => null,
            'bloquea_dependientes' => false,
            'fuente' => null,
            'version_regla' => 'v1',
            'es_dato_prueba' => false,
            'revision_pendiente' => false,
            'revisado' => false,
            'evidencias' => null,
        ];
    }

    public static function recurso(array $campos = []): array
    {
        return $campos + [
            'id' => ++self::$id,
            'url' => 'https://ejemplo.es/',
            'url_final' => 'https://ejemplo.es/',
            'estado_http' => 200,
            'tipo_contenido' => 'text/html',
            'bytes' => 1000,
            'truncado' => 0,
            'resultado' => 'correcto',
            'motivo' => null,
            'justificado_por' => null,
        ];
    }

    public static function analisis(array $campos = []): array
    {
        return $campos + [
            'id' => 1,
            'proyecto_id' => 2,
            'proyecto_nombre' => 'Proyecto',
            'proyecto_tipo' => 'web_kit_digital',
            'proyecto_sector' => null,
            'proyecto_es_dato_prueba' => 0,
            'url_introducida' => 'https://ejemplo.es',
            'url_normalizada' => 'https://ejemplo.es/',
            'url_final' => 'https://ejemplo.es/',
            'alcance' => 'url_individual',
            'objetivo' => null,
            'hosts_autorizados' => '["ejemplo.es"]',
            'perfil_version' => 'v1',
            'version_catalogo' => '1.0.0',
            'estado' => 'completado',
            'motivo_cierre' => null,
            'es_dato_prueba' => 0,
            'creado_en' => '2026-11-04 09:42:00',
            'iniciado_en' => '2026-11-04 09:42:01',
            'terminado_en' => '2026-11-04 09:43:00',
        ];
    }
}
