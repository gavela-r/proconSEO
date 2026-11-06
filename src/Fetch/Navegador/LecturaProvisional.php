<?php

declare(strict_types=1);

namespace ProconSEO\Fetch\Navegador;

use DOMDocument;
use DOMXPath;
use ProconSEO\Shared\Enum\EstadoResultado;
use ProconSEO\Shared\Enum\Gravedad;

/**
 * PROTOTIPO: convierte las mediciones del navegador en resultados con los estados del
 * proyecto, para poder probarlo con bin/navegador.php antes de integrarlo en el motor.
 *
 * Cuando se integre, esta lectura pasa a ser comprobaciones de la Parte 2 (catálogo y motor),
 * y este fichero desaparece. Mientras tanto sigue las mismas reglas: sin notas ni porcentajes,
 * y lo que no se puede medir es no_verificable con su motivo, nunca correcto.
 */
final class LecturaProvisional
{
    /** Etiquetas de contenido que pueden necesitar desplazamiento en dos dimensiones (excepción de 1.4.10). */
    private const BIDIMENSIONALES = ['table', 'pre', 'code', 'img', 'svg', 'canvas', 'video', 'iframe', 'map', 'object', 'embed'];

    /**
     * @return list<array{id: string, titulo: string, estado: EstadoResultado, gravedad: ?Gravedad, detalle: string, evidencias: list<string>}>
     */
    public static function leer(PaginaRenderizada $p): array
    {
        if (!$p->esCorrecta()) {
            $motivo = 'No se pudo pintar la página en el navegador: ' . $p->error;
            $r = [];
            foreach (self::titulos() as $id => $titulo) {
                $r[] = self::resultado($id, $titulo, EstadoResultado::NoVerificable, null, $motivo);
            }

            return $r;
        }

        return [...self::contraste($p), ...self::foco($p), self::reflow($p), self::contenidoJs($p)];
    }

    /** @return array<string, string> */
    public static function titulos(): array
    {
        return [
            'ACC-05.contraste-texto' => 'Contraste del texto (1.4.3)',
            'ACC-06.foco-visible' => 'Foco visible con el teclado (2.4.7)',
            'ACC-06.trampa-teclado' => 'Sin trampas de teclado (2.1.2)',
            'ACC-04.reflow-320' => 'Sin desplazamiento horizontal a 320 px (1.4.10)',
            'SEO-09.contenido-tras-js' => 'Contenido en el HTML inicial',
        ];
    }

    /** @return list<array{id: string, titulo: string, estado: EstadoResultado, gravedad: ?Gravedad, detalle: string, evidencias: list<string>}> */
    private static function contraste(PaginaRenderizada $p): array
    {
        $id = 'ACC-05.contraste-texto';
        $titulo = self::titulos()[$id];
        $c = $p->contraste;
        if ($c === null || $c['muestras'] === []) {
            return [self::resultado($id, $titulo, EstadoResultado::NoVerificable, null, 'No se encontró texto visible que medir.')];
        }

        $medidas = 0;
        $fallos = [];
        $sinMedir = [];
        foreach ($c['muestras'] as $m) {
            if (is_string($m['motivo'] ?? null)) {
                $sinMedir[$m['motivo']][] = $m;
                continue;
            }
            /** @var array{color: list<float|int>, opacidad?: float|int, capas: list<list<float|int>>, tamano: float|int, peso: int} $datos */
            $datos = $m;
            $medida = Contraste::medir($datos);
            $medidas++;
            if (!$medida['cumple']) {
                // Una entrada por combinación de colores y tamaño: el mismo fallo en 40 enlaces es una sola causa.
                $clave = Contraste::hex($medida['texto']) . Contraste::hex($medida['fondo']) . (string) $medida['minimo'];
                $fallos[$clave] ??= ['medida' => $medida, 'ejemplos' => [], 'veces' => 0];
                $fallos[$clave]['veces']++;
                if (count($fallos[$clave]['ejemplos']) < 3) {
                    $fallos[$clave]['ejemplos'][] = '«' . (string) $m['texto'] . '» (' . (string) $m['selector'] . ')';
                }
            }
        }

        $resultados = [];
        if ($medidas === 0) {
            $resultados[] = self::resultado($id, $titulo, EstadoResultado::NoVerificable, null, 'Ningún texto se pudo medir: todos están sobre imágenes, degradados o con efectos.');
        } elseif ($fallos === []) {
            $resultados[] = self::resultado($id, $titulo, EstadoResultado::Correcto, null, ($medidas === 1 ? 'El texto medido cumple' : "Los {$medidas} textos medidos cumplen") . ' el contraste mínimo (4,5:1, o 3:1 en texto grande).');
        } else {
            uasort($fallos, static fn (array $a, array $b): int => $a['medida']['ratio'] <=> $b['medida']['ratio']);
            $peor = reset($fallos)['medida'];
            $evidencias = [];
            $textos = 0;
            foreach ($fallos as $f) {
                $textos += $f['veces'];
                $m = $f['medida'];
                $evidencias[] = sprintf('%s sobre %s: %s:1 (mínimo %s:1), %d %s. Ej.: %s',
                    Contraste::hex($m['texto']), Contraste::hex($m['fondo']), self::num($m['ratio']), self::num($m['minimo']),
                    $f['veces'], $f['veces'] === 1 ? 'texto' : 'textos', implode(', ', $f['ejemplos']));
            }
            // Alto si algún texto normal no llega ni a 3:1 (se lee con dificultad); si no, medio.
            $gravedad = $peor['minimo'] === Contraste::MINIMO_NORMAL && $peor['ratio'] < 3.0 ? Gravedad::Alto : Gravedad::Medio;
            $resultados[] = self::resultado($id, $titulo, EstadoResultado::Problema, $gravedad,
                "{$textos} de {$medidas} textos medidos no llegan al contraste mínimo, en " . count($fallos) . ' combinaciones de colores.', array_slice($evidencias, 0, 10));
        }

        if ($sinMedir !== []) {
            $evidencias = [];
            $total = 0;
            foreach ($sinMedir as $motivo => $lista) {
                $total += count($lista);
                $evidencias[] = $motivo . ': ' . count($lista) . '. Ej.: «' . (string) $lista[0]['texto'] . '» (' . (string) $lista[0]['selector'] . ')';
            }
            $resultados[] = self::resultado('ACC-05.contraste-sobre-imagen', 'Contraste del texto sobre imágenes o efectos', EstadoResultado::NoVerificable, null,
                "{$total} textos no se pueden medir de forma automática porque su fondo no es un color liso: hay que revisarlos a mano.", $evidencias);
        }
        $extra = [];
        if (($c['tapados'] ?? 0) > 0) {
            $extra[] = $c['tapados'] . ' textos tapados por otros elementos no se han medido.';
        }
        if (($c['inactivos'] ?? 0) > 0) {
            $extra[] = $c['inactivos'] . ' textos de controles desactivados no se exigen (excepción de 1.4.3).';
        }
        if ($extra !== []) {
            $resultados[0]['evidencias'] = [...$resultados[0]['evidencias'], ...$extra];
        }

        return $resultados;
    }

    /** @return list<array{id: string, titulo: string, estado: EstadoResultado, gravedad: ?Gravedad, detalle: string, evidencias: list<string>}> */
    private static function foco(PaginaRenderizada $p): array
    {
        $tVisible = self::titulos()['ACC-06.foco-visible'];
        $tTrampa = self::titulos()['ACC-06.trampa-teclado'];
        $f = $p->foco;
        if ($f === null) {
            return [
                self::resultado('ACC-06.foco-visible', $tVisible, EstadoResultado::NoVerificable, null, 'No se pudo recorrer la página con el teclado.'),
                self::resultado('ACC-06.trampa-teclado', $tTrampa, EstadoResultado::NoVerificable, null, 'No se pudo recorrer la página con el teclado.'),
            ];
        }
        if ($f['pasos'] === []) {
            $estado = $f['enfocables'] === 0 ? EstadoResultado::NoAplicable : EstadoResultado::NoVerificable;
            $texto = $f['enfocables'] === 0 ? 'La página no tiene elementos que se puedan usar con el teclado.'
                : "La página tiene {$f['enfocables']} elementos enfocables, pero el tabulador no llegó a ninguno: hay que revisarlo a mano.";

            return [self::resultado('ACC-06.foco-visible', $tVisible, $estado, null, $texto), self::resultado('ACC-06.trampa-teclado', $tTrampa, $estado, null, $texto)];
        }

        $resultados = [];
        $sin = [];
        $ocultos = [];
        $evaluados = 0;
        foreach ($f['pasos'] as $i => $paso) {
            $ind = $f['indicadores'][$paso['indice'] ?? $i] ?? null;
            $desc = self::elemento($paso);
            if (($paso['visible'] ?? true) === false) {
                $ocultos[$desc] = true;
                continue;
            }
            if ($ind === null) {
                continue;
            }
            $evaluados++;
            if ($ind === false) {
                $sin[$desc] = true;
            }
        }
        if ($evaluados === 0) {
            $resultados[] = self::resultado('ACC-06.foco-visible', $tVisible, EstadoResultado::NoVerificable, null, 'No se pudo comparar el aspecto de los elementos con y sin foco.');
        } elseif ($sin === []) {
            $resultados[] = self::resultado('ACC-06.foco-visible', $tVisible, EstadoResultado::Correcto, null, ($evaluados === 1 ? 'El elemento recorrido con el tabulador cambia' : "Los {$evaluados} elementos recorridos con el tabulador cambian") . ' de aspecto al recibir el foco.');
        } else {
            $resultados[] = self::resultado('ACC-06.foco-visible', $tVisible, EstadoResultado::Problema, count($sin) * 2 >= $evaluados ? Gravedad::Alto : Gravedad::Medio,
                count($sin) . " de {$evaluados} elementos no cambian de aspecto al recibir el foco: quien usa el teclado no sabe dónde está.", array_slice(array_keys($sin), 0, 10));
        }
        if ($ocultos !== []) {
            $resultados[] = self::resultado('ACC-06.foco-oculto', 'El foco no va a elementos que no se ven (2.4.11)', EstadoResultado::Mejorable, null,
                count($ocultos) . ' veces el foco pasa por elementos invisibles o fuera de la pantalla (menús cerrados, enlaces ocultos…).', array_slice(array_keys($ocultos), 0, 10));
        }

        $repetidos = 0;
        $atrapado = null;
        foreach ($f['pasos'] as $paso) {
            $repetidos = ($paso['mismo'] ?? false) === true ? $repetidos + 1 : 0;
            if ($repetidos >= 3) {
                $atrapado = self::elemento($paso);
                break;
            }
        }
        if ($atrapado !== null) {
            $resultados[] = self::resultado('ACC-06.trampa-teclado', $tTrampa, EstadoResultado::Problema, Gravedad::Alto,
                'El foco se queda atrapado: al pulsar el tabulador no sale de un elemento.', [$atrapado]);
        } elseif ($f['vueltaCompleta']) {
            $resultados[] = self::resultado('ACC-06.trampa-teclado', $tTrampa, EstadoResultado::Correcto, null,
                'Con el tabulador se recorre la página entera y se vuelve al principio (' . self::cuantos(count($f['pasos']), 'elemento', 'elementos') . ').');
        } else {
            $resultados[] = self::resultado('ACC-06.trampa-teclado', $tTrampa, EstadoResultado::NoVerificable, null,
                'La página tiene más elementos de los que se recorren (' . count($f['pasos']) . '); en los recorridos no hay trampas.');
        }

        return $resultados;
    }

    /** @return array{id: string, titulo: string, estado: EstadoResultado, gravedad: ?Gravedad, detalle: string, evidencias: list<string>} */
    private static function reflow(PaginaRenderizada $p): array
    {
        $id = 'ACC-04.reflow-320';
        $titulo = self::titulos()[$id];
        $r = $p->reflow;
        if ($r === null) {
            return self::resultado($id, $titulo, EstadoResultado::NoVerificable, null, 'No se pudo medir la página a 320 px.');
        }
        if ($r['total'] <= $r['ancho'] + 1) {
            return self::resultado($id, $titulo, EstadoResultado::Correcto, null, 'Con 320 px de ancho (equivale a un zoom del 400 %) la página se adapta sin desplazamiento horizontal.');
        }
        $evidencias = array_map(static fn (array $c): string => "{$c['selector']} llega hasta {$c['derecha']} px", $r['culpables']);
        $todosBidimensionales = $r['culpables'] !== [] && array_reduce($r['culpables'], static fn (bool $ok, array $c): bool => $ok && in_array($c['etiqueta'], self::BIDIMENSIONALES, true), true);
        if ($todosBidimensionales) {
            return self::resultado($id, $titulo, EstadoResultado::DecisionRevisar, null,
                "A 320 px la página mide {$r['total']} px de ancho por tablas, imágenes o código. WCAG lo admite si ese contenido necesita las dos dimensiones: hay que decidirlo.", $evidencias);
        }

        return self::resultado($id, $titulo, EstadoResultado::Problema, Gravedad::Medio,
            "A 320 px la página mide {$r['total']} px de ancho: con zoom hay que desplazarse en horizontal para leerla.", $evidencias);
    }

    /** @return array{id: string, titulo: string, estado: EstadoResultado, gravedad: ?Gravedad, detalle: string, evidencias: list<string>} */
    private static function contenidoJs(PaginaRenderizada $p): array
    {
        $id = 'SEO-09.contenido-tras-js';
        $titulo = self::titulos()[$id];
        if ($p->htmlInicial === null || $p->palabrasVisibles === null) {
            return self::resultado($id, $titulo, EstadoResultado::NoVerificable, null, 'No se pudo comparar el HTML inicial con la página pintada.');
        }
        $inicial = self::palabrasHtml($p->htmlInicial);
        $pintada = $p->palabrasVisibles;
        $evidencia = ["HTML inicial: {$inicial} palabras. Página pintada: {$pintada} palabras visibles."];
        if ($inicial < 50 && $pintada >= 200) {
            return self::resultado($id, $titulo, EstadoResultado::Problema, Gravedad::Alto,
                'Casi todo el contenido aparece al ejecutar JavaScript. Google lo puede ver, pero los rastreadores de IA (GPTBot, ClaudeBot, PerplexityBot…) no ejecutan JavaScript y verán la página vacía.', $evidencia);
        }
        if ($pintada >= 2 * max(1, $inicial) && $pintada - $inicial >= 150) {
            return self::resultado($id, $titulo, EstadoResultado::Mejorable, null,
                'Una parte importante del contenido aparece al ejecutar JavaScript: los rastreadores que no lo ejecutan (muchos de IA) no la verán.', $evidencia);
        }

        return self::resultado($id, $titulo, EstadoResultado::Correcto, null, 'El contenido principal ya está en el HTML que envía el servidor.', $evidencia);
    }

    /** Palabras de texto del HTML tal como llega, sin scripts ni estilos. */
    public static function palabrasHtml(string $html): int
    {
        if (trim($html) === '') {
            return 0;
        }
        $doc = new DOMDocument();
        $previo = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previo);
        $xp = new DOMXPath($doc);
        foreach ($xp->query('//script|//style|//noscript|//template|//head') ?: [] as $n) {
            $n->parentNode?->removeChild($n);
        }
        // Nodo a nodo y con espacios: textContent pegaría «dos</p><p>tres» en una sola palabra.
        $texto = '';
        foreach ($xp->query('//text()') ?: [] as $n) {
            $texto .= ' ' . $n->nodeValue;
        }

        return (int) preg_match_all('/[\p{L}\p{N}]+/u', $texto);
    }

    /** @param array<string, mixed> $paso */
    private static function elemento(array $paso): string
    {
        $nombre = is_string($paso['nombre'] ?? null) && $paso['nombre'] !== '' ? ' «' . $paso['nombre'] . '»' : '';

        return (string) ($paso['selector'] ?? $paso['etiqueta'] ?? '?') . $nombre;
    }

    private static function cuantos(int $n, string $uno, string $varios): string
    {
        return $n . ' ' . ($n === 1 ? $uno : $varios);
    }

    private static function num(float $n): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.'));
    }

    /**
     * @param list<string> $evidencias
     * @return array{id: string, titulo: string, estado: EstadoResultado, gravedad: ?Gravedad, detalle: string, evidencias: list<string>}
     */
    private static function resultado(string $id, string $titulo, EstadoResultado $estado, ?Gravedad $gravedad, string $detalle, array $evidencias = []): array
    {
        return ['id' => $id, 'titulo' => $titulo, 'estado' => $estado, 'gravedad' => $estado === EstadoResultado::Problema ? $gravedad : null, 'detalle' => $detalle, 'evidencias' => $evidencias];
    }
}
