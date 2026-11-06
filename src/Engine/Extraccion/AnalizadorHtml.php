<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Extraccion;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use JsonException;

/**
 * Lee una página HTML de forma segura (SEG-05) y extrae lo que necesitan las comprobaciones.
 *
 * - Usa el parser HTML de libxml sin red (LIBXML_NONET) y sin sustituir entidades: el HTML no
 *   puede cargar DTD ni entidades externas.
 * - Rechaza documentos por encima del límite en lugar de recortarlos en silencio.
 * - No ejecuta nada ni sigue ningún enlace.
 */
final class AnalizadorHtml
{
    /** Tamaño máximo del HTML (perfil v1, sección 8: 5 MB). */
    public const LIMITE_BYTES = 5 * 1024 * 1024;

    /** Bloques JSON-LD que se leen como máximo. */
    private const MAXIMO_JSONLD = 50;

    /** Profundidad máxima de un JSON-LD. */
    private const PROFUNDIDAD_JSONLD = 32;

    private const MAPA_NO_ASCII = [0x80, 0x10FFFF, 0, 0x1FFFFF];

    /** Etiquetas cuyo texto no es contenido visible. */
    private const SIN_TEXTO = ['script', 'style', 'noscript', 'template', 'head', 'svg', 'iframe', 'object'];

    public function __construct(private readonly int $limiteBytes = self::LIMITE_BYTES)
    {
    }

    /**
     * @param string $html    Cuerpo de la respuesta
     * @param string $urlBase URL final de la página, para resolver enlaces relativos
     *
     * @throws ErrorExtraccion si el documento supera el límite o no se puede leer
     */
    public function analizar(string $html, string $urlBase): DocumentoHtml
    {
        if (strlen($html) > $this->limiteBytes) {
            throw new ErrorExtraccion('demasiado_grande', 'El HTML supera el límite de ' . $this->limiteBytes . ' bytes.');
        }

        $dom = $this->cargar($html);
        $xp = new DOMXPath($dom);

        $base = $urlBase;
        $baseHref = $this->primero($xp, '//head/base[@href]');
        if ($baseHref instanceof DOMElement) {
            $base = Url::resolver($urlBase, $baseHref->getAttribute('href')) ?? $urlBase;
        }

        $html_ = $dom->documentElement;
        $lang = $html_ instanceof DOMElement && $html_->hasAttribute('lang') ? trim($html_->getAttribute('lang')) : null;

        return new DocumentoHtml(
            urlBase: $base,
            lang: $lang,
            titulos: $this->titulos($xp),
            metas: $this->metas($xp),
            canonicos: $this->canonicos($xp, $base),
            alternativasIdioma: $this->alternativas($xp, $base),
            encabezados: $this->encabezados($xp),
            enlaces: $this->enlaces($xp, $base),
            imagenes: $this->imagenes($xp, $base),
            formularios: $this->formularios($xp, $base),
            botones: $this->botones($xp),
            iframes: $this->iframes($xp, $base),
            medios: $this->medios($xp, $base),
            jsonLd: $this->jsonLd($xp),
            tiposMicrodatos: $this->microdatos($xp),
            recursos: $this->recursos($xp, $base),
            roles: $this->roles($xp),
            tabindexPositivos: $this->tabindex($xp),
            idsDuplicados: $this->idsDuplicados($xp),
            idiomasPartes: $this->idiomasPartes($xp),
            texto: $this->textoVisible($dom),
            dom: $dom,
        );
    }

    private function cargar(string $html): DOMDocument
    {
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
        }
        // libxml interpreta el HTML como ISO-8859-1 si no ve un charset, y obedece la meta
        // charset de la página aunque mienta. Para no depender de eso, los caracteres no ASCII
        // se pasan como referencias numéricas (el texto ya está en UTF-8). En el texto de
        // <script> y <style> el parser no las decodifica: lo hace textoCrudo().
        $html = mb_encode_numericentity($html, self::MAPA_NO_ASCII, 'UTF-8');

        $dom = new DOMDocument();
        $previo = libxml_use_internal_errors(true);
        try {
            // Sin LIBXML_NOENT ni LIBXML_DTDLOAD: no se sustituyen entidades ni se cargan DTD.
            $ok = $dom->loadHTML($html === '' ? '<html></html>' : $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previo);
        }
        if ($ok === false) {
            throw new ErrorExtraccion('html_ilegible', 'No se ha podido leer el HTML.');
        }
        return $dom;
    }

    /** @return list<string> */
    private function titulos(DOMXPath $xp): array
    {
        $titulos = [];
        foreach ($xp->query('//title[not(ancestor::svg)]') ?: [] as $t) {
            if (!$t instanceof DOMElement) {
                continue;
            }
            $titulos[] = self::normalizar($t->textContent);
        }
        return $titulos;
    }

    /** @return list<array{nombre: ?string, propiedad: ?string, httpEquiv: ?string, contenido: ?string, charset: ?string}> */
    private function metas(DOMXPath $xp): array
    {
        $metas = [];
        foreach ($xp->query('//meta') ?: [] as $m) {
            /** @var DOMElement $m */
            $metas[] = [
                'nombre' => self::atributo($m, 'name'),
                'propiedad' => self::atributo($m, 'property'),
                'httpEquiv' => self::atributo($m, 'http-equiv'),
                'contenido' => self::atributo($m, 'content'),
                'charset' => self::atributo($m, 'charset'),
            ];
        }
        return $metas;
    }

    /** @return list<string> */
    private function canonicos(DOMXPath $xp, string $base): array
    {
        $urls = [];
        foreach ($this->links($xp, 'canonical') as $l) {
            $url = Url::resolver($base, $l->getAttribute('href'));
            if ($url !== null) {
                $urls[] = $url;
            }
        }
        return $urls;
    }

    /** @return list<array{hreflang: string, href: string, url: ?string}> */
    private function alternativas(DOMXPath $xp, string $base): array
    {
        $alt = [];
        foreach ($this->links($xp, 'alternate') as $l) {
            if (!$l->hasAttribute('hreflang')) {
                continue;
            }
            $href = $l->getAttribute('href');
            $alt[] = ['hreflang' => trim($l->getAttribute('hreflang')), 'href' => $href, 'url' => Url::resolver($base, $href)];
        }
        return $alt;
    }

    /** @return list<DOMElement> */
    private function links(DOMXPath $xp, string $rel): array
    {
        $links = [];
        foreach ($xp->query('//link[@rel][@href]') ?: [] as $l) {
            /** @var DOMElement $l */
            if (in_array($rel, self::tokens($l->getAttribute('rel')), true)) {
                $links[] = $l;
            }
        }
        return $links;
    }

    /** @return list<array{nivel: int, texto: string}> */
    private function encabezados(DOMXPath $xp): array
    {
        $lista = [];
        foreach ($xp->query('//h1|//h2|//h3|//h4|//h5|//h6') ?: [] as $h) {
            /** @var DOMElement $h */
            $lista[] = ['nivel' => (int) substr($h->nodeName, 1), 'texto' => $this->nombreAccesible($xp, $h)];
        }
        return $lista;
    }

    /** @return list<array<string, mixed>> */
    private function enlaces(DOMXPath $xp, string $base): array
    {
        $lista = [];
        foreach ($xp->query('//a') ?: [] as $a) {
            /** @var DOMElement $a */
            $tieneHref = $a->hasAttribute('href');
            $href = $a->getAttribute('href');
            $lista[] = [
                'href' => $href,
                'url' => $tieneHref ? Url::resolver($base, $href) : null,
                'texto' => self::normalizar($a->textContent),
                'nombre' => $this->nombreAccesible($xp, $a),
                'rel' => self::tokens($a->getAttribute('rel')),
                'hreflang' => self::atributo($a, 'hreflang'),
                'noNavegable' => $tieneHref && Url::esNoNavegable($href),
                'tieneHref' => $tieneHref,
            ];
        }
        return $lista;
    }

    /** @return list<array<string, mixed>> */
    private function imagenes(DOMXPath $xp, string $base): array
    {
        $lista = [];
        foreach ($xp->query('//img') ?: [] as $img) {
            /** @var DOMElement $img */
            $src = self::atributo($img, 'src');
            $enlace = $this->ancestroInteractivo($img);
            $lista[] = [
                'src' => $src,
                'url' => $src === null ? null : Url::resolver($base, $src),
                'alt' => $img->hasAttribute('alt') ? $img->getAttribute('alt') : null,
                'ancho' => self::atributo($img, 'width'),
                'alto' => self::atributo($img, 'height'),
                'role' => self::atributo($img, 'role'),
                'enEnlace' => $enlace !== null,
                'soloContenidoDeEnlace' => $enlace !== null && self::normalizar($enlace->textContent) === ''
                    && $xp->query('.//img', $enlace)?->length === 1,
            ];
        }
        return $lista;
    }

    /** @return list<array<string, mixed>> */
    private function formularios(DOMXPath $xp, string $base): array
    {
        $lista = [];
        foreach ($xp->query('//form') ?: [] as $form) {
            /** @var DOMElement $form */
            $action = self::atributo($form, 'action');
            $campos = [];
            foreach ($xp->query('.//input|.//select|.//textarea', $form) ?: [] as $campo) {
                /** @var DOMElement $campo */
                $campos[] = $this->campo($xp, $campo);
            }
            $lista[] = [
                'action' => $action,
                // Sin action, el formulario se envía a la propia página.
                'url' => $action === null || trim($action) === '' ? Url::sinFragmento($base) : Url::resolver($base, $action),
                'metodo' => strtolower(self::atributo($form, 'method') ?? 'get'),
                'campos' => $campos,
            ];
        }

        // Campos fuera de un <form> (frecuentes en buscadores y widgets).
        $sueltos = [];
        foreach ($xp->query('//input[not(ancestor::form)]|//select[not(ancestor::form)]|//textarea[not(ancestor::form)]') ?: [] as $campo) {
            /** @var DOMElement $campo */
            $sueltos[] = $this->campo($xp, $campo);
        }
        if ($sueltos !== []) {
            $lista[] = ['action' => null, 'url' => null, 'metodo' => 'ninguno', 'campos' => $sueltos];
        }
        return $lista;
    }

    /** @return array<string, mixed> */
    private function campo(DOMXPath $xp, DOMElement $campo): array
    {
        $tipo = $campo->nodeName === 'input' ? strtolower(self::atributo($campo, 'type') ?? 'text') : $campo->nodeName;
        return [
            'elemento' => $campo->nodeName,
            'tipo' => $tipo,
            'nombre' => self::atributo($campo, 'name'),
            'id' => self::atributo($campo, 'id'),
            'etiqueta' => $this->etiquetaDeCampo($xp, $campo),
            'ariaLabel' => self::atributo($campo, 'aria-label'),
            'ariaLabelledby' => self::atributo($campo, 'aria-labelledby'),
            'titulo' => self::atributo($campo, 'title'),
            'placeholder' => self::atributo($campo, 'placeholder'),
            'autocomplete' => self::atributo($campo, 'autocomplete'),
            'obligatorio' => $campo->hasAttribute('required') || strtolower($campo->getAttribute('aria-required')) === 'true',
            'alt' => $campo->hasAttribute('alt') ? $campo->getAttribute('alt') : null,
            'valor' => $tipo === 'password' ? null : self::atributo($campo, 'value'),
            'nombreAccesible' => $this->nombreAccesible($xp, $campo),
        ];
    }

    /** Texto del <label> asociado (por for o envolvente), o null. */
    private function etiquetaDeCampo(DOMXPath $xp, DOMElement $campo): ?string
    {
        $id = $campo->getAttribute('id');
        if ($id !== '') {
            foreach ($xp->query('//label[@for=' . self::literal($id) . ']') ?: [] as $label) {
                if (!$label instanceof DOMElement) {
                    continue;
                }
                return self::normalizar($label->textContent);
            }
        }
        for ($n = $campo->parentNode; $n instanceof DOMElement; $n = $n->parentNode) {
            if ($n->nodeName === 'label') {
                return self::normalizar($n->textContent);
            }
        }
        return null;
    }

    /** @return list<array{etiqueta: string, tipo: ?string, nombre: string}> */
    private function botones(DOMXPath $xp): array
    {
        $lista = [];
        $q = '//button|//input[@type="submit" or @type="button" or @type="reset" or @type="image"]|//*[@role="button"][not(self::button)]';
        foreach ($xp->query($q) ?: [] as $b) {
            /** @var DOMElement $b */
            $lista[] = ['etiqueta' => $b->nodeName, 'tipo' => self::atributo($b, 'type'), 'nombre' => $this->nombreAccesible($xp, $b)];
        }
        return $lista;
    }

    /** @return list<array<string, mixed>> */
    private function iframes(DOMXPath $xp, string $base): array
    {
        $lista = [];
        foreach ($xp->query('//iframe') ?: [] as $f) {
            /** @var DOMElement $f */
            $src = self::atributo($f, 'src');
            $lista[] = [
                'src' => $src,
                'url' => $src === null ? null : Url::resolver($base, $src),
                'titulo' => self::atributo($f, 'title'),
                'nombre' => $this->nombreAccesible($xp, $f),
                'oculto' => $f->hasAttribute('hidden') || strtolower($f->getAttribute('aria-hidden')) === 'true',
            ];
        }
        return $lista;
    }

    /** @return list<array<string, mixed>> */
    private function medios(DOMXPath $xp, string $base): array
    {
        $lista = [];
        foreach ($xp->query('//video|//audio') ?: [] as $m) {
            /** @var DOMElement $m */
            $src = self::atributo($m, 'src');
            if ($src === null) {
                $fuente = $this->primero($xp, './/source[@src]', $m);
                $src = $fuente instanceof DOMElement ? $fuente->getAttribute('src') : null;
            }
            $pistas = [];
            foreach ($xp->query('.//track', $m) ?: [] as $t) {
                /** @var DOMElement $t */
                $pistas[] = ['kind' => strtolower(self::atributo($t, 'kind') ?? 'subtitles'), 'srclang' => self::atributo($t, 'srclang')];
            }
            $poster = self::atributo($m, 'poster');
            $lista[] = [
                'tipo' => $m->nodeName,
                'src' => $src,
                'url' => $src === null ? null : Url::resolver($base, $src),
                'autoplay' => $m->hasAttribute('autoplay'),
                'muted' => $m->hasAttribute('muted'),
                'controls' => $m->hasAttribute('controls'),
                'poster' => $poster === null ? null : Url::resolver($base, $poster),
                'pistas' => $pistas,
            ];
        }
        return $lista;
    }

    /** @return list<array{crudo: string, datos: mixed, error: ?string}> */
    private function jsonLd(DOMXPath $xp): array
    {
        $lista = [];
        foreach ($xp->query('//script') ?: [] as $s) {
            /** @var DOMElement $s */
            $tipo = strtolower(trim(explode(';', $s->getAttribute('type'))[0]));
            if ($tipo !== 'application/ld+json') {
                continue;
            }
            if (count($lista) >= self::MAXIMO_JSONLD) {
                break;
            }
            $crudo = trim(self::textoCrudo($s));
            try {
                $datos = json_decode($crudo, true, self::PROFUNDIDAD_JSONLD, JSON_THROW_ON_ERROR);
                $lista[] = ['crudo' => $crudo, 'datos' => $datos, 'error' => null];
            } catch (JsonException $e) {
                $lista[] = ['crudo' => $crudo, 'datos' => null, 'error' => $e->getMessage()];
            }
        }
        return $lista;
    }

    /** @return list<string> */
    private function microdatos(DOMXPath $xp): array
    {
        $tipos = [];
        foreach ($xp->query('//*[@itemscope][@itemtype]') ?: [] as $e) {
            /** @var DOMElement $e */
            foreach (self::tokens($e->getAttribute('itemtype'), false) as $t) {
                $tipos[] = $t;
            }
        }
        return $tipos;
    }

    /** @return list<array{tipo: string, url: string}> */
    private function recursos(DOMXPath $xp, string $base): array
    {
        $consultas = [
            'script' => ['//script[@src]', 'src'],
            'estilo' => ['//link[@href]', 'href'],
            'imagen' => ['//img[@src]|//input[@type="image"][@src]', 'src'],
            'iframe' => ['//iframe[@src]', 'src'],
            'medio' => ['//video[@src]|//audio[@src]|//source[@src]|//track[@src]', 'src'],
            'objeto' => ['//embed[@src]', 'src'],
        ];
        $lista = [];
        foreach ($consultas as $tipo => [$consulta, $atributo]) {
            foreach ($xp->query($consulta) ?: [] as $e) {
                /** @var DOMElement $e */
                if ($tipo === 'estilo' && !in_array('stylesheet', self::tokens($e->getAttribute('rel')), true)) {
                    continue;
                }
                $url = Url::resolver($base, $e->getAttribute($atributo));
                if ($url !== null) {
                    $lista[] = ['tipo' => $tipo, 'url' => $url];
                }
            }
        }
        return $lista;
    }

    /** @return list<array{etiqueta: string, role: string}> */
    private function roles(DOMXPath $xp): array
    {
        $lista = [];
        foreach ($xp->query('//*[@role]') ?: [] as $e) {
            /** @var DOMElement $e */
            $lista[] = ['etiqueta' => $e->nodeName, 'role' => trim($e->getAttribute('role'))];
        }
        return $lista;
    }

    /** @return list<array{etiqueta: string, tabindex: int}> */
    private function tabindex(DOMXPath $xp): array
    {
        $lista = [];
        foreach ($xp->query('//*[@tabindex]') ?: [] as $e) {
            /** @var DOMElement $e */
            $valor = trim($e->getAttribute('tabindex'));
            if (preg_match('/^\d+$/', $valor) === 1 && (int) $valor > 0) {
                $lista[] = ['etiqueta' => $e->nodeName, 'tabindex' => (int) $valor];
            }
        }
        return $lista;
    }

    /** @return list<string> */
    private function idsDuplicados(DOMXPath $xp): array
    {
        $cuenta = [];
        foreach ($xp->query('//*[@id]') ?: [] as $e) {
            /** @var DOMElement $e */
            $id = $e->getAttribute('id');
            $cuenta[$id] = ($cuenta[$id] ?? 0) + 1;
        }
        return array_map('strval', array_keys(array_filter($cuenta, static fn (int $n): bool => $n > 1)));
    }

    /** @return list<array{etiqueta: string, lang: string}> */
    private function idiomasPartes(DOMXPath $xp): array
    {
        $lista = [];
        foreach ($xp->query('//body//*[@lang]') ?: [] as $e) {
            /** @var DOMElement $e */
            $lista[] = ['etiqueta' => $e->nodeName, 'lang' => trim($e->getAttribute('lang'))];
        }
        return $lista;
    }

    private function textoVisible(DOMDocument $dom): string
    {
        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return '';
        }
        $partes = [];
        $this->recogerTexto($body, $partes);
        return self::normalizar(implode(' ', $partes));
    }

    /** @param list<string> $partes */
    private function recogerTexto(DOMNode $nodo, array &$partes): void
    {
        foreach ($nodo->childNodes as $hijo) {
            if ($hijo->nodeType === XML_TEXT_NODE) {
                $partes[] = $hijo->nodeValue ?? '';
            } elseif ($hijo instanceof DOMElement) {
                if (in_array($hijo->nodeName, self::SIN_TEXTO, true) || $hijo->hasAttribute('hidden')
                    || preg_match('/display\s*:\s*none/i', $hijo->getAttribute('style')) === 1) {
                    continue;
                }
                $this->recogerTexto($hijo, $partes);
            }
        }
    }

    /**
     * Nombre accesible aproximado (sin CSS ni navegador): aria-labelledby, aria-label,
     * contenido (texto y alt de imágenes), alt y title. Suficiente para detectar nombres vacíos.
     */
    public function nombreAccesible(DOMXPath $xp, DOMElement $e): string
    {
        $ids = trim($e->getAttribute('aria-labelledby'));
        if ($ids !== '') {
            $partes = [];
            foreach (preg_split('/\s+/', $ids) ?: [] as $id) {
                $ref = $this->primero($xp, '//*[@id=' . self::literal($id) . ']');
                if ($ref !== null) {
                    $partes[] = self::normalizar($ref->textContent);
                }
            }
            $nombre = self::normalizar(implode(' ', $partes));
            if ($nombre !== '') {
                return $nombre;
            }
        }

        $aria = self::normalizar($e->getAttribute('aria-label'));
        if ($aria !== '') {
            return $aria;
        }

        if (in_array($e->nodeName, ['input', 'select', 'textarea'], true)) {
            $tipo = strtolower($e->getAttribute('type'));
            if ($e->nodeName === 'input' && in_array($tipo, ['submit', 'button', 'reset'], true)) {
                $valor = self::normalizar($e->getAttribute('value'));
                if ($valor !== '') {
                    return $valor;
                }
            }
            if ($e->nodeName === 'input' && $tipo === 'image') {
                return self::normalizar($e->getAttribute('alt') ?: $e->getAttribute('title'));
            }
            $etiqueta = $this->etiquetaDeCampo($xp, $e);
            return $etiqueta ?? self::normalizar($e->getAttribute('title'));
        }

        if ($e->nodeName === 'img') {
            return self::normalizar($e->getAttribute('alt') ?: $e->getAttribute('title'));
        }

        if (!in_array($e->nodeName, ['iframe'], true)) {
            $contenido = self::normalizar($this->textoConAlternativas($e));
            if ($contenido !== '') {
                return $contenido;
            }
        }

        return self::normalizar($e->getAttribute('title'));
    }

    private function textoConAlternativas(DOMNode $nodo): string
    {
        $texto = '';
        foreach ($nodo->childNodes as $hijo) {
            if ($hijo->nodeType === XML_TEXT_NODE) {
                $texto .= $hijo->nodeValue;
            } elseif ($hijo instanceof DOMElement) {
                if (in_array($hijo->nodeName, ['script', 'style', 'template'], true)
                    || strtolower($hijo->getAttribute('aria-hidden')) === 'true') {
                    continue;
                }
                if ($hijo->nodeName === 'img' || ($hijo->nodeName === 'input' && strtolower($hijo->getAttribute('type')) === 'image')) {
                    $texto .= ' ' . $hijo->getAttribute('alt') . ' ';
                } elseif ($hijo->nodeName === 'svg') {
                    $titulo = $hijo->getElementsByTagName('title')->item(0);
                    $texto .= ' ' . ($hijo->getAttribute('aria-label') ?: ($titulo?->textContent ?? '')) . ' ';
                } else {
                    $texto .= ' ' . $this->textoConAlternativas($hijo) . ' ';
                }
            }
        }
        return $texto;
    }

    private function ancestroInteractivo(DOMElement $e): ?DOMElement
    {
        for ($n = $e->parentNode; $n instanceof DOMElement; $n = $n->parentNode) {
            if ($n->nodeName === 'a' || $n->nodeName === 'button') {
                return $n;
            }
        }
        return null;
    }

    private function primero(DOMXPath $xp, string $consulta, ?DOMNode $contexto = null): ?DOMElement
    {
        $r = $xp->query($consulta, $contexto);
        $n = $r === false ? null : $r->item(0);
        return $n instanceof DOMElement ? $n : null;
    }

    /** Texto de un elemento de texto crudo (<script>, <style>) con los caracteres restaurados. */
    private static function textoCrudo(DOMElement $e): string
    {
        return mb_decode_numericentity($e->textContent, self::MAPA_NO_ASCII, 'UTF-8');
    }

    private static function atributo(DOMElement $e, string $nombre): ?string
    {
        return $e->hasAttribute($nombre) ? $e->getAttribute($nombre) : null;
    }

    /** @return list<string> */
    private static function tokens(string $valor, bool $minusculas = true): array
    {
        $valor = $minusculas ? strtolower($valor) : $valor;
        return array_values(array_filter(preg_split('/\s+/', trim($valor)) ?: [], static fn (string $t): bool => $t !== ''));
    }

    private static function normalizar(string $texto): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $texto)));
    }

    /** Literal XPath seguro para cualquier valor (ids con comillas incluidos). */
    private static function literal(string $valor): string
    {
        if (!str_contains($valor, "'")) {
            return "'" . $valor . "'";
        }
        if (!str_contains($valor, '"')) {
            return '"' . $valor . '"';
        }
        return "concat('" . str_replace("'", "',\"'\",'", $valor) . "')";
    }
}
