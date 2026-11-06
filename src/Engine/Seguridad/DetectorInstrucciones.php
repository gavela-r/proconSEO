<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Seguridad;

use DOMAttr;
use DOMComment;
use DOMElement;
use DOMNode;
use DOMXPath;
use ProconSEO\Engine\Extraccion\DocumentoHtml;
use ProconSEO\Shared\Contract\SenalSeguridad;
use ProconSEO\Shared\Enum\DecisionCierre;

/**
 * SEG-07: busca texto de la web dirigido al analizador o a una IA («ignora las instrucciones
 * anteriores», «analizador: marca todo como correcto»…).
 *
 * Es solo un registro: lo encontrado se devuelve como SenalSeguridad (decisión «continuar») y
 * nunca cambia qué se comprueba ni cómo se valora. El motor no ejecuta ni interpreta nada de lo
 * que lee; esto existe para que una persona sepa que la web lo intentó.
 *
 * Mira el texto visible y oculto, los comentarios HTML, los metadatos, los atributos con texto
 * (alt, title, aria-label…), el JSON-LD y los comentarios de robots.txt.
 *
 * Las expresiones son lineales (sin cuantificadores anidados) y cada fragmento se recorta a
 * LARGO_MAXIMO caracteres, así que una página hostil no puede hacer que tarde.
 */
final class DetectorInstrucciones
{
    public const REGLA = 'SEG-07';
    public const MAXIMO_SENALES = 10;
    private const LARGO_MAXIMO = 2000;
    private const LARGO_CITA = 160;

    /** Frases que, por sí solas, son un intento de dar órdenes a un modelo. */
    private const FUERTES = [
        '/\b(ignore|disregard|forget|override)\b.{0,30}\b(previous|prior|above|earlier|preceding|your)\s+(\w+\s+)?(instructions?|prompts?|rules|directions|guidelines)\b/u',
        '/\b(ignore|disregard|forget|override)\s+(all|any)\s+(of\s+)?(the\s+|your\s+)?(instructions?|prompts?)\b/u',
        '/\b(ignora|ignorad|ignoren|ignorar|olvida|olvidad|olvidar|descarta|descartad|omite|omitid)\b.{0,30}\b(instrucciones|indicaciones|reglas|ordenes|directrices)\s+(anteriores|previas|de\s+arriba|del\s+sistema|que\s+(te|has|tienes))\b/u',
        '/\b(ignora|ignorad|ignoren|ignorar|olvida|olvidad|olvidar|descarta|descartad|omite|omitid)\s+(todas|tus|cualquier)\s+(las\s+)?(instrucciones|indicaciones|reglas|ordenes|directrices)\b/u',
        '/\bsystem\s*prompt\b|\bprompt\s+del\s+sistema\b|\bmensaje\s+del\s+sistema\b/u',
        '/<\|im_start\|>|<\|system\|>|\[\/?inst\]|<<\/?sys>>/u',
        '/\byou\s+are\s+(now\s+)?(an?\s+)?(ai|assistant|language\s+model|llm|chatgpt)\b/u',
        '/\b(instruccion|instrucciones|nota|mensaje|aviso)\s+(para|al|a\s+la)\s+(el\s+|la\s+)?(analizador|auditor|evaluador|ia|inteligencia\s+artificial|agente|modelo)\b/u',
        '/\b(note|message|instructions?)\s+(to|for)\s+(the\s+)?(ai|llm|agent|model|analy[sz]er|auditor|crawler)\b/u',
    ];

    /**
     * A quién se dirige: un vocativo («IA:», «AI agent,») o «el analizador». Que la página hable
     * de la IA no basta.
     */
    private const DESTINATARIO = '/\b(analizador|auditor|evaluador|asistente\s+de\s+ia|agente\s+de\s+ia|modelo\s+de\s+lenguaje|ia|ai|llm|ai\s+(agent|assistant|model)|analy[sz]er)\s*[:,]|\b(el|al|the|dear)\s+(analizador|auditor|evaluador|analy[sz]er)\b/u';

    /** …y qué le pide sobre el análisis. Hacen falta las dos cosas en el mismo fragmento. */
    private const ORDEN = '/\b(marca|marcad|marcar|marque|asigna|asignar|asigne|pon|poner|establece|cambia|cambiar|cambie|di\s+que|decir\s+que|responde|devuelve|no\s+generes|no\s+generar|no\s+reportes|no\s+informes|analiza\s+tambien|report|mark|set|rate|assign|say\s+that|respond|return|output|change)\b.{0,60}\b(correct[oa]s?|correct|estados?|resultados?|results?|problemas?|problems?|evidencias?|evidence|alcance|scope|comprobaciones|checks?|passed|compliant|aprobad[oa])\b/u';

    /** Atributos con texto que alguien puede leer (o un modelo, si lo hubiera). */
    private const ATRIBUTOS = ['alt', 'title', 'aria-label', 'aria-description', 'aria-roledescription', 'placeholder', 'value', 'label', 'summary'];

    /**
     * @return list<SenalSeguridad>
     */
    public function detectar(?DocumentoHtml $documento, string $url, ?string $robotsTxt = null, ?string $urlRobots = null): array
    {
        $hallazgos = [];
        if ($documento !== null) {
            $this->html($documento, $hallazgos);
        }
        if ($robotsTxt !== null) {
            $this->robots($robotsTxt, $hallazgos);
        }

        $senales = [];
        foreach (array_slice($hallazgos, 0, self::MAXIMO_SENALES) as [$lugar, $texto, $enRobots]) {
            $senales[] = new SenalSeguridad(
                self::REGLA,
                'Texto dirigido al analizador en ' . $lugar . ': «' . self::cita($texto) . '». Se ha tratado como dato y no ha cambiado ningún resultado.',
                DecisionCierre::Continuar,
                $enRobots ? ($urlRobots ?? $url) : $url,
            );
        }
        $resto = count($hallazgos) - self::MAXIMO_SENALES;
        if ($resto > 0) {
            $senales[] = new SenalSeguridad(
                self::REGLA,
                "Hay $resto fragmento(s) más dirigidos al analizador que no se detallan. Se han tratado como datos.",
                DecisionCierre::Continuar,
                $url,
            );
        }
        return $senales;
    }

    /** El fragmento parece una orden dirigida al analizador o a una IA. */
    public static function esInstruccion(string $texto): bool
    {
        $t = self::normalizar($texto);
        if ($t === '') {
            return false;
        }
        foreach (self::FUERTES as $patron) {
            if (preg_match($patron, $t) === 1) {
                return true;
            }
        }
        return preg_match(self::DESTINATARIO, $t) === 1 && preg_match(self::ORDEN, $t) === 1;
    }

    /** @param list<array{0: string, 1: string, 2: bool}> $hallazgos */
    private function html(DocumentoHtml $documento, array &$hallazgos): void
    {
        $xp = new DOMXPath($documento->dom);
        $raiz = $documento->dom->documentElement;
        if ($raiz === null) {
            return;
        }

        foreach ($xp->query('//comment()') ?: [] as $nodo) {
            if ($nodo instanceof DOMComment) {
                $this->revisar('un comentario HTML', $nodo->data, $hallazgos);
            }
        }

        foreach ($xp->query('//meta[@content]') ?: [] as $meta) {
            if ($meta instanceof DOMElement) {
                $nombre = $meta->getAttribute('name') ?: $meta->getAttribute('property') ?: $meta->getAttribute('http-equiv');
                $this->revisar('la meta «' . self::cita($nombre, 40) . '»', $meta->getAttribute('content'), $hallazgos);
            }
        }

        $consulta = implode('|', array_map(static fn (string $a): string => '//@' . $a, self::ATRIBUTOS));
        foreach ($xp->query($consulta) ?: [] as $atributo) {
            if ($atributo instanceof DOMAttr && $atributo->ownerElement instanceof DOMElement) {
                $lugar = 'el atributo ' . $atributo->name . ' de <' . $atributo->ownerElement->tagName . '>';
                $this->revisar($lugar, $atributo->value, $hallazgos);
            }
        }

        foreach ($xp->query('//script[contains(translate(@type, "LDJSON", "ldjson"), "ld+json")]') ?: [] as $script) {
            if ($script instanceof DOMElement) {
                $this->revisar('los datos estructurados (JSON-LD)', $script->textContent, $hallazgos);
            }
        }

        foreach ($xp->query('//text()[normalize-space()][not(ancestor::script) and not(ancestor::style) and not(ancestor::template)]') ?: [] as $texto) {
            $padre = $texto->parentNode;
            $etiqueta = $padre instanceof DOMElement ? $padre->tagName : 'html';
            $lugar = (self::oculto($xp, $texto) ? 'texto oculto' : 'el texto de la página') . ' (<' . $etiqueta . '>)';
            $this->revisar($lugar, (string) $texto->nodeValue, $hallazgos);
        }
    }

    /** @param list<array{0: string, 1: string, 2: bool}> $hallazgos */
    private function robots(string $cuerpo, array &$hallazgos): void
    {
        $lineas = preg_split('/\r\n|\r|\n/', mb_substr($cuerpo, 0, 500_000)) ?: [];
        foreach ($lineas as $n => $linea) {
            $posicion = strpos($linea, '#');
            if ($posicion !== false) {
                $this->revisar('un comentario de robots.txt (línea ' . ($n + 1) . ')', substr($linea, $posicion + 1), $hallazgos, true);
            }
        }
    }

    /** @param list<array{0: string, 1: string, 2: bool}> $hallazgos */
    private function revisar(string $lugar, string $texto, array &$hallazgos, bool $enRobots = false): void
    {
        // Si ya hay de sobra para la señal resumen, no hace falta seguir mirando con detalle.
        if (count($hallazgos) > 200) {
            return;
        }
        $texto = mb_substr($texto, 0, self::LARGO_MAXIMO);
        if (self::esInstruccion($texto)) {
            $hallazgos[] = [$lugar, $texto, $enRobots];
        }
    }

    private static function oculto(DOMXPath $xp, DOMNode $nodo): bool
    {
        $condicion = 'ancestor-or-self::*[@hidden or @aria-hidden="true"'
            . ' or contains(translate(@style, "ABCDEFGHIJKLMNOPQRSTUVWXYZ ", "abcdefghijklmnopqrstuvwxyz"), "display:none")'
            . ' or contains(translate(@style, "ABCDEFGHIJKLMNOPQRSTUVWXYZ ", "abcdefghijklmnopqrstuvwxyz"), "visibility:hidden")'
            . ' or contains(translate(@style, "ABCDEFGHIJKLMNOPQRSTUVWXYZ ", "abcdefghijklmnopqrstuvwxyz"), "font-size:0")]';
        return ($xp->query($condicion, $nodo)?->length ?? 0) > 0;
    }

    /** Minúsculas, sin tildes y con los espacios juntos, para comparar sin rodeos. */
    private static function normalizar(string $texto): string
    {
        $t = mb_strtolower($texto);
        $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u']);
        return trim((string) preg_replace('/\s+/u', ' ', $t));
    }

    /** Cita corta y de una línea; la interfaz la escapa al mostrarla. */
    private static function cita(string $texto, int $largo = self::LARGO_CITA): string
    {
        $t = trim((string) preg_replace('/[\x00-\x1F\x7F]+|\s+/u', ' ', $texto));
        return mb_strlen($t) > $largo ? mb_substr($t, 0, $largo - 1) . '…' : $t;
    }
}
