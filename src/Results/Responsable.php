<?php

declare(strict_types=1);

namespace ProconSEO\Results;

/**
 * Quién suele arreglar cada criterio (docs/diseno/analisis-herramientas.md, mejora 6; como el filtro
 * por responsabilidad de Siteimprove). Sirve para pasarle a cada persona solo lo suyo.
 *
 * Es una orientación por criterio, no una regla: una decisión a revisar es siempre del cliente.
 * Cuando el catálogo tenga este dato (Parte 2), esta tabla se sustituye por el suyo.
 */
final class Responsable
{
    public const CONTENIDO = 'contenido';
    public const DESARROLLO = 'desarrollo';
    public const DISENO = 'diseno';
    public const CLIENTE = 'cliente';

    public const ETIQUETAS = [
        self::CONTENIDO => 'Contenido',
        self::DESARROLLO => 'Desarrollo',
        self::DISENO => 'Diseño',
        self::CLIENTE => 'Decisión del cliente',
    ];

    private const POR_CRITERIO = [
        'IA-01' => self::DESARROLLO, 'IA-02' => self::DESARROLLO, 'IA-03' => self::DESARROLLO,
        'IA-04' => self::CONTENIDO, 'IA-05' => self::CONTENIDO, 'IA-06' => self::CONTENIDO,
        'IA-07' => self::DESARROLLO, 'IA-08' => self::DESARROLLO, 'IA-09' => self::CONTENIDO,
        'IA-10' => self::CONTENIDO, 'IA-11' => self::DESARROLLO,
        'SEO-01' => self::DESARROLLO, 'SEO-02' => self::DESARROLLO, 'SEO-03' => self::CONTENIDO,
        'SEO-04' => self::CONTENIDO, 'SEO-05' => self::CONTENIDO, 'SEO-06' => self::CONTENIDO,
        'SEO-07' => self::CONTENIDO, 'SEO-08' => self::CONTENIDO, 'SEO-09' => self::DESARROLLO,
        'SEO-10' => self::DESARROLLO, 'SEO-11' => self::DESARROLLO, 'SEO-12' => self::CONTENIDO,
        'SEO-13' => self::DISENO, 'SEO-14' => self::DESARROLLO, 'SEO-15' => self::DESARROLLO,
        'SEO-16' => self::DESARROLLO,
        'ACC-01' => self::CONTENIDO, 'ACC-02' => self::CONTENIDO, 'ACC-03' => self::DESARROLLO,
        'ACC-04' => self::DISENO, 'ACC-05' => self::DISENO, 'ACC-06' => self::DESARROLLO,
        'ACC-07' => self::DISENO, 'ACC-08' => self::DISENO, 'ACC-09' => self::DESARROLLO,
        'ACC-10' => self::DESARROLLO, 'ACC-11' => self::DISENO, 'ACC-12' => self::DESARROLLO,
    ];

    /** @param array<string, mixed> $resultado */
    public static function de(array $resultado): string
    {
        if (($resultado['estado_vigente'] ?? $resultado['estado'] ?? null) === 'decision_revisar') {
            return self::CLIENTE;
        }

        return self::POR_CRITERIO[(string) ($resultado['id_criterio'] ?? '')] ?? self::DESARROLLO;
    }

    /** @param array<string, mixed> $resultado */
    public static function etiqueta(array $resultado): string
    {
        return self::ETIQUETAS[self::de($resultado)];
    }
}
