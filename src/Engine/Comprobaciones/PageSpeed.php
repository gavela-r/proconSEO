<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Comprobaciones;

use ProconSEO\Engine\Causas;
use ProconSEO\Engine\Comprobacion;
use ProconSEO\Engine\Entorno;
use ProconSEO\Engine\NoVerificable;
use ProconSEO\Shared\Contract\BorradorResultado;
use ProconSEO\Shared\Contract\InformePageSpeed;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\Gravedad;
use ProconSEO\Shared\Enum\TipoConclusion;
use ProconSEO\Shared\Enum\TipoEvidencia;
use RuntimeException;

/**
 * SEO-11 (Core Web Vitals) y ACC-05.contraste-texto a partir de los informes de Google
 * PageSpeed Insights que trae la Parte 1 en ContextoAnalisis (especificación 6.4).
 *
 * - Un resultado por subcomprobación, con móvil y ordenador juntos: el estado es el del peor
 *   dispositivo y el valor muestra los dos.
 * - Campo (visitas reales) y laboratorio (simulación) son subcomprobaciones distintas: nunca se
 *   mezclan.
 * - Los datos de campo del origen (todo el sitio, no esta URL) se dicen y no pasan de mejorable.
 * - Sin datos de campo no se afirma nada: no demuestra lentitud.
 * - Nunca se usa la puntuación de Lighthouse.
 */
final class PageSpeed implements Comprobacion
{
    /** Umbrales de la especificación 6.4 (y de catalog/v1/criterios/SEO-11.json): bueno hasta, mejorable hasta. */
    public const UMBRALES = [
        'lcp_ms' => [2500, 4000],
        'inp_ms' => [200, 500],
        'cls' => [0.1, 0.25],
    ];

    private const NOMBRES = [
        'lcp_ms' => 'LCP (aparición del contenido principal)',
        'inp_ms' => 'INP (respuesta a la interacción)',
        'cls' => 'CLS (estabilidad visual)',
    ];

    private const DISPOSITIVOS = [InformePageSpeed::MOVIL => 'Móvil', InformePageSpeed::ORDENADOR => 'Ordenador'];

    private const BUENO = 0;
    private const MEJORABLE = 1;
    private const DEFICIENTE = 2;
    private const BANDAS = ['bueno', 'necesita mejora', 'deficiente'];

    private const MAXIMO_FALLOS_CONTRASTE = 20;

    public function subcomprobaciones(): array
    {
        return [
            'SEO-11.lcp-campo' => Cobertura::Implementada,
            'SEO-11.inp-campo' => Cobertura::Implementada,
            'SEO-11.cls-campo' => Cobertura::Implementada,
            'SEO-11.lcp-laboratorio' => Cobertura::Implementada,
            'SEO-11.cls-laboratorio' => Cobertura::Implementada,
            // Lighthouse solo revisa el texto visible al cargar: ni estados (hover, foco) ni texto sobre imágenes.
            'ACC-05.contraste-texto' => Cobertura::ParcialmenteImplementada,
        ];
    }

    public function evaluar(string $id, Entorno $entorno): BorradorResultado
    {
        return match ($id) {
            'SEO-11.lcp-campo' => $this->metrica($entorno, $id, 'lcp_ms', true),
            'SEO-11.inp-campo' => $this->metrica($entorno, $id, 'inp_ms', true),
            'SEO-11.cls-campo' => $this->metrica($entorno, $id, 'cls', true),
            'SEO-11.lcp-laboratorio' => $this->metrica($entorno, $id, 'lcp_ms', false),
            'SEO-11.cls-laboratorio' => $this->metrica($entorno, $id, 'cls', false),
            'ACC-05.contraste-texto' => $this->contraste($entorno),
            default => throw new RuntimeException("Subcomprobación desconocida: $id"),
        };
    }

    /** Banda de la especificación 6.4 para un valor. */
    public static function banda(string $metrica, float $valor): int
    {
        [$bueno, $mejorable] = self::UMBRALES[$metrica] ?? throw new RuntimeException("Métrica desconocida: $metrica");
        return $valor <= $bueno ? self::BUENO : ($valor <= $mejorable ? self::MEJORABLE : self::DEFICIENTE);
    }

    public static function formatear(string $metrica, float $valor): string
    {
        return match ($metrica) {
            'lcp_ms' => number_format($valor / 1000.0, 1, ',', '.') . ' s',
            'inp_ms', 'tbt_ms', 'fcp_ms', 'si_ms' => number_format($valor, 0, ',', '.') . ' ms',
            'cls' => number_format($valor, 2, ',', '.'),
            default => (string) $valor,
        };
    }

    /**
     * Informes disponibles por dispositivo. Lanza NoVerificable si no hay ninguno.
     *
     * @return array<string, InformePageSpeed>
     */
    private static function informes(Entorno $e): array
    {
        $disponibles = [];
        $errores = [];
        foreach (array_keys(self::DISPOSITIVOS) as $d) {
            $informe = $e->contexto->pageSpeed($d);
            if ($informe === null) {
                continue;
            }
            if ($informe->disponible()) {
                $disponibles[$d] = $informe;
            } else {
                $errores[] = self::DISPOSITIVOS[$d] . ': ' . self::texto((string) $informe->error, 200);
            }
        }
        if ($disponibles !== []) {
            return $disponibles;
        }
        if ($errores === []) {
            throw new NoVerificable('No se pidieron datos a Google PageSpeed Insights para esta página: en un sitio completo se miden la primera y unas páginas de muestra (una por zona de la web), porque medirlas todas tardaría horas.', Causas::PAGESPEED_SIN_DATOS);
        }
        throw new NoVerificable('Google PageSpeed Insights no devolvió datos (' . implode('; ', $errores) . ').', Causas::PAGESPEED_SIN_DATOS);
    }

    private function metrica(Entorno $e, string $id, string $metrica, bool $campo): BorradorResultado
    {
        $informes = self::informes($e);
        $v = $e->resultado($id);
        $nombre = self::NOMBRES[$metrica];

        $medidas = [];
        $sinDatos = [];
        $soloOrigen = [];
        $peor = null;
        $peorDispositivo = null;
        $peorOrigen = false;
        foreach (self::DISPOSITIVOS as $d => $etiqueta) {
            $informe = $informes[$d] ?? null;
            $datos = $informe === null ? [] : ($campo ? $informe->campo : $informe->laboratorio);
            if ($informe === null || !isset($datos[$metrica]) || !is_finite((float) $datos[$metrica]) || (float) $datos[$metrica] < 0) {
                $sinDatos[] = $etiqueta;
                continue;
            }
            $valor = (float) $datos[$metrica];
            $banda = self::banda($metrica, $valor);
            $origen = $campo && $informe->ambitoCampo === InformePageSpeed::CAMPO_ORIGEN;
            if ($origen) {
                $soloOrigen[] = $etiqueta;
            }
            $medidas[] = $etiqueta . ': ' . self::formatear($metrica, $valor) . ' (' . self::BANDAS[$banda] . ')' . ($origen ? ', datos del sitio entero' : '');
            // Ante un empate, cuenta antes el móvil: Google indexa la versión móvil.
            if ($peor === null || $banda > $peor) {
                [$peor, $peorDispositivo, $peorOrigen] = [$banda, $d, $origen];
            }
        }

        $tipoDato = $campo ? 'datos de campo: percentil 75 de visitas reales de los últimos 28 días' : 'datos de laboratorio: una simulación de Google, no visitas reales';
        $fecha = self::fecha($informes);
        $v->fuente(InformePageSpeed::FUENTE . ($fecha !== null ? ', ' . $fecha : '') . ' (' . ($campo ? 'datos de campo' : 'datos de laboratorio') . ')')
            ->url(reset($informes)->url);

        if ($medidas === []) {
            if ($campo) {
                throw new NoVerificable("Google no tiene suficientes visitas reales de esta página para medir $nombre. No demuestra que la web sea lenta.");
            }
            throw new NoVerificable("La simulación de PageSpeed Insights no midió $nombre.");
        }

        $limitaciones = ['Son ' . $tipoDato . '.'];
        if ($sinDatos !== []) {
            $limitaciones[] = 'Sin datos para: ' . implode(', ', $sinDatos) . '.';
        }
        if ($soloOrigen !== []) {
            $limitaciones[] = 'En ' . implode(' y ', array_map('mb_strtolower', $soloOrigen)) . ' Google solo tiene datos del sitio entero, no de esta URL.';
        }
        $umbral = self::UMBRALES[$metrica];
        $v->valor(implode(' · ', $medidas))
            ->evidencia($e->evidencia(
                TipoEvidencia::Medicion,
                "$nombre, percentil 75\n" . implode("\n", $medidas) . "\nUmbrales: bueno hasta " . self::formatear($metrica, $umbral[0]) . ', necesita mejora hasta ' . self::formatear($metrica, $umbral[1]) . "\nFuente: " . InformePageSpeed::FUENTE . ($fecha !== null ? ", $fecha" : '') . ', ' . ($campo ? 'campo' : 'laboratorio'),
                reset($informes)->url,
                reset($informes)->fecha,
            ))
            ->limitacion(implode(' ', $limitaciones));

        if ($peor === self::BUENO) {
            return $v->correcto();
        }

        $dispositivo = mb_strtolower(self::DISPOSITIVOS[(string) $peorDispositivo]);
        $v->consecuencia(self::consecuencia($metrica, $dispositivo))
            ->correccion(self::correccion($metrica))
            ->correccionCms(self::correccionCms($metrica));

        if ($peorOrigen) {
            // Medido sobre todo el sitio: apunta a la página, pero no lo demuestra.
            return $v->conclusion(TipoConclusion::Inferencia)->mejorable();
        }
        if ($peor === self::MEJORABLE) {
            return $v->mejorable();
        }
        $movil = $peorDispositivo === InformePageSpeed::MOVIL;
        if ($campo) {
            return $v->problema($movil ? Gravedad::Alto : Gravedad::Medio);
        }
        // En laboratorio es una simulación: indica un riesgo, menos grave que las visitas reales.
        return $v->problema($movil ? Gravedad::Medio : Gravedad::Bajo);
    }

    private function contraste(Entorno $e): BorradorResultado
    {
        $informes = self::informes($e);
        // El contraste no depende del dispositivo: se usa el móvil si está, y si no el de ordenador.
        $informe = null;
        foreach ($informes as $i) {
            if ($i->contrasteCorrecto !== null) {
                $informe = $i;
                break;
            }
        }
        if ($informe === null) {
            throw new NoVerificable('PageSpeed Insights no pudo evaluar el contraste del texto de esta página.');
        }

        $fecha = $informe->fecha?->format('Y-m-d');
        $v = $e->resultado('ACC-05.contraste-texto')
            ->url($informe->url)
            ->fuente(InformePageSpeed::FUENTE . ($fecha !== null ? ', ' . $fecha : '') . ' (auditoría de contraste, ' . mb_strtolower(self::DISPOSITIVOS[$informe->dispositivo] ?? $informe->dispositivo) . ')')
            ->limitacion('La auditoría automática solo revisa el texto visible al cargar la página: no el texto sobre imágenes, ni los estados al pasar el ratón o con el foco del teclado.');

        if ($informe->contrasteCorrecto) {
            return $v->valor('Sin textos con contraste insuficiente.')->correcto();
        }

        $fallos = array_slice($informe->fallosContraste, 0, self::MAXIMO_FALLOS_CONTRASTE);
        $lineas = array_map(
            static fn (array $f): string => self::texto((string) ($f['selector'] ?? ''), 120) . ' — ' . self::texto((string) ($f['fragmento'] ?? ''), 200)
                . (($f['explicacion'] ?? '') !== '' ? ' — ' . self::texto((string) $f['explicacion'], 200) : ''),
            $fallos,
        );
        $v->valor(count($informe->fallosContraste) . ' elemento(s) con contraste insuficiente.');
        if ($lineas !== []) {
            // Los fragmentos son HTML de la web analizada: van como dato en la evidencia, nunca se interpretan.
            $v->elemento(self::texto((string) ($fallos[0]['selector'] ?? ''), 120))
                ->evidencia($e->evidencia(TipoEvidencia::FragmentoHtml, implode("\n", $lineas), $informe->url, $informe->fecha));
        }
        return $v
            ->consecuencia('Quien ve mal, usa el móvil a pleno sol o tiene una pantalla de poca calidad no puede leer bien esos textos.')
            ->correccion('Oscurecer el texto o aclarar el fondo hasta llegar a un contraste de 4,5:1 (3:1 en textos grandes). Suele bastar con ajustar un color del tema.')
            ->correccionCms([
                'wordpress' => 'Apariencia > Personalizar (o Editor del sitio > Estilos) y cambiar los colores de texto y de fondo del tema.',
                'prestashop' => 'Diseño > Tema y logotipo > Personalizar, o el módulo de colores del tema.',
            ])
            ->problema(Gravedad::Medio);
    }

    /** @param array<string, InformePageSpeed> $informes */
    private static function fecha(array $informes): ?string
    {
        foreach ($informes as $i) {
            if ($i->fecha !== null) {
                return $i->fecha->format('Y-m-d');
            }
        }
        return null;
    }

    private static function consecuencia(string $metrica, string $dispositivo): string
    {
        return match ($metrica) {
            'lcp_ms' => "En $dispositivo, el contenido principal tarda en aparecer: muchas personas se van antes, y Google lo tiene en cuenta al ordenar los resultados.",
            'inp_ms' => "En $dispositivo, la página tarda en responder al tocar un botón o un enlace: parece que no funciona.",
            'cls' => "En $dispositivo, los elementos se mueven mientras carga la página: es fácil pulsar donde no se quería.",
            default => '',
        };
    }

    private static function correccion(string $metrica): string
    {
        return match ($metrica) {
            'lcp_ms' => 'Reducir el peso de la imagen o el bloque principal (formatos WebP o AVIF, tamaño adecuado), no cargarla en diferido y mejorar el tiempo de respuesta del servidor.',
            'inp_ms' => 'Reducir el JavaScript que se ejecuta al interactuar (plugins, chats, analítica) y dividir las tareas largas.',
            'cls' => 'Reservar el espacio de imágenes, anuncios y banners (width y height) y no insertar contenido por encima del que ya se ve.',
            default => '',
        };
    }

    /** @return array<string, string> */
    private static function correccionCms(string $metrica): array
    {
        return match ($metrica) {
            'lcp_ms' => [
                'wordpress' => 'Usar un plugin de caché y de optimización de imágenes, y excluir la imagen principal de la carga diferida.',
                'prestashop' => 'Activar la caché y la compresión en Parámetros avanzados > Rendimiento y optimizar las imágenes del tema.',
            ],
            'inp_ms' => [
                'wordpress' => 'Revisar los plugins que cargan JavaScript en todas las páginas y desactivar los que no hagan falta.',
                'prestashop' => 'Revisar los módulos que añaden JavaScript y desactivar los que no hagan falta.',
            ],
            'cls' => [
                'wordpress' => 'Revisar el banner de cookies y los bloques que se cargan después; reservarles espacio en el tema.',
            ],
            default => [],
        };
    }

    /** Texto de una línea y corto: viene de Google o de la web analizada y solo se muestra. */
    private static function texto(string $texto, int $largo): string
    {
        $t = trim((string) preg_replace('/[\x00-\x1F\x7F]+|\s+/u', ' ', $texto));
        return mb_strlen($t) > $largo ? mb_substr($t, 0, $largo - 1) . '…' : $t;
    }
}
