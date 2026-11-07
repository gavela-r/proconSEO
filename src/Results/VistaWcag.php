<?php

declare(strict_types=1);

namespace ProconSEO\Results;

/**
 * Tabla de criterios WCAG 2.2 de nivel A y AA con lo que dice el análisis de cada uno.
 *
 * Sirve para preparar la declaración de accesibilidad: de un vistazo, qué criterios tienen
 * problemas, cuáles faltan por revisar y cuáles no comprueba la herramienta (y hay que mirar a
 * mano). Nunca dice «cumple»: como mucho «sin problemas detectados», porque una revisión
 * automática no lo puede garantizar. Sin notas ni porcentajes.
 */
final class VistaWcag
{
    /** Criterios de WCAG 2.2 de nivel A y AA (el 4.1.1 se retiró en la 2.2). [número, nombre, nivel, nuevo en 2.2] */
    public const CRITERIOS = [
        ['1.1.1', 'Contenido no textual', 'A', false],
        ['1.2.1', 'Solo audio y solo vídeo (grabado)', 'A', false],
        ['1.2.2', 'Subtítulos (grabados)', 'A', false],
        ['1.2.3', 'Audiodescripción o medio alternativo (grabado)', 'A', false],
        ['1.2.4', 'Subtítulos (en directo)', 'AA', false],
        ['1.2.5', 'Audiodescripción (grabada)', 'AA', false],
        ['1.3.1', 'Información y relaciones', 'A', false],
        ['1.3.2', 'Secuencia significativa', 'A', false],
        ['1.3.3', 'Características sensoriales', 'A', false],
        ['1.3.4', 'Orientación', 'AA', false],
        ['1.3.5', 'Identificar el propósito de la entrada', 'AA', false],
        ['1.4.1', 'Uso del color', 'A', false],
        ['1.4.2', 'Control del audio', 'A', false],
        ['1.4.3', 'Contraste (mínimo)', 'AA', false],
        ['1.4.4', 'Cambio de tamaño del texto', 'AA', false],
        ['1.4.5', 'Imágenes de texto', 'AA', false],
        ['1.4.10', 'Reajuste del contenido', 'AA', false],
        ['1.4.11', 'Contraste no textual', 'AA', false],
        ['1.4.12', 'Espaciado del texto', 'AA', false],
        ['1.4.13', 'Contenido con hover o foco', 'AA', false],
        ['2.1.1', 'Teclado', 'A', false],
        ['2.1.2', 'Sin trampas para el foco del teclado', 'A', false],
        ['2.1.4', 'Atajos de teclado de un solo carácter', 'A', false],
        ['2.2.1', 'Tiempo ajustable', 'A', false],
        ['2.2.2', 'Pausar, detener, ocultar', 'A', false],
        ['2.3.1', 'Umbral de tres destellos o menos', 'A', false],
        ['2.4.1', 'Evitar bloques', 'A', false],
        ['2.4.2', 'Titulado de páginas', 'A', false],
        ['2.4.3', 'Orden del foco', 'A', false],
        ['2.4.4', 'Propósito de los enlaces (en contexto)', 'A', false],
        ['2.4.5', 'Múltiples vías', 'AA', false],
        ['2.4.6', 'Encabezados y etiquetas', 'AA', false],
        ['2.4.7', 'Foco visible', 'AA', false],
        ['2.4.11', 'Foco no oculto (mínimo)', 'AA', true],
        ['2.5.1', 'Gestos del puntero', 'A', false],
        ['2.5.2', 'Cancelación del puntero', 'A', false],
        ['2.5.3', 'Etiqueta en el nombre', 'A', false],
        ['2.5.4', 'Activación mediante movimiento', 'A', false],
        ['2.5.7', 'Movimientos de arrastre', 'AA', true],
        ['2.5.8', 'Tamaño del objetivo (mínimo)', 'AA', true],
        ['3.1.1', 'Idioma de la página', 'A', false],
        ['3.1.2', 'Idioma de las partes', 'AA', false],
        ['3.2.1', 'Al recibir el foco', 'A', false],
        ['3.2.2', 'Al recibir entradas', 'A', false],
        ['3.2.3', 'Navegación coherente', 'AA', false],
        ['3.2.4', 'Identificación coherente', 'AA', false],
        ['3.2.6', 'Ayuda coherente', 'A', true],
        ['3.3.1', 'Identificación de errores', 'A', false],
        ['3.3.2', 'Etiquetas o instrucciones', 'A', false],
        ['3.3.3', 'Sugerencias ante errores', 'AA', false],
        ['3.3.4', 'Prevención de errores (legales, financieros, datos)', 'AA', false],
        ['3.3.7', 'Entrada redundante', 'A', true],
        ['3.3.8', 'Autenticación accesible (mínimo)', 'AA', true],
        ['4.1.2', 'Nombre, función, valor', 'A', false],
        ['4.1.3', 'Mensajes de estado', 'AA', false],
    ];

    /** Situación de un criterio, de peor a mejor, con su texto. */
    public const SITUACIONES = [
        'problemas' => 'Hay problemas',
        'revisar' => 'Falta revisar',
        'mejorable' => 'Mejorable',
        'sin_problemas' => 'Sin problemas detectados',
        'no_aplica' => 'No aplica',
        'sin_comprobar' => 'No lo comprueba la herramienta',
    ];

    /**
     * @var list<array{numero: string, nombre: string, nivel: string, nuevo22: bool, situacion: string,
     *                 recuento: array<string, int>, paginas: int, comprobaciones: list<array{id: int, titulo: string, estado: string, paginas: int, total: int}>,
     *                 manual: array<string, mixed>|null, situacion_automatica: string}>
     */
    public readonly array $criterios;

    /** @var array<string, int> cuántos criterios hay en cada situación */
    public readonly array $resumen;

    /**
     * @param list<array<string, mixed>>          $resultados filas con estado_vigente y la referencia WCAG ya completada (Informe::conTitulos)
     * @param array<string, array<string, mixed>> $manuales revisión manual vigente de cada criterio (RevisionesManuales::vigentes)
     */
    public function __construct(array $resultados, array $manuales = [])
    {
        $porCriterio = [];
        foreach ($resultados as $r) {
            $criterio = trim((string) ($r['wcag_criterio'] ?? ''));
            if ($criterio !== '') {
                $porCriterio[$criterio][] = $r;
            }
        }

        $criterios = [];
        foreach (self::CRITERIOS as [$numero, $nombre, $nivel, $nuevo]) {
            $lista = $porCriterio[$numero] ?? [];
            $recuento = [];
            foreach ($lista as $r) {
                $recuento[(string) $r['estado_vigente']] = ($recuento[(string) $r['estado_vigente']] ?? 0) + 1;
            }
            $criterios[] = [
                'numero' => $numero,
                'nombre' => $nombre,
                'nivel' => $nivel,
                'nuevo22' => $nuevo,
                'situacion' => self::conManual(self::situacion($lista), $manuales[$numero] ?? null),
                'situacion_automatica' => self::situacion($lista),
                'manual' => $manuales[$numero] ?? null,
                'recuento' => $recuento,
                'paginas' => count(array_unique(array_filter(array_map(static fn (array $r): string => (string) ($r['url_recurso'] ?? ''), $lista)))),
                'comprobaciones' => self::comprobaciones($lista),
            ];
        }
        $this->criterios = $criterios;
        $resumen = array_fill_keys(array_keys(self::SITUACIONES), 0);
        foreach ($criterios as $c) {
            $resumen[$c['situacion']]++;
        }
        $this->resumen = $resumen;
    }

    /** De peor a mejor, para quedarse con el peor estado de cada comprobación. */
    private const ORDEN = ['problema', 'decision_revisar', 'mejorable', 'no_verificable', 'correcto', 'no_aplicable'];

    /**
     * Una línea por comprobación con su peor estado y en cuántas páginas está así (de cuántas).
     *
     * @param list<array<string, mixed>> $lista
     * @return list<array{id: int, titulo: string, estado: string, paginas: int, total: int}>
     */
    private static function comprobaciones(array $lista): array
    {
        $porId = [];
        foreach ($lista as $r) {
            $porId[(string) $r['id_comprobacion']][] = $r;
        }
        $rango = static function (string $estado): int {
            $i = array_search($estado, self::ORDEN, true);

            return $i === false ? 9 : (int) $i;
        };
        $paginas = static fn (array $filas): int => max(1, count(array_unique(array_map(static fn (array $r): string => (string) ($r['url_recurso'] ?? ''), $filas))));

        $salida = [];
        foreach ($porId as $filas) {
            usort($filas, static fn (array $a, array $b): int => $rango((string) $a['estado_vigente']) <=> $rango((string) $b['estado_vigente']));
            $peor = $filas[0];
            $conPeor = array_values(array_filter($filas, static fn (array $r): bool => $r['estado_vigente'] === $peor['estado_vigente']));
            $salida[] = [
                'id' => (int) $peor['id'],
                'titulo' => (string) ($peor['titulo'] ?? $peor['id_comprobacion']),
                'estado' => (string) $peor['estado_vigente'],
                'paginas' => $paginas($conPeor),
                'total' => $paginas($filas),
            ];
        }
        usort($salida, static fn (array $a, array $b): int => $rango($a['estado']) <=> $rango($b['estado']));

        return $salida;
    }

    /**
     * Lo que una persona ha revisado a mano completa a la herramienta, pero no tapa un problema
     * que la herramienta ha encontrado: si hay problemas automáticos, sigue habiendo problemas.
     *
     * @param array<string, mixed>|null $manual
     */
    private static function conManual(string $automatica, ?array $manual): string
    {
        if ($manual === null || $automatica === 'problemas') {
            return $automatica;
        }

        return match ((string) $manual['resultado']) {
            'problema' => 'problemas',
            'correcto' => 'sin_problemas',
            'no_aplicable' => 'no_aplica',
            default => $automatica,
        };
    }

    /** @param list<array<string, mixed>> $lista */
    private static function situacion(array $lista): string
    {
        if ($lista === []) {
            return 'sin_comprobar';
        }
        $estados = array_map(static fn (array $r): string => (string) $r['estado_vigente'], $lista);
        $pendiente = array_filter($lista, static fn (array $r): bool => (bool) ($r['revision_pendiente'] ?? false)) !== [];

        return match (true) {
            in_array('problema', $estados, true) => 'problemas',
            $pendiente || in_array('decision_revisar', $estados, true) || in_array('no_verificable', $estados, true) => 'revisar',
            in_array('mejorable', $estados, true) => 'mejorable',
            in_array('correcto', $estados, true) => 'sin_problemas',
            default => 'no_aplica',
        };
    }
}
