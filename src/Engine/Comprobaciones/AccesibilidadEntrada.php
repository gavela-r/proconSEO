<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Comprobaciones;

use DOMElement;
use DOMNode;
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
 * ACC-08 (entrada): lo que se puede revisar sin navegador.
 *
 * - ACC-08.etiqueta-en-nombre (WCAG 2.5.3), regla ACT 2ee8b8: si un control tiene un nombre
 *   puesto a mano (aria-label o aria-labelledby), ese nombre contiene el texto que se ve. Así,
 *   quien maneja la web con la voz puede decir lo que lee en pantalla («pulsar Comprar»).
 */
final class AccesibilidadEntrada implements Comprobacion
{
    /** Roles cuyo nombre sale del contenido: el texto que se ve es su etiqueta. */
    private const ROLES = ['button', 'checkbox', 'gridcell', 'link', 'menuitem', 'menuitemcheckbox', 'menuitemradio',
        'option', 'radio', 'switch', 'tab', 'treeitem'];

    /** Elementos cuyo contenido no se ve como texto. */
    private const SIN_TEXTO = ['script', 'style', 'template', 'noscript', 'svg', 'img', 'iframe', 'video', 'audio', 'canvas', 'select', 'textarea'];

    public function subcomprobaciones(): array
    {
        return [
            // Sin navegador no se ven el CSS (texto oculto o generado con ::before) ni las etiquetas
            // que están fuera del control.
            'ACC-08.etiqueta-en-nombre' => Cobertura::ParcialmenteImplementada,
        ];
    }

    public function evaluar(string $id, Entorno $entorno): BorradorResultado
    {
        return match ($id) {
            'ACC-08.etiqueta-en-nombre' => $this->etiquetaEnNombre($entorno),
            default => throw new RuntimeException("Subcomprobación desconocida: $id"),
        };
    }

    /**
     * Texto que se compara: minúsculas, sin signos de puntuación ni símbolos y con los espacios
     * juntos. «Comprar ya!» y «comprar ya» cuentan como iguales (ACT 2ee8b8).
     */
    public static function comparable(string $texto): string
    {
        $texto = mb_strtolower($texto);
        $texto = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $texto);
        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }

    /** Si el nombre contiene la etiqueta visible palabra por palabra. */
    public static function contiene(string $nombre, string $visible): bool
    {
        $n = self::comparable($nombre);
        $v = self::comparable($visible);
        return $v === '' || str_contains(' ' . $n . ' ', ' ' . $v . ' ');
    }

    private function etiquetaEnNombre(Entorno $e): BorradorResultado
    {
        $xp = new DOMXPath($e->documento()->dom);
        $analizador = new AnalizadorHtml();
        $v = $e->resultado('ACC-08.etiqueta-en-nombre')
            ->limitacion('Sin navegador no se tiene en cuenta el CSS: texto oculto, texto añadido con ::before o ::after, ni etiquetas que están fuera del control.');

        $revisados = 0;
        $fallos = [];
        foreach ($this->candidatos($xp) as $el) {
            $visible = $this->textoVisible($el);
            // Un solo carácter o solo símbolos («×», «>») no es una etiqueta que se pueda decir.
            if (mb_strlen(str_replace(' ', '', self::comparable($visible))) < 2) {
                continue;
            }
            ++$revisados;
            $nombre = $analizador->nombreAccesible($xp, $el);
            if (!self::contiene($nombre, $visible)) {
                $fallos[] = ['elemento' => self::etiqueta($el), 'visible' => self::corto($visible), 'nombre' => self::corto($nombre)];
            }
        }

        if ($revisados === 0) {
            return $v->noAplicable('Ningún control con texto visible tiene un nombre puesto con aria-label o aria-labelledby.');
        }
        if ($fallos === []) {
            return $v->valor("En los $revisados controles con aria-label o aria-labelledby, el nombre incluye el texto que se ve.")->correcto();
        }

        $lineas = array_map(static fn (array $f): string => $f['elemento'] . ' se ve «' . $f['visible'] . '» pero se llama «' . $f['nombre'] . '»', $fallos);
        $primero = $fallos[0];
        return $v->valor(count($fallos) . " de $revisados controles se llaman distinto de lo que se ve.")
            ->elemento($primero['elemento'])
            ->explicacion('El nombre que oyen el lector de pantalla y el control por voz no coincide con el texto del botón o enlace.')
            ->evidencia($e->evidencia(TipoEvidencia::FragmentoHtml, implode("\n", array_slice($lineas, 0, 20))))
            ->consecuencia('Quien maneja la web con la voz dice «' . $primero['visible'] . '» y no pasa nada, porque el control se llama «' . $primero['nombre'] . '».')
            ->correccion('Hacer que el aria-label empiece por el texto visible («' . $primero['visible'] . '», o «' . $primero['visible'] . ', abre en otra pestaña»), o quitar el aria-label si el texto ya basta.')
            ->correccionCms([
                'wordpress' => 'Suele venir de un tema o plugin que pone aria-label en botones y enlaces del menú: corregirlo en sus ajustes o en el bloque del botón (Avanzado → atributos).',
            ])
            ->problema(Gravedad::Medio);
    }

    /** @return list<DOMElement> controles con nombre puesto a mano y visibles para las tecnologías de apoyo */
    private function candidatos(DOMXPath $xp): array
    {
        $roles = implode(' or ', array_map(static fn (string $r): string => '@role="' . $r . '"', self::ROLES));
        $consulta = '//*[@aria-label or @aria-labelledby][self::a[@href] or self::button or self::summary or ' . $roles . ']';
        $lista = [];
        foreach ($xp->query($consulta) ?: [] as $el) {
            if ($el instanceof DOMElement && $xp->query('ancestor-or-self::*[@aria-hidden="true" or @hidden]', $el)?->length === 0) {
                $lista[] = $el;
            }
        }
        return $lista;
    }

    /** Texto que se ve dentro del control: sin scripts, sin lo oculto y sin el texto de imágenes. */
    private function textoVisible(DOMNode $nodo): string
    {
        $texto = '';
        foreach ($nodo->childNodes as $hijo) {
            if ($hijo->nodeType === XML_TEXT_NODE) {
                $texto .= $hijo->nodeValue;
            } elseif ($hijo instanceof DOMElement) {
                if (in_array($hijo->nodeName, self::SIN_TEXTO, true) || $hijo->hasAttribute('hidden')
                    || preg_match('/display\s*:\s*none|visibility\s*:\s*hidden/i', $hijo->getAttribute('style')) === 1) {
                    continue;
                }
                $texto .= ' ' . $this->textoVisible($hijo) . ' ';
            }
        }
        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }

    private static function etiqueta(DOMElement $el): string
    {
        $atributos = '';
        foreach (['id', 'class', 'role', 'aria-label', 'aria-labelledby'] as $a) {
            if ($el->hasAttribute($a)) {
                $atributos .= ' ' . $a . '="' . mb_substr($el->getAttribute($a), 0, 60) . '"';
            }
        }
        return '<' . $el->nodeName . $atributos . '>';
    }

    private static function corto(string $texto): string
    {
        return mb_strlen($texto) > 80 ? mb_substr($texto, 0, 79) . '…' : $texto;
    }
}
