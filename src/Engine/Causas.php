<?php

declare(strict_types=1);

namespace ProconSEO\Engine;

use ProconSEO\Shared\Enum\EstadoResultado;

/**
 * Causas que comparten varios resultados (especificación 4.4, RF-21 y RF-24).
 *
 * Una misma causa (p. ej. «las imágenes no tienen texto alternativo») suele aparecer en varios
 * bloques a la vez: SEO, IA y accesibilidad. El informe la muestra una sola vez, con sus efectos
 * en cada bloque y una única recomendación.
 *
 * Un resultado entra en una causa de dos maneras:
 * - la comprobación la declara al valorar (Valoracion::causa) o al no poder ejecutarse
 *   (NoVerificable con causa), cuando sabe exactamente qué la ha provocado;
 * - si no declara ninguna, por las REGLAS de esta clase: una lista de subcomprobaciones que,
 *   cuando son incidencia, se deben a lo mismo.
 *
 * El código y la descripción son fijos: nunca salen del contenido de la web.
 */
final class Causas
{
    public const PAGINA_NO_RECUPERADA = 'pagina-no-recuperada';
    public const HTML_ILEGIBLE = 'html-ilegible';
    public const CONTENIDO_JAVASCRIPT = 'contenido-javascript';
    public const ALCANCE_INSUFICIENTE = 'alcance-insuficiente';
    public const LIMITE_URLS = 'limite-urls';
    public const ROBOTS_ERROR_TEMPORAL = 'robots-error-temporal';
    public const ROBOTS_NO_CONSULTABLE = 'robots-no-consultable';
    public const ROBOTS_BLOQUEA = 'robots-bloquea';
    public const DIRECTIVAS_NOINDEX = 'directivas-noindex';
    public const TITLE_AUSENTE = 'title-ausente';
    public const IMAGENES_SIN_ALT = 'imagenes-sin-alt';
    public const ENLACES_SIN_NOMBRE = 'enlaces-sin-nombre';
    public const TEXTOS_ENLACE_AMBIGUOS = 'textos-enlace-ambiguos';
    public const CONTROLES_SIN_NOMBRE = 'controles-sin-nombre';
    public const DATOS_ESTRUCTURADOS_INVALIDOS = 'datos-estructurados-invalidos';
    public const CANONICAL = 'canonical';
    public const PAGESPEED_SIN_DATOS = 'pagespeed-sin-datos';

    /** Qué pasa y qué hacer, en lenguaje sencillo. Se muestra una vez por causa. */
    public const DESCRIPCIONES = [
        self::PAGINA_NO_RECUPERADA => 'No se pudo recuperar la página que se pidió analizar (error del servidor, bloqueo o redirección fuera del ámbito). Todo lo que depende de su contenido queda sin comprobar. Hay que resolver primero el acceso y repetir el análisis.',
        self::HTML_ILEGIBLE => 'La página se descargó, pero su HTML no se pudo leer (demasiado grande, vacío o dañado). Lo que depende del HTML queda sin comprobar.',
        self::CONTENIDO_JAVASCRIPT => 'La página carga casi todo su contenido con JavaScript. Sin navegador no se ve lo que aparece después, así que no se puede afirmar que falte nada. Servir el contenido principal en el HTML inicial ayuda a buscadores, asistentes con IA y lectores de pantalla.',
        self::ALCANCE_INSUFICIENTE => 'Estas comprobaciones necesitan consultar otras URLs del sitio y el análisis se pidió solo para una URL. Para verlas, repetir el análisis con el alcance «URL con contexto».',
        self::LIMITE_URLS => 'Se alcanzó el límite de URLs adicionales del perfil de ejecución; las comprobaciones que necesitaban más URLs quedan sin terminar.',
        self::ROBOTS_ERROR_TEMPORAL => 'robots.txt responde con un error temporal (429 o 5xx). Mientras dure, los buscadores pueden dejar de rastrear el sitio y no se puede saber qué permite. Hay que hacer que /robots.txt responda 200 (o 404 si no se quiere ninguno).',
        self::ROBOTS_NO_CONSULTABLE => 'No se pudo consultar robots.txt, así que no se sabe qué robots tienen permiso para leer la página.',
        self::ROBOTS_BLOQUEA => 'robots.txt impide a buscadores leer la página. Si no es intencionado, hay que quitar o acotar la regla Disallow que la afecta; con una sola corrección se resuelven todos estos resultados.',
        self::DIRECTIVAS_NOINDEX => 'La página pide a los buscadores que no la indexen o que no la muestren (meta robots o cabecera X-Robots-Tag). Si no es intencionado, hay que quitar esa directiva.',
        self::TITLE_AUSENTE => 'La página no tiene título (<title>). Afecta a cómo aparece en los buscadores y a las personas que usan lector de pantalla. Hay que escribir un título que diga de qué trata la página.',
        self::IMAGENES_SIN_ALT => 'Hay imágenes sin texto alternativo. Los buscadores, los asistentes con IA y los lectores de pantalla no saben qué muestran. Hay que darles un alt que lo describa (o alt="" si son decorativas).',
        self::ENLACES_SIN_NOMBRE => 'Hay enlaces sin ningún texto (por ejemplo, una imagen sin alt dentro de un enlace). Nadie que no los vea sabe adónde llevan. Hay que darles un texto o un alt a la imagen.',
        self::TEXTOS_ENLACE_AMBIGUOS => 'Hay enlaces con textos que no dicen adónde llevan («pincha aquí», «leer más»). Hay que cambiarlos por un texto que describa el destino.',
        self::CONTROLES_SIN_NOMBRE => 'Hay botones u otros controles sin nombre. Ni un asistente con IA ni un lector de pantalla pueden saber qué hacen. Hay que darles un texto visible o un aria-label con la acción.',
        self::DATOS_ESTRUCTURADOS_INVALIDOS => 'Los datos estructurados (JSON-LD) tienen errores de sintaxis, así que buscadores y asistentes con IA los ignoran. Hay que corregir el JSON-LD.',
        self::CANONICAL => 'La URL canónica falta o no coincide con la página. Hay que declarar en cada página un canonical a su propia URL definitiva.',
        self::PAGESPEED_SIN_DATOS => 'No hay datos de Google PageSpeed Insights para esta página (no se pidieron o Google no los devolvió), así que no se pueden valorar la velocidad ni el contraste del texto. No dice nada malo de la web.',
    ];

    private const INCIDENCIAS = [EstadoResultado::Problema, EstadoResultado::Mejorable, EstadoResultado::DecisionRevisar];

    /**
     * Subcomprobaciones que, cuando son incidencia y no han declarado otra causa, comparten la
     * causa indicada. Si «sin_pagina» es true, la regla solo se aplica cuando la página no se
     * pudo recuperar (también cuenta no_verificable).
     *
     * @var array<string, array{miembros: list<string>, sin_pagina?: bool}>
     */
    public const REGLAS = [
        self::PAGINA_NO_RECUPERADA => [
            'miembros' => ['IA-03.respuesta-http', 'IA-03.bloqueo-acceso', 'SEO-01.estado-http', 'SEO-02.url-final', 'SEO-02.cadena-redirecciones'],
            'sin_pagina' => true,
        ],
        self::ROBOTS_BLOQUEA => ['miembros' => ['IA-01.permiso-buscadores', 'IA-01.permiso-buscadores-ia', 'SEO-01.rastreable']],
        self::DIRECTIVAS_NOINDEX => ['miembros' => ['IA-02.meta-robots', 'IA-02.x-robots-tag', 'SEO-01.indexable']],
        self::TITLE_AUSENTE => ['miembros' => ['SEO-03.title-presente', 'ACC-03.title-pagina']],
        self::IMAGENES_SIN_ALT => ['miembros' => ['SEO-12.imagenes-alt', 'ACC-01.img-alt-presente', 'ACC-01.input-image-alt', 'IA-10.contexto-imagenes']],
        self::ENLACES_SIN_NOMBRE => ['miembros' => ['ACC-06.enlaces-nombre', 'ACC-01.img-enlace-sin-nombre']],
        self::TEXTOS_ENLACE_AMBIGUOS => ['miembros' => ['SEO-07.textos-ambiguos', 'ACC-06.enlaces-proposito']],
        self::CONTROLES_SIN_NOMBRE => ['miembros' => ['IA-11.acciones-nombre-rol', 'ACC-12.nombres-controles']],
        self::DATOS_ESTRUCTURADOS_INVALIDOS => ['miembros' => ['IA-07.jsonld-sintaxis', 'SEO-14.elegibilidad-enriquecidos']],
        self::CANONICAL => ['miembros' => ['IA-08.canonical-declarado', 'SEO-02.canonical-coherente']],
    ];

    public static function existe(string $codigo): bool
    {
        return isset(self::DESCRIPCIONES[$codigo]);
    }

    public static function descripcion(string $codigo): string
    {
        return self::DESCRIPCIONES[$codigo] ?? throw new \InvalidArgumentException("Causa desconocida: $codigo");
    }

    /** Causa que la regla asigna a un resultado, o null. */
    public static function porRegla(string $idComprobacion, EstadoResultado $estado, bool $paginaDisponible): ?string
    {
        foreach (self::REGLAS as $codigo => $regla) {
            if (!in_array($idComprobacion, $regla['miembros'], true)) {
                continue;
            }
            if ($regla['sin_pagina'] ?? false) {
                if (!$paginaDisponible && $estado !== EstadoResultado::Correcto && $estado !== EstadoResultado::NoAplicable) {
                    return $codigo;
                }
                continue;
            }
            if (in_array($estado, self::INCIDENCIAS, true)) {
                return $codigo;
            }
        }
        return null;
    }
}
