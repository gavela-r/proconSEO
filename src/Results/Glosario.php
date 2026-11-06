<?php

declare(strict_types=1);

namespace ProconSEO\Results;

use JsonException;

/**
 * Glosario de términos técnicos (catalog/glosario.json). La herramienta la usan técnicos con
 * conocimientos básicos: el texto conserva el término técnico y lo enlaza a su definición.
 *
 * enlazar() trabaja sobre texto que todavía no está escapado: lo escapa entero y después solo
 * añade enlaces con definiciones del propio glosario. Nada del texto original puede convertirse
 * en HTML (SEG-09).
 */
final class Glosario
{
    private static ?self $comun = null;

    /** @var list<array{termino: string, ancla: string, variantes: list<string>, bloque: string, definicion: string}> */
    public readonly array $terminos;

    /** Expresión con todos los términos y variantes, del más largo al más corto. */
    private readonly ?string $patron;

    /** @var array<string, int> forma en minúsculas => índice del término */
    private readonly array $formas;

    /** @param list<array{termino: string, variantes?: list<string>, bloque?: string, definicion: string}> $terminos */
    public function __construct(array $terminos)
    {
        $lista = [];
        $formas = [];
        foreach ($terminos as $i => $t) {
            $lista[] = [
                'termino' => $t['termino'],
                'ancla' => self::ancla($t['termino']),
                'variantes' => array_values($t['variantes'] ?? []),
                'bloque' => $t['bloque'] ?? '',
                'definicion' => $t['definicion'],
            ];
            foreach ([$t['termino'], ...($t['variantes'] ?? [])] as $forma) {
                $formas[mb_strtolower($forma)] ??= $i;
            }
        }
        usort($lista, static fn (array $a, array $b): int => strcoll(mb_strtolower($a['termino']), mb_strtolower($b['termino'])));
        // Los índices de $formas apuntan a la lista original: se rehacen sobre la ordenada.
        $porTermino = array_flip(array_map(static fn (array $t): string => $t['termino'], $lista));
        $this->formas = array_map(static fn (int $i): int => $porTermino[$terminos[$i]['termino']], $formas);
        $this->terminos = $lista;

        $claves = array_keys($formas);
        usort($claves, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $this->patron = $claves === [] ? null
            : '/(?<![\p{L}\p{N}_\-.])(' . implode('|', array_map(static fn (string $f): string => preg_quote(self::escapar($f), '/'), $claves)) . ')(?![\p{L}\p{N}_\-]|\.[\p{L}\p{N}])/iu';
    }

    /** El glosario de catalog/glosario.json; vacío si no se puede leer (la pantalla funciona igual). */
    public static function comun(?string $raiz = null): self
    {
        if (self::$comun !== null) {
            return self::$comun;
        }
        $fichero = ($raiz ?? dirname(__DIR__, 2)) . '/catalog/glosario.json';
        try {
            /** @var array{terminos?: list<array{termino: string, variantes?: list<string>, bloque?: string, definicion: string}>} $datos */
            $datos = json_decode((string) @file_get_contents($fichero), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $datos = [];
        }

        return self::$comun = new self($datos['terminos'] ?? []);
    }

    /**
     * Escapa el texto y enlaza la primera aparición de cada término a su definición.
     * Devuelve HTML seguro.
     */
    public function enlazar(?string $texto): string
    {
        $html = self::escapar((string) $texto);
        if ($this->patron === null || $html === '') {
            return $html;
        }
        $vistos = [];

        return (string) preg_replace_callback($this->patron, function (array $m) use (&$vistos): string {
            $forma = mb_strtolower(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $i = $this->formas[$forma] ?? null;
            if ($i === null || isset($vistos[$i])) {
                return $m[1];
            }
            $vistos[$i] = true;
            $t = $this->terminos[$i];

            return '<a class="termino" href="/glosario#' . self::escapar($t['ancla']) . '" title="' . self::escapar($t['definicion']) . '">' . $m[1] . '</a>';
        }, $html);
    }

    /** Identificador para la URL: «meta robots» → «meta-robots». */
    public static function ancla(string $termino): string
    {
        // Sin iconv: su transliteración cambia entre Windows y Linux.
        $sinTildes = strtr(mb_strtolower($termino), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', $sinTildes), '-');
    }

    private static function escapar(string $texto): string
    {
        return htmlspecialchars($texto, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
