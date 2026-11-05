<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Comprobaciones;

use DOMElement;
use DOMXPath;
use ProconSEO\Engine\Comprobacion;
use ProconSEO\Engine\Entorno;
use ProconSEO\Engine\Extraccion\AnalizadorHtml;
use ProconSEO\Engine\Extraccion\Url;
use ProconSEO\Shared\Contract\BorradorResultado;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\Gravedad;
use ProconSEO\Shared\Enum\TipoConclusion;
use ProconSEO\Shared\Enum\TipoEvidencia;
use RuntimeException;

/**
 * ACC-12 (4.1.2): nombre, rol y estado de los controles, ARIA válido, iframes con título,
 * ids duplicados que se usan como referencia y detección de chatbots (especificación 6.8).
 */
final class AccesibilidadAria implements Comprobacion
{
    /** Roles de WAI-ARIA 1.2 (sin los abstractos). */
    private const ROLES = [
        'alert', 'alertdialog', 'application', 'article', 'banner', 'blockquote', 'button', 'caption', 'cell', 'checkbox',
        'code', 'columnheader', 'combobox', 'complementary', 'contentinfo', 'definition', 'deletion', 'dialog', 'directory',
        'document', 'emphasis', 'feed', 'figure', 'form', 'generic', 'grid', 'gridcell', 'group', 'heading', 'img', 'image',
        'insertion', 'link', 'list', 'listbox', 'listitem', 'log', 'main', 'marquee', 'math', 'meter', 'menu', 'menubar',
        'menuitem', 'menuitemcheckbox', 'menuitemradio', 'navigation', 'none', 'note', 'option', 'paragraph', 'presentation',
        'progressbar', 'radio', 'radiogroup', 'region', 'row', 'rowgroup', 'rowheader', 'scrollbar', 'search', 'searchbox',
        'separator', 'slider', 'spinbutton', 'status', 'strong', 'subscript', 'superscript', 'switch', 'tab', 'table',
        'tablist', 'tabpanel', 'term', 'textbox', 'time', 'timer', 'toolbar', 'tooltip', 'tree', 'treegrid', 'treeitem',
        // Roles DPUB-ARIA y Graphics-ARIA, válidos aunque poco frecuentes.
        'doc-abstract', 'doc-chapter', 'doc-footnote', 'doc-noteref', 'doc-toc', 'graphics-document', 'graphics-object', 'graphics-symbol',
    ];

    /** Atributos aria-* de WAI-ARIA 1.2. */
    private const ATRIBUTOS = [
        'aria-activedescendant', 'aria-atomic', 'aria-autocomplete', 'aria-braillelabel', 'aria-brailleroledescription',
        'aria-busy', 'aria-checked', 'aria-colcount', 'aria-colindex', 'aria-colindextext', 'aria-colspan', 'aria-controls',
        'aria-current', 'aria-describedby', 'aria-description', 'aria-details', 'aria-disabled', 'aria-dropeffect',
        'aria-errormessage', 'aria-expanded', 'aria-flowto', 'aria-grabbed', 'aria-haspopup', 'aria-hidden', 'aria-invalid',
        'aria-keyshortcuts', 'aria-label', 'aria-labelledby', 'aria-level', 'aria-live', 'aria-modal', 'aria-multiline',
        'aria-multiselectable', 'aria-orientation', 'aria-owns', 'aria-placeholder', 'aria-posinset', 'aria-pressed',
        'aria-readonly', 'aria-relevant', 'aria-required', 'aria-roledescription', 'aria-rowcount', 'aria-rowindex',
        'aria-rowindextext', 'aria-rowspan', 'aria-selected', 'aria-setsize', 'aria-sort', 'aria-valuemax', 'aria-valuemin',
        'aria-valuenow', 'aria-valuetext',
    ];

    /** Roles de controles que necesitan nombre accesible. */
    private const ROLES_CON_NOMBRE = ['button', 'checkbox', 'combobox', 'link', 'menuitem', 'menuitemcheckbox', 'menuitemradio',
        'option', 'radio', 'searchbox', 'slider', 'spinbutton', 'switch', 'tab', 'textbox', 'treeitem'];

    /** Señales de widgets de chat (ids, clases y dominios de scripts habituales). */
    private const CHATBOT = '/chatbot|chat-?widget|chat-?launcher|livechat|live-chat|webchat|tawk|tidio|crisp|intercom|drift|zendesk|zopim|hubspot-messages|botpress|landbot|freshchat|smartsupp|whatsapp-?(button|widget)|asistente-?virtual/i';

    public function subcomprobaciones(): array
    {
        return [
            'ACC-12.nombres-controles' => Cobertura::Implementada,
            'ACC-12.roles-aria-validos' => Cobertura::Implementada,
            'ACC-12.iframe-title' => Cobertura::Implementada,
            'ACC-12.ids-referenciados-duplicados' => Cobertura::ParcialmenteImplementada,
            'ACC-12.chatbot-detectado' => Cobertura::ParcialmenteImplementada,
        ];
    }

    public function evaluar(string $id, Entorno $e): BorradorResultado
    {
        return match ($id) {
            'ACC-12.nombres-controles' => $this->nombres($e),
            'ACC-12.roles-aria-validos' => $this->roles($e),
            'ACC-12.iframe-title' => $this->iframes($e),
            'ACC-12.ids-referenciados-duplicados' => $this->idsDuplicados($e),
            'ACC-12.chatbot-detectado' => $this->chatbot($e),
            default => throw new RuntimeException("Subcomprobación desconocida: $id"),
        };
    }

    private static function etiqueta(DOMElement $el): string
    {
        $atributos = '';
        foreach (['id', 'class', 'role', 'type'] as $a) {
            if ($el->hasAttribute($a)) {
                $atributos .= ' ' . $a . '="' . mb_substr($el->getAttribute($a), 0, 40) . '"';
            }
        }
        return '<' . $el->nodeName . $atributos . '>';
    }

    /** @return list<DOMElement> controles visibles para las tecnologías de apoyo */
    private function controles(DOMXPath $xp, ?DOMElement $dentro = null): array
    {
        $roles = implode(' or ', array_map(static fn (string $r): string => '@role="' . $r . '"', self::ROLES_CON_NOMBRE));
        $consulta = './/button|.//input[@type="submit" or @type="button" or @type="reset"]|.//*[' . $roles . '][not(self::a) and not(self::input) and not(self::select) and not(self::textarea)]';
        $lista = [];
        foreach ($xp->query($consulta, $dentro ?? $xp->document->documentElement) ?: [] as $el) {
            if ($el instanceof DOMElement && $xp->query('ancestor-or-self::*[@aria-hidden="true" or @hidden]', $el)?->length === 0) {
                $lista[] = $el;
            }
        }
        return $lista;
    }

    private function nombres(Entorno $e): BorradorResultado
    {
        $xp = new DOMXPath($e->documento()->dom);
        $analizador = new AnalizadorHtml();
        $controles = $this->controles($xp);
        $v = $e->resultado('ACC-12.nombres-controles');
        if ($controles === []) {
            return $v->noAplicable('La página no tiene botones ni controles personalizados.');
        }
        $sin = array_values(array_filter($controles, static fn (DOMElement $c): bool => $analizador->nombreAccesible($xp, $c) === ''));
        if ($sin === []) {
            return $v->valor('Los ' . count($controles) . ' controles tienen nombre accesible.')->correcto();
        }
        $lista = array_map([self::class, 'etiqueta'], $sin);
        return $v->valor(count($sin) . ' de ' . count($controles) . ' controles sin nombre accesible.')
            ->elemento($lista[0])
            ->evidencia($e->evidencia(TipoEvidencia::FragmentoHtml, implode("\n", array_slice($lista, 0, 20))))
            ->consecuencia('El lector de pantalla anuncia «botón» sin decir qué hace: la persona no puede usarlo con seguridad.')
            ->correccion('Dar a cada control un texto visible o, si solo tiene un icono, un aria-label con la acción («Cerrar», «Abrir menú»).')
            ->problema(Gravedad::Alto);
    }

    private function roles(Entorno $e): BorradorResultado
    {
        $doc = $e->documento();
        $xp = new DOMXPath($doc->dom);
        $errores = [];
        foreach ($doc->roles as $r) {
            $tokens = preg_split('/\s+/', strtolower($r['role'])) ?: [];
            // Se usa el primer rol válido de la lista; si ninguno lo es, el rol se ignora.
            if ($tokens !== [] && array_intersect($tokens, self::ROLES) === []) {
                $errores[] = '<' . $r['etiqueta'] . ' role="' . $r['role'] . '">: rol que no existe';
            }
        }
        $atributosVistos = 0;
        foreach ($xp->query('//*[@*[starts-with(name(), "aria-")]]') ?: [] as $el) {
            if (!$el instanceof DOMElement) {
                continue;
            }
            foreach ($el->attributes ?? [] as $atributo) {
                $nombre = strtolower($atributo->nodeName);
                if (!str_starts_with($nombre, 'aria-')) {
                    continue;
                }
                $atributosVistos++;
                if (!in_array($nombre, self::ATRIBUTOS, true)) {
                    $errores[] = '<' . $el->nodeName . ' ' . $nombre . '>: atributo ARIA que no existe';
                }
            }
        }
        $v = $e->resultado('ACC-12.roles-aria-validos');
        if ($doc->roles === [] && $atributosVistos === 0) {
            return $v->noAplicable('La página no usa roles ni atributos ARIA.');
        }
        $v->valor(count($doc->roles) . ' roles y ' . $atributosVistos . ' atributos ARIA; ' . count($errores) . ' no válidos.')
            ->limitacion('Se comprueba que existan; que el rol encaje con el elemento y sus estados necesita navegador.');
        if ($errores === []) {
            return $v->correcto();
        }
        return $v->elemento($errores[0])
            ->evidencia($e->evidencia(TipoEvidencia::FragmentoHtml, implode("\n", array_slice($errores, 0, 20))))
            ->consecuencia('Las tecnologías de apoyo ignoran los roles y atributos que no existen: el elemento se anuncia como algo genérico y no se sabe para qué sirve.')
            ->correccion('Usar elementos nativos (<button>, <nav>…) o roles y atributos de la especificación WAI-ARIA, bien escritos.')
            ->problema(Gravedad::Medio);
    }

    private function iframes(Entorno $e): BorradorResultado
    {
        $visibles = array_values(array_filter($e->documento()->iframes, static fn (array $f): bool => !$f['oculto']));
        $v = $e->resultado('ACC-12.iframe-title');
        if ($visibles === []) {
            return $v->noAplicable('La página no tiene iframes visibles.');
        }
        $sin = array_values(array_filter($visibles, static fn (array $f): bool => trim($f['nombre']) === ''));
        if ($sin === []) {
            return $v->valor('Los ' . count($visibles) . ' iframes tienen título.')->correcto();
        }
        $lista = array_map(static fn (array $f): string => '<iframe src="' . ($f['src'] ?? '') . '">', $sin);
        return $v->valor(count($sin) . ' iframe(s) sin title.')
            ->elemento($lista[0])
            ->evidencia($e->evidencia(TipoEvidencia::FragmentoHtml, implode("\n", $lista)))
            ->consecuencia('El lector de pantalla anuncia «marco» sin decir qué contiene (mapa, vídeo, formulario…).')
            ->correccion('Añadir title a cada iframe, p. ej. title="Mapa de cómo llegar".')
            ->problema(Gravedad::Medio);
    }

    private function idsDuplicados(Entorno $e): BorradorResultado
    {
        $doc = $e->documento();
        $v = $e->resultado('ACC-12.ids-referenciados-duplicados');
        if ($doc->idsDuplicados === []) {
            return $v->valor('No hay ids duplicados.')->correcto();
        }
        $xp = new DOMXPath($doc->dom);
        $referenciados = [];
        foreach ($xp->query('//label[@for]|//*[@aria-labelledby]|//*[@aria-describedby]|//*[@aria-controls]') ?: [] as $el) {
            if (!$el instanceof DOMElement) {
                continue;
            }
            $ids = array_merge(
                [$el->getAttribute('for')],
                preg_split('/\s+/', trim($el->getAttribute('aria-labelledby') . ' ' . $el->getAttribute('aria-describedby') . ' ' . $el->getAttribute('aria-controls'))) ?: [],
            );
            foreach (array_intersect($ids, $doc->idsDuplicados) as $id) {
                $referenciados[$id] = true;
            }
        }
        $v->valor(count($doc->idsDuplicados) . ' id(s) duplicados; ' . count($referenciados) . ' se usan en etiquetas o ARIA.');
        if ($referenciados === []) {
            return $v->limitacion('Los ids duplicados que no se usan como referencia no afectan a la accesibilidad (4.1.1 desapareció en WCAG 2.2).')->correcto();
        }
        return $v->elemento('id="' . array_key_first($referenciados) . '"')
            ->evidencia($e->evidencia(TipoEvidencia::Texto, 'Duplicados referenciados: ' . implode(', ', array_keys($referenciados))))
            ->consecuencia('Una etiqueta o descripción que apunta a un id repetido puede asociarse al elemento equivocado.')
            ->correccion('Dar un id único a cada elemento y apuntar for, aria-labelledby y aria-describedby al correcto.')
            ->problema(Gravedad::Medio);
    }

    private function chatbot(Entorno $e): BorradorResultado
    {
        $doc = $e->documento();
        $xp = new DOMXPath($doc->dom);
        $contenedores = [];
        foreach ($xp->query('//body//*[@id or @class]') ?: [] as $el) {
            if ($el instanceof DOMElement && preg_match(self::CHATBOT, $el->getAttribute('id') . ' ' . $el->getAttribute('class')) === 1) {
                // Solo el contenedor más externo de cada widget.
                $dentro = false;
                foreach ($contenedores as $c) {
                    if ($xp->query('ancestor::*', $el) !== false && in_array($c, iterator_to_array($xp->query('ancestor::*', $el) ?: []), true)) {
                        $dentro = true;
                    }
                }
                if (!$dentro) {
                    $contenedores[] = $el;
                }
            }
        }
        $scripts = array_values(array_filter($doc->recursos, static fn (array $r): bool => $r['tipo'] === 'script' && preg_match(self::CHATBOT, (string) Url::host($r['url']) . (string) parse_url($r['url'], PHP_URL_PATH)) === 1));
        $v = $e->resultado('ACC-12.chatbot-detectado');
        if ($contenedores === [] && $scripts === []) {
            return $v->noAplicable('No se detecta ningún chatbot.');
        }
        if ($contenedores === []) {
            return $v->valor('Script de chat detectado: ' . $scripts[0]['url'])
                ->conclusion(TipoConclusion::Inferencia)
                ->revisionHumana('El botón del chat lo crea JavaScript: hay que probarlo con teclado y lector de pantalla.')
                ->noVerificable('El widget se construye con JavaScript; sin navegador no se puede revisar su botón.');
        }

        $analizador = new AnalizadorHtml();
        $fallos = [];
        foreach ($contenedores as $c) {
            foreach ($this->controles($xp, $c) as $control) {
                if ($analizador->nombreAccesible($xp, $control) === '') {
                    $fallos[] = 'Botón del chat sin nombre: ' . self::etiqueta($control);
                }
            }
            foreach ($xp->query('.//iframe', $c) ?: [] as $f) {
                if ($f instanceof DOMElement && trim($f->getAttribute('title')) === '' && trim($f->getAttribute('aria-label')) === '') {
                    $fallos[] = 'Ventana del chat (iframe) sin title: ' . self::etiqueta($f);
                }
            }
        }
        $v->valor('Chatbot detectado: ' . self::etiqueta($contenedores[0]) . ($scripts !== [] ? ' (script ' . Url::host($scripts[0]['url']) . ')' : '') . '; ' . count($fallos) . ' fallo(s) en su estado inicial.')
            ->limitacion('Solo se revisa el estado inicial (botón y ventana); la conversación necesita navegador.');
        if ($fallos === []) {
            return $v->correcto();
        }
        return $v->elemento($fallos[0])
            ->evidencia($e->evidencia(TipoEvidencia::FragmentoHtml, implode("\n", $fallos)))
            ->consecuencia('Una persona que usa lector de pantalla no sabe que hay un chat ni cómo abrirlo, ni qué es la ventana que aparece.')
            ->correccion('Dar al botón que abre el chat un nombre («Abrir chat de ayuda») y a su iframe un title («Chat de ayuda»).')
            ->problema(Gravedad::Alto);
    }
}
