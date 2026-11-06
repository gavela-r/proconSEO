<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Comprobaciones;

use DOMElement;
use DOMXPath;
use ProconSEO\Engine\Comprobacion;
use ProconSEO\Engine\Entorno;
use ProconSEO\Engine\Extraccion\AnalizadorHtml;
use ProconSEO\Shared\Contract\BorradorResultado;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\Gravedad;
use ProconSEO\Shared\Enum\TipoEvidencia;
use RuntimeException;

/**
 * ACC-01 (contenido no textual, 1.1.1) y ACC-02 (audio y vídeo, 1.2.x y 1.4.2).
 *
 * Lo automático solo demuestra presencia o ausencia. Si un texto alternativo describe bien
 * la imagen, o si un vídeo tiene audio que subtitular, lo decide una persona: mientras tanto,
 * el resultado es «No verificable» con revisión humana pendiente (RF-44).
 */
final class AccesibilidadImagenes implements Comprobacion
{
    /** Textos alternativos que casi nunca describen nada (en minúsculas). */
    private const ALT_SOSPECHOSOS = ['imagen', 'image', 'foto', 'photo', 'picture', 'img', 'icono', 'icon', 'logo', 'banner', 'grafico', 'gráfico'];

    public function subcomprobaciones(): array
    {
        return [
            'ACC-01.img-alt-presente' => Cobertura::Implementada,
            'ACC-01.img-enlace-sin-nombre' => Cobertura::Implementada,
            'ACC-01.input-image-alt' => Cobertura::Implementada,
            'ACC-01.svg-area-nombre' => Cobertura::ParcialmenteImplementada,
            'ACC-01.alt-adecuado' => Cobertura::ParcialmenteImplementada,
            'ACC-02.medios-detectados' => Cobertura::Implementada,
            'ACC-02.pistas-subtitulos' => Cobertura::ParcialmenteImplementada,
            'ACC-02.audio-automatico' => Cobertura::ParcialmenteImplementada,
        ];
    }

    public function evaluar(string $id, Entorno $entorno): BorradorResultado
    {
        return match ($id) {
            'ACC-01.img-alt-presente' => $this->altPresente($entorno),
            'ACC-01.img-enlace-sin-nombre' => $this->enlaceConImagen($entorno),
            'ACC-01.input-image-alt' => $this->inputImage($entorno),
            'ACC-01.svg-area-nombre' => $this->svgArea($entorno),
            'ACC-01.alt-adecuado' => $this->altAdecuado($entorno),
            'ACC-02.medios-detectados' => $this->medios($entorno),
            'ACC-02.pistas-subtitulos' => $this->subtitulos($entorno),
            'ACC-02.audio-automatico' => $this->audioAutomatico($entorno),
            default => throw new RuntimeException("Subcomprobación desconocida: $id"),
        };
    }

    private function xp(Entorno $e): DOMXPath
    {
        return new DOMXPath($e->documento()->dom);
    }

    /** Imágenes que forman parte del árbol de accesibilidad (no ocultas ni de presentación). */
    private function imagenesVisibles(Entorno $e): array
    {
        $lista = [];
        foreach ($this->xp($e)->query('//img[not(ancestor-or-self::*[@aria-hidden="true"])][not(ancestor-or-self::*[@hidden])]') ?: [] as $img) {
            if ($img instanceof DOMElement && !in_array(strtolower($img->getAttribute('role')), ['presentation', 'none'], true)) {
                $lista[] = $img;
            }
        }
        return $lista;
    }

    private static function src(DOMElement $img): string
    {
        return $img->getAttribute('src') ?: $img->getAttribute('data-src') ?: '(sin src)';
    }

    private function altPresente(Entorno $e): BorradorResultado
    {
        $imagenes = $this->imagenesVisibles($e);
        $v = $e->resultado('ACC-01.img-alt-presente');
        if ($imagenes === []) {
            return $v->noAplicable('La página no tiene imágenes <img> visibles para las tecnologías de apoyo.');
        }
        $sinAlt = array_values(array_filter($imagenes, static fn (DOMElement $i): bool => !$i->hasAttribute('alt')
            && trim($i->getAttribute('aria-label')) === '' && trim($i->getAttribute('aria-labelledby')) === '' && trim($i->getAttribute('title')) === ''));
        if ($sinAlt === []) {
            return $v->valor('Las ' . count($imagenes) . ' imágenes tienen texto alternativo (o alt="" si son decorativas).')
                ->limitacion('Que el texto sea adecuado se revisa en ACC-01.alt-adecuado.')
                ->correcto();
        }
        $lista = array_map(static fn (DOMElement $i): string => '<img src="' . self::src($i) . '">', $sinAlt);
        return $v->valor(count($sinAlt) . ' de ' . count($imagenes) . ' imágenes sin atributo alt.')
            ->elemento($lista[0])
            ->evidencia($e->evidencia(TipoEvidencia::FragmentoHtml, implode("\n", array_slice($lista, 0, 20))))
            ->consecuencia('Un lector de pantalla no puede decir qué muestran: suele leer el nombre del fichero o nada.')
            ->correccion('Añadir alt a cada imagen: una descripción breve si informa de algo, o alt="" si es decorativa.')
            ->correccionCms([
                'wordpress' => 'Medios > Biblioteca: abrir cada imagen y rellenar «Texto alternativo».',
                'prestashop' => 'En cada producto, pestaña Imágenes: rellenar la «Leyenda», que se usa como alt.',
            ])
            ->problema(Gravedad::Alto);
    }

    private function enlaceConImagen(Entorno $e): BorradorResultado
    {
        $xp = $this->xp($e);
        $analizador = new AnalizadorHtml();
        $con = 0;
        $sinNombre = [];
        foreach ($xp->query('//a[@href][.//img]|//button[.//img]') ?: [] as $el) {
            if (!$el instanceof DOMElement) {
                continue;
            }
            $con++;
            if ($analizador->nombreAccesible($xp, $el) === '') {
                $img = $xp->query('.//img', $el)?->item(0);
                $sinNombre[] = '<' . $el->nodeName . ($el->nodeName === 'a' ? ' href="' . $el->getAttribute('href') . '"' : '') . '><img src="'
                    . ($img instanceof DOMElement ? self::src($img) : '') . '"></' . $el->nodeName . '>';
            }
        }
        $v = $e->resultado('ACC-01.img-enlace-sin-nombre');
        if ($con === 0) {
            return $v->noAplicable('No hay enlaces ni botones con imágenes.');
        }
        if ($sinNombre === []) {
            return $v->valor('Los ' . $con . ' enlaces o botones con imagen tienen nombre.')->correcto();
        }
        return $v->valor(count($sinNombre) . ' enlace(s) o botón(es) cuyo único contenido es una imagen sin texto alternativo.')
            ->elemento($sinNombre[0])
            ->evidencia($e->evidencia(TipoEvidencia::FragmentoHtml, implode("\n", array_slice($sinNombre, 0, 20))))
            ->consecuencia('El lector de pantalla anuncia «enlace» o «botón» sin decir adónde lleva ni qué hace.')
            ->correccion('Poner en el alt de la imagen el destino o la acción («Inicio», «Buscar», «Abrir carrito»), no lo que se ve.')
            ->problema(Gravedad::Alto);
    }

    private function inputImage(Entorno $e): BorradorResultado
    {
        $botones = [];
        $sinAlt = [];
        foreach ($this->xp($e)->query('//input[translate(@type,"IMAGE","image")="image"]') ?: [] as $i) {
            if ($i instanceof DOMElement) {
                $botones[] = $i;
                if (trim($i->getAttribute('alt')) === '' && trim($i->getAttribute('aria-label')) === '' && trim($i->getAttribute('title')) === '') {
                    $sinAlt[] = '<input type="image" src="' . $i->getAttribute('src') . '">';
                }
            }
        }
        $v = $e->resultado('ACC-01.input-image-alt');
        if ($botones === []) {
            return $v->noAplicable('No hay botones de imagen (<input type="image">).');
        }
        if ($sinAlt === []) {
            return $v->valor('Los ' . count($botones) . ' botones de imagen tienen alt.')->correcto();
        }
        return $v->valor(count($sinAlt) . ' botón(es) de imagen sin alt.')
            ->elemento($sinAlt[0])
            ->evidencia($e->evidencia(TipoEvidencia::FragmentoHtml, implode("\n", $sinAlt)))
            ->consecuencia('Quien usa lector de pantalla no sabe qué hace el botón (p. ej., enviar el formulario).')
            ->correccion('Añadir alt con la acción del botón, p. ej. alt="Enviar".')
            ->problema(Gravedad::Alto);
    }

    private function svgArea(Entorno $e): BorradorResultado
    {
        $xp = $this->xp($e);
        $analizador = new AnalizadorHtml();
        $total = 0;
        $sinNombre = [];
        foreach ($xp->query('//svg[@role="img"]|//area[@href]') ?: [] as $el) {
            if (!$el instanceof DOMElement) {
                continue;
            }
            $total++;
            $nombre = $el->nodeName === 'area'
                ? trim($el->getAttribute('alt') ?: $el->getAttribute('aria-label'))
                : trim($el->getAttribute('aria-label') ?: (string) $el->getElementsByTagName('title')->item(0)?->textContent);
            if ($nombre === '' && $el->getAttribute('aria-labelledby') !== '') {
                $nombre = $analizador->nombreAccesible($xp, $el);
            }
            if ($nombre === '') {
                $sinNombre[] = $el->nodeName === 'area' ? '<area href="' . $el->getAttribute('href') . '">' : '<svg role="img">';
            }
        }
        $v = $e->resultado('ACC-01.svg-area-nombre');
        if ($total === 0) {
            return $v->noAplicable('No hay SVG con role="img" ni mapas de imagen.');
        }
        $v->limitacion('Los SVG sin role="img" no se valoran: sin navegador no se sabe si son decorativos.');
        if ($sinNombre === []) {
            return $v->valor($total . ' SVG o áreas con nombre.')->correcto();
        }
        return $v->valor(count($sinNombre) . ' de ' . $total . ' sin nombre accesible.')
            ->evidencia($e->evidencia(TipoEvidencia::FragmentoHtml, implode("\n", $sinNombre)))
            ->consecuencia('Se anuncian como «imagen» o «enlace» sin decir qué representan.')
            ->correccion('Añadir aria-label (o un <title> dentro del SVG); en <area>, el atributo alt.')
            ->problema(Gravedad::Medio);
    }

    private function altAdecuado(Entorno $e): BorradorResultado
    {
        $conAlt = array_values(array_filter($this->imagenesVisibles($e), static fn (DOMElement $i): bool => trim($i->getAttribute('alt')) !== ''));
        $v = $e->resultado('ACC-01.alt-adecuado');
        if ($conAlt === []) {
            return $v->noAplicable('No hay imágenes con texto alternativo que revisar.');
        }
        $lineas = [];
        $sospechosos = 0;
        foreach ($conAlt as $img) {
            $alt = trim($img->getAttribute('alt'));
            $fichero = strtolower(pathinfo(self::src($img), PATHINFO_FILENAME));
            $motivo = null;
            if (in_array(mb_strtolower($alt), self::ALT_SOSPECHOSOS, true)) {
                $motivo = 'texto genérico';
            } elseif ($fichero !== '' && strtolower($alt) === $fichero || preg_match('/\.(jpe?g|png|gif|webp|svg|avif)$/i', $alt) === 1) {
                $motivo = 'parece el nombre del fichero';
            } elseif (mb_strlen($alt) > 150) {
                $motivo = 'muy largo (' . mb_strlen($alt) . ' caracteres)';
            }
            if ($motivo !== null) {
                $sospechosos++;
            }
            $lineas[] = ($motivo !== null ? '[revisar: ' . $motivo . '] ' : '') . self::src($img) . ' → «' . mb_substr($alt, 0, 200) . '»';
        }
        return $v->valor(count($conAlt) . ' imágenes con texto alternativo para revisar' . ($sospechosos > 0 ? '; ' . $sospechosos . ' parecen poco descriptivas' : '') . '.')
            ->evidencia($e->evidencia(TipoEvidencia::Texto, implode("\n", array_slice($lineas, 0, 50))))
            ->revisionHumana('Una persona debe comprobar que cada texto describe la función o el contenido de su imagen.')
            ->noVerificable('Que el texto alternativo sea adecuado requiere revisión humana (especificación 10: la presencia no es calidad).');
    }

    /** @return list<array{tipo: string, autoplay: bool, muted: bool, pistas: list<array{kind: string, srclang: ?string}>}> */
    private function mediosPropios(Entorno $e): array
    {
        return array_map(static fn (array $m): array => ['tipo' => $m['tipo'], 'autoplay' => $m['autoplay'], 'muted' => $m['muted'], 'pistas' => $m['pistas']], $e->documento()->medios);
    }

    private function medios(Entorno $e): BorradorResultado
    {
        $propios = $this->mediosPropios($e);
        $incrustados = array_values(array_filter(Multimedia::videos($e), static fn (array $vid): bool => $vid['tipo'] !== 'video'));
        $v = $e->resultado('ACC-02.medios-detectados');
        if ($propios === [] && $incrustados === []) {
            return $v->noAplicable('La página no tiene audio ni vídeo.');
        }
        $cuenta = array_count_values(array_column($propios, 'tipo'));
        $resumen = [];
        foreach ($cuenta as $tipo => $n) {
            $resumen[] = $n . ' ' . ($tipo === 'video' ? '<video>' : '<audio>');
        }
        foreach ($incrustados as $vid) {
            $resumen[] = 'vídeo de ' . $vid['tipo'];
        }
        return $v->valor('Medios: ' . implode(', ', $resumen) . '.')
            ->evidencia($e->evidencia(TipoEvidencia::Texto, implode("\n", $resumen)))
            ->revisionHumana('Comprobar en cada medio grabado: subtítulos (1.2.2), transcripción o audiodescripción (1.2.1, 1.2.3, 1.2.5) y, si hay emisión en directo, subtítulos en directo (1.2.4).')
            ->noVerificable('Hay audio o vídeo: sus alternativas solo se pueden valorar viéndolos.');
    }

    private function subtitulos(Entorno $e): BorradorResultado
    {
        $videos = array_values(array_filter($this->mediosPropios($e), static fn (array $m): bool => $m['tipo'] === 'video'));
        $incrustados = array_values(array_filter(Multimedia::videos($e), static fn (array $vid): bool => $vid['tipo'] !== 'video'));
        $v = $e->resultado('ACC-02.pistas-subtitulos');
        if ($videos === [] && $incrustados === []) {
            return $v->noAplicable('La página no tiene vídeo.');
        }
        $con = array_values(array_filter($videos, static fn (array $m): bool => array_intersect(array_column($m['pistas'], 'kind'), ['captions', 'subtitles']) !== []));
        $sin = count($videos) - count($con);
        $v->valor(count($con) . ' <video> con pista de subtítulos, ' . $sin . ' sin ella, ' . count($incrustados) . ' incrustado(s) de terceros.');
        if ($incrustados !== []) {
            $v->limitacion('Los subtítulos de YouTube o Vimeo no se pueden comprobar desde el HTML.');
        }
        if ($sin > 0) {
            return $v->consecuencia('Si esos vídeos tienen voz, una persona sorda no puede seguirlos.')
                ->correccion('Añadir <track kind="captions" src="subtitulos.vtt" srclang="es" label="Español"> a cada vídeo con audio.')
                ->revisionHumana('Hay que ver si el vídeo tiene audio con información (si no tiene, no necesita subtítulos).')
                ->noVerificable($sin . ' vídeo(s) sin pista de subtítulos: falta confirmar si tienen audio.');
        }
        if ($videos === []) {
            return $v->revisionHumana('Comprobar los subtítulos en la plataforma (YouTube, Vimeo…).')
                ->noVerificable('Solo hay vídeos incrustados de terceros.');
        }
        return $v->limitacion('Hay pistas de subtítulos; que estén completas y sincronizadas lo comprueba una persona.')->correcto();
    }

    private function audioAutomatico(Entorno $e): BorradorResultado
    {
        $medios = $this->mediosPropios($e);
        $v = $e->resultado('ACC-02.audio-automatico');
        if ($medios === []) {
            return $v->noAplicable('La página no tiene <audio> ni <video> propios.');
        }
        $suenan = array_values(array_filter($medios, static fn (array $m): bool => $m['autoplay'] && !$m['muted']));
        if ($suenan === []) {
            return $v->valor('Ningún medio se reproduce solo con sonido.')
                ->limitacion('No se detectan reproducciones lanzadas por JavaScript.')
                ->correcto();
        }
        return $v->valor(count($suenan) . ' medio(s) con autoplay y sin muted.')
            ->elemento('<' . $suenan[0]['tipo'] . ' autoplay>')
            ->consecuencia('El sonido que arranca solo tapa la voz del lector de pantalla; si dura más de 3 segundos sin forma de pararlo, incumple 1.4.2.')
            ->correccion('Quitar autoplay, añadir muted o dar un control visible para pausar o bajar el volumen.')
            ->revisionHumana('Sin reproducirlo no se sabe si dura más de 3 segundos ni si hay un control para pararlo.')
            ->noVerificable('Falta comprobar la duración y los controles del audio.');
    }
}
