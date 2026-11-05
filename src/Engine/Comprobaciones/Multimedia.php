<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Comprobaciones;

use DOMElement;
use DOMXPath;
use ProconSEO\Engine\Comprobacion;
use ProconSEO\Engine\Entorno;
use ProconSEO\Engine\Estructurados;
use ProconSEO\Engine\Extraccion\Url;
use ProconSEO\Shared\Contract\BorradorResultado;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\TipoConclusion;
use ProconSEO\Shared\Enum\TipoEvidencia;
use RuntimeException;

/**
 * SEO-12 (imágenes y vídeo) e IA-10 (contexto textual de imágenes, vídeos y horarios).
 *
 * Las subcomprobaciones de vídeo solo se activan si la página tiene vídeo: su ausencia nunca
 * es un problema (especificación 6.5). La accesibilidad del vídeo se valora en ACC-02.
 */
final class Multimedia implements Comprobacion
{
    private const PLATAFORMAS_VIDEO = [
        'youtube.com' => 'YouTube', 'youtube-nocookie.com' => 'YouTube', 'youtu.be' => 'YouTube',
        'vimeo.com' => 'Vimeo', 'player.vimeo.com' => 'Vimeo', 'dailymotion.com' => 'Dailymotion',
        'wistia.com' => 'Wistia', 'wistia.net' => 'Wistia',
    ];

    /** Formatos que Google indexa (Imágenes de Google). */
    private const FORMATOS = ['jpg' => 'JPEG', 'jpeg' => 'JPEG', 'png' => 'PNG', 'gif' => 'GIF', 'webp' => 'WebP',
        'avif' => 'AVIF', 'svg' => 'SVG', 'bmp' => 'BMP'];

    public function subcomprobaciones(): array
    {
        return [
            'SEO-12.imagenes-rastreables' => Cobertura::Implementada,
            'SEO-12.imagenes-alt' => Cobertura::Implementada,
            'SEO-12.imagenes-formato' => Cobertura::Implementada,
            'SEO-12.video-detectado' => Cobertura::Implementada,
            'SEO-12.video-miniatura' => Cobertura::ParcialmenteImplementada,
            'SEO-12.video-datos' => Cobertura::Implementada,
            'IA-10.contexto-imagenes' => Cobertura::ParcialmenteImplementada,
            'IA-10.contexto-video' => Cobertura::ParcialmenteImplementada,
            'IA-10.horarios-texto' => Cobertura::ParcialmenteImplementada,
        ];
    }

    public function evaluar(string $id, Entorno $e): BorradorResultado
    {
        return match ($id) {
            'SEO-12.imagenes-rastreables' => $this->rastreables($e),
            'SEO-12.imagenes-alt' => $this->alt($e),
            'SEO-12.imagenes-formato' => $this->formato($e),
            'SEO-12.video-detectado' => $this->videoDetectado($e),
            'SEO-12.video-miniatura' => $this->miniatura($e),
            'SEO-12.video-datos' => $this->videoDatos($e),
            'IA-10.contexto-imagenes' => $this->contextoImagenes($e),
            'IA-10.contexto-video' => $this->contextoVideo($e),
            'IA-10.horarios-texto' => $this->horarios($e),
            default => throw new RuntimeException("Subcomprobación desconocida: $id"),
        };
    }

    /**
     * Vídeos de la página: <video> e iframes de plataformas conocidas.
     *
     * @return list<array{tipo: string, url: ?string, poster: ?string, titulo: ?string}>
     */
    public static function videos(Entorno $e): array
    {
        $doc = $e->documento();
        $videos = [];
        foreach ($doc->medios as $m) {
            if ($m['tipo'] === 'video') {
                $videos[] = ['tipo' => 'video', 'url' => $m['url'], 'poster' => $m['poster'], 'titulo' => null];
            }
        }
        foreach ($doc->iframes as $f) {
            $plataforma = self::plataforma($f['url']);
            if ($plataforma !== null) {
                $videos[] = ['tipo' => $plataforma, 'url' => $f['url'], 'poster' => null, 'titulo' => $f['titulo']];
            }
        }
        return $videos;
    }

    private static function plataforma(?string $url): ?string
    {
        $host = $url === null ? null : Url::host($url);
        if ($host === null) {
            return null;
        }
        $host = (string) preg_replace('/^www\./', '', $host);
        return self::PLATAFORMAS_VIDEO[$host] ?? null;
    }

    /** @return list<array<string, mixed>> */
    private function imagenesDeContenido(Entorno $e): array
    {
        return array_values(array_filter(
            $e->documento()->imagenes,
            static fn (array $i): bool => $i['alt'] !== '' && !in_array($i['role'], ['presentation', 'none'], true),
        ));
    }

    private function rastreables(Entorno $e): BorradorResultado
    {
        $doc = $e->documento();
        $v = $e->resultado('SEO-12.imagenes-rastreables');
        if ($doc->imagenes === []) {
            return $v->noAplicable('La página no tiene imágenes <img>.');
        }
        $xp = new DOMXPath($doc->dom);
        $perezosas = [];
        foreach ($xp->query('//img[not(@src) or @src=""][@data-src or @data-lazy-src or @data-original]') ?: [] as $img) {
            if ($img instanceof DOMElement) {
                $perezosas[] = $img->getAttribute('data-src') ?: $img->getAttribute('data-lazy-src') ?: $img->getAttribute('data-original');
            }
        }
        $v->limitacion('No se detectan imágenes puestas como fondo con CSS, que Google no indexa.');
        if ($perezosas === []) {
            return $v->valor(count($doc->imagenes) . ' imágenes con src rastreable.')->correcto();
        }
        return $v->valor(count($perezosas) . ' imagen(es) sin src: solo se cargan con JavaScript (data-src).')
            ->elemento('<img data-src="' . $perezosas[0] . '">')
            ->evidencia($e->evidencia(TipoEvidencia::Texto, implode("\n", array_slice($perezosas, 0, 20))))
            ->consecuencia('Si Google no ejecuta el script de carga diferida, no ve esas imágenes.')
            ->correccion('Usar la carga diferida nativa (<img src="…" loading="lazy">) en lugar de data-src.')
            ->mejorable();
    }

    private function alt(Entorno $e): BorradorResultado
    {
        $doc = $e->documento();
        $v = $e->resultado('SEO-12.imagenes-alt');
        if ($doc->imagenes === []) {
            return $v->noAplicable('La página no tiene imágenes <img>.');
        }
        $sinAlt = array_values(array_filter($doc->imagenes, static fn (array $i): bool => $i['alt'] === null && !in_array($i['role'], ['presentation', 'none'], true)));
        if ($sinAlt === []) {
            return $v->valor('Todas las imágenes (' . count($doc->imagenes) . ') tienen atributo alt.')
                ->limitacion('Que el texto describa bien la imagen lo valora ACC-01.alt-adecuado.')
                ->correcto();
        }
        $lista = array_map(static fn (array $i): string => (string) ($i['url'] ?? $i['src'] ?? '(sin src)'), $sinAlt);
        return $v->valor(count($sinAlt) . ' de ' . count($doc->imagenes) . ' imágenes sin atributo alt.')
            ->elemento('<img src="' . $lista[0] . '">')
            ->evidencia($e->evidencia(TipoEvidencia::Texto, implode("\n", array_slice($lista, 0, 20))))
            ->consecuencia('Google usa el texto alternativo para entender la imagen y mostrarla en Imágenes de Google.')
            ->correccion('Añadir alt con una descripción breve; si la imagen es decorativa, alt="".')
            ->correccionCms([
                'wordpress' => 'Medios > Biblioteca: abrir la imagen y rellenar «Texto alternativo».',
                'prestashop' => 'En el producto, pestaña Imágenes: rellenar la «Leyenda» de cada imagen.',
            ])
            ->mejorable();
    }

    private function formato(Entorno $e): BorradorResultado
    {
        $doc = $e->documento();
        $v = $e->resultado('SEO-12.imagenes-formato');
        if ($doc->imagenes === []) {
            return $v->noAplicable('La página no tiene imágenes <img>.');
        }
        $formatos = [];
        $sinDimensiones = [];
        foreach ($doc->imagenes as $i) {
            $ext = strtolower(pathinfo((string) parse_url((string) $i['url'], PHP_URL_PATH), PATHINFO_EXTENSION));
            $nombre = self::FORMATOS[$ext] ?? ($ext === '' ? 'desconocido' : $ext);
            $formatos[$nombre] = ($formatos[$nombre] ?? 0) + 1;
            if (($i['ancho'] === null || $i['alto'] === null) && $i['url'] !== null) {
                $sinDimensiones[] = $i['url'];
            }
        }
        arsort($formatos);
        $resumen = implode(', ', array_map(static fn (string $f, int $n): string => "$f: $n", array_keys($formatos), $formatos));
        $v->valor('Formatos: ' . $resumen . '. ' . count($sinDimensiones) . ' sin width/height.');
        if ($sinDimensiones === []) {
            return $v->correcto();
        }
        return $v->evidencia($e->evidencia(TipoEvidencia::Texto, implode("\n", array_slice($sinDimensiones, 0, 20))))
            ->consecuencia('Sin ancho y alto declarados, el navegador no reserva el hueco y el contenido salta mientras cargan las imágenes (empeora CLS, SEO-11).')
            ->correccion('Añadir width y height a cada <img> con sus dimensiones reales.')
            ->mejorable();
    }

    private function videoDetectado(Entorno $e): BorradorResultado
    {
        $videos = self::videos($e);
        $v = $e->resultado('SEO-12.video-detectado');
        if ($videos === []) {
            return $v->noAplicable('La página no tiene vídeo.');
        }
        $lista = array_map(static fn (array $vid): string => $vid['tipo'] . ': ' . ($vid['url'] ?? '(sin URL)'), $videos);
        return $v->valor(count($videos) . ' vídeo(s). Principal (el primero): ' . $lista[0])
            ->evidencia($e->evidencia(TipoEvidencia::Texto, implode("\n", $lista)))
            ->conclusion(TipoConclusion::Inferencia)
            ->limitacion('Se toma como principal el primero que aparece en el HTML.')
            ->correcto();
    }

    private function miniatura(Entorno $e): BorradorResultado
    {
        $videos = self::videos($e);
        $v = $e->resultado('SEO-12.video-miniatura');
        if ($videos === []) {
            return $v->noAplicable('La página no tiene vídeo.');
        }
        $principal = $videos[0];
        $marcado = $this->videoObject($e);
        $miniatura = $principal['poster'] ?? ($marcado !== null ? Estructurados::texto($marcado, 'thumbnailUrl') : null);
        if ($miniatura !== null) {
            return $v->valor('Miniatura: ' . $miniatura)->correcto();
        }
        if ($principal['tipo'] !== 'video') {
            return $v->valor('Vídeo de ' . $principal['tipo'] . ': la plataforma genera la miniatura.')
                ->conclusion(TipoConclusion::Inferencia)
                ->limitacion('No se comprueba la miniatura que sirve la plataforma.')
                ->correcto();
        }
        return $v->valor('El vídeo principal no tiene poster ni thumbnailUrl.')
            ->consecuencia('Google necesita una miniatura para mostrar el vídeo en los resultados.')
            ->correccion('Añadir poster="imagen.jpg" al <video> o thumbnailUrl en el marcado VideoObject.')
            ->mejorable();
    }

    private function videoDatos(Entorno $e): BorradorResultado
    {
        $v = $e->resultado('SEO-12.video-datos');
        if (self::videos($e) === []) {
            return $v->noAplicable('La página no tiene vídeo.');
        }
        $marcado = $this->videoObject($e);
        if ($marcado === null) {
            return $v->noAplicable('No hay marcado VideoObject; su ausencia no es un problema.');
        }
        $faltan = array_values(array_filter(['name', 'thumbnailUrl', 'uploadDate'], static fn (string $p): bool => Estructurados::texto($marcado, $p) === null));
        $v->valor('VideoObject «' . (Estructurados::texto($marcado, 'name') ?? '—') . '»' . ($faltan !== [] ? '; faltan: ' . implode(', ', $faltan) : '') . '.')
            ->limitacion('En esta versión se valida la sintaxis y las propiedades obligatorias, no que coincida con el vídeo visible.');
        if ($faltan === []) {
            return $v->correcto();
        }
        return $v->consecuencia('Sin ' . implode(', ', $faltan) . ', Google no puede usar el marcado para mostrar el vídeo en los resultados.')
            ->correccion('Completar el VideoObject con name, thumbnailUrl y uploadDate (y, si se puede, description, duration y contentUrl).')
            ->mejorable();
    }

    /** @return array<string, mixed>|null */
    private function videoObject(Entorno $e): ?array
    {
        foreach (Estructurados::nodosJsonLd($e->documento()) as $nodo) {
            if (Estructurados::esDeTipo($nodo, 'VideoObject')) {
                return $nodo;
            }
        }
        return null;
    }

    private function contextoImagenes(Entorno $e): BorradorResultado
    {
        $imagenes = $this->imagenesDeContenido($e);
        $v = $e->resultado('IA-10.contexto-imagenes');
        if ($imagenes === []) {
            return $v->noAplicable('La página no tiene imágenes de contenido (las decorativas tienen alt="").');
        }
        $xp = new DOMXPath($e->documento()->dom);
        $conPie = 0;
        foreach ($xp->query('//figure[.//img][.//figcaption]') ?: [] as $_) {
            $conPie++;
        }
        $sinTexto = array_values(array_filter($imagenes, static fn (array $i): bool => $i['alt'] === null || trim((string) $i['alt']) === ''));
        $v->valor(count($imagenes) . ' imágenes de contenido; ' . count($sinTexto) . ' sin texto alternativo; ' . $conPie . ' con pie de foto.')
            ->limitacion('Sin IA no se valora si el texto explica lo que la imagen aporta; solo si existe.');
        if ($sinTexto === []) {
            return $v->correcto();
        }
        return $v->consecuencia('Las IA leen texto: lo que solo está en esas imágenes no lo pueden entender ni citar.')
            ->correccion('Dar a cada imagen informativa un texto alternativo o un pie que diga lo que muestra.')
            ->mejorable();
    }

    private function contextoVideo(Entorno $e): BorradorResultado
    {
        $videos = self::videos($e);
        $v = $e->resultado('IA-10.contexto-video');
        if ($videos === []) {
            return $v->noAplicable('La página no tiene vídeo.');
        }
        $marcado = $this->videoObject($e);
        $titulos = array_filter(array_column($videos, 'titulo'));
        $texto = mb_strlen($e->documento()->texto);
        $tieneDescripcion = $marcado !== null && Estructurados::texto($marcado, 'description') !== null;
        $v->valor('Texto en la página: ' . $texto . ' caracteres; ' . ($tieneDescripcion ? 'VideoObject con descripción' : 'sin descripción en el marcado') . '; ' . count($titulos) . ' iframe(s) con título.');
        if ($tieneDescripcion || $texto >= RespuestaUtilizable::TEXTO_MINIMO) {
            return $v->limitacion('No se comprueba si el texto resume el vídeo.')->correcto();
        }
        return $v->consecuencia('Si lo importante solo se dice en el vídeo, ni los buscadores ni las IA lo pueden leer.')
            ->correccion('Añadir junto al vídeo un resumen o la transcripción de lo que cuenta.')
            ->mejorable();
    }

    private function horarios(Entorno $e): BorradorResultado
    {
        $doc = $e->documento();
        $v = $e->resultado('IA-10.horarios-texto');
        $patron = '/horario|tarifa|timetable|precios?\b/iu';

        $soloArchivo = [];
        foreach ($doc->enlaces as $a) {
            $ext = strtolower(pathinfo((string) parse_url((string) $a['url'], PHP_URL_PATH), PATHINFO_EXTENSION));
            if ($ext === 'pdf' && preg_match($patron, $a['nombre'] . ' ' . $a['url']) === 1) {
                $soloArchivo[] = 'PDF: ' . $a['url'];
            }
        }
        foreach ($doc->imagenes as $i) {
            if (preg_match($patron, (string) $i['alt'] . ' ' . (string) $i['src']) === 1) {
                $soloArchivo[] = 'Imagen: ' . ($i['url'] ?? $i['src']);
            }
        }
        $esTransporte = $e->contexto->sector === \ProconSEO\Shared\Enum\Sector::Transporte;
        if (!$esTransporte && $soloArchivo === []) {
            return $v->noAplicable('No es un proyecto de transporte ni la página enlaza horarios o tarifas en PDF o imagen.');
        }
        preg_match_all('/\b([01]?\d|2[0-3])[:.h][0-5]\d\b/u', $doc->texto, $horas);
        $horasEnTexto = count($horas[0]);
        $v->valor($horasEnTexto . ' horas escritas en el texto; ' . count($soloArchivo) . ' horario(s) o tarifa(s) en PDF o imagen.');
        if ($soloArchivo !== []) {
            $v->evidencia($e->evidencia(TipoEvidencia::Texto, implode("\n", $soloArchivo)));
        }
        if ($soloArchivo === [] || $horasEnTexto >= 3) {
            return $v->limitacion('Se buscan horas escritas en el texto (hh:mm); no se valida que estén completas.')->correcto();
        }
        return $v->conclusion(TipoConclusion::Inferencia)
            ->consecuencia('Los horarios y tarifas que solo están en imagen o PDF no los leen bien los buscadores ni los asistentes con IA, y tampoco los lectores de pantalla.')
            ->correccion('Publicar los horarios y tarifas también como texto en la página (por ejemplo, en una tabla).')
            ->mejorable();
    }
}
