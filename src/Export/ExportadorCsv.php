<?php

declare(strict_types=1);

namespace ProconSEO\Export;

use ProconSEO\Results\Etiquetas;

/**
 * Exportación a CSV para abrir en Excel (RF-50; decisión 17 de docs/decisiones.md):
 * UTF-8 con BOM, separado por punto y coma y con fin de línea CRLF.
 *
 * Es una sola tabla con una columna "Tipo" para poder filtrarla: aviso, resumen, resultado,
 * revisión, recurso no descargado y límite. Así el CSV conserva estados, evidencias,
 * revisiones, límites y fallos de acceso (RF-51) y la marca de datos de prueba (RF-52).
 *
 * Seguridad: toda celda que empiece por = + - @ tabulador o retorno de carro se neutraliza con
 * una comilla simple delante, para que Excel no la ejecute como fórmula (inyección CSV).
 */
final class ExportadorCsv
{
    public const COLUMNAS = [
        'Tipo', 'Bloque', 'Comprobación', 'Nombre', 'Estado vigente', 'Número', 'Estado original', 'Gravedad', 'Esfuerzo',
        'Revisión pendiente', 'Explicación', 'Consecuencia', 'Corrección', 'Corrección en el CMS', 'Página o recurso',
        'Elemento', 'Valor encontrado', 'Evidencias', 'Referencia', 'Límite', 'Cobertura', 'Ejecución', 'Revisiones',
        'Datos de prueba',
    ];

    private const BOM = "\xEF\xBB\xBF";

    public static function generar(array $datos): string
    {
        $prueba = $datos['datos_de_prueba'] ? 'SÍ' : 'No';
        $filas = [];

        if ($datos['datos_de_prueba']) {
            $filas[] = self::fila(['Tipo' => 'Aviso', 'Explicación' => (string) $datos['aviso'], 'Datos de prueba' => $prueba]);
        }

        $a = $datos['analisis'];
        $filas[] = self::fila([
            'Tipo' => 'Análisis',
            'Nombre' => (string) $a['proyecto']['nombre'],
            'Estado vigente' => (string) $a['estado'],
            'Página o recurso' => (string) ($a['url_final'] ?? $a['url_introducida']),
            'Explicación' => 'Alcance: ' . $a['alcance'] . ' · Perfil: ' . ($a['perfil_ejecucion'] ?? '—') . ' · Catálogo: ' . ($a['version_catalogo'] ?? '—') . ' · Fecha (UTC): ' . $a['creado_en_utc'],
            'Límite' => (string) ($a['motivo_cierre'] ?? ''),
            'Datos de prueba' => $prueba,
        ]);

        // Resumen: número de resultados por estado. No es una nota ni un porcentaje.
        foreach ($datos['resumen'] as $bloque => $estados) {
            foreach ($estados as $estado => $numero) {
                $filas[] = self::fila(['Tipo' => 'Resumen', 'Bloque' => $bloque, 'Estado vigente' => $estado, 'Número' => (string) $numero, 'Datos de prueba' => $prueba]);
            }
        }

        $evidencias = array_column($datos['evidencias'], null, 'codigo');
        $revisionesPorResultado = [];
        foreach ($datos['revisiones'] as $v) {
            $revisionesPorResultado[$v['resultado_id']][] = $v;
        }

        foreach ($datos['resultados'] as $r) {
            $revisiones = $revisionesPorResultado[$r['id']] ?? [];
            $filas[] = self::fila([
                'Tipo' => 'Resultado',
                'Bloque' => (string) $r['bloque'],
                'Comprobación' => (string) $r['comprobacion'],
                'Nombre' => (string) $r['nombre'],
                'Estado vigente' => (string) $r['estado_vigente'],
                'Estado original' => (string) $r['estado_original'],
                'Gravedad' => (string) ($r['gravedad'] ?? ''),
                'Esfuerzo' => (string) ($r['esfuerzo'] ?? ''),
                'Revisión pendiente' => $r['revision_pendiente'] ? 'Sí' : 'No',
                'Explicación' => (string) $r['explicacion_sencilla'],
                'Consecuencia' => (string) ($r['consecuencia'] ?? ''),
                'Corrección' => (string) ($r['correccion'] ?? ''),
                'Corrección en el CMS' => (string) ($r['correccion_cms'] ?? ''),
                'Página o recurso' => (string) ($r['url'] ?? ''),
                'Elemento' => (string) ($r['elemento'] ?? ''),
                'Valor encontrado' => (string) ($r['valor_observado'] ?? ''),
                'Evidencias' => implode("\n", array_map(
                    static fn (string $c): string => $c . ': ' . (string) ($evidencias[$c]['contenido'] ?? $evidencias[$c]['captura'] ?? ''),
                    $r['evidencias'],
                )),
                'Referencia' => self::referencia($r),
                'Límite' => (string) ($r['limitacion'] ?? ''),
                'Cobertura' => (string) $r['cobertura'],
                'Ejecución' => (string) $r['ejecucion'],
                'Revisiones' => (string) count($revisiones),
                'Datos de prueba' => $prueba,
            ]);

            foreach ($revisiones as $v) {
                $filas[] = self::fila([
                    'Tipo' => 'Revisión',
                    'Bloque' => (string) $r['bloque'],
                    'Comprobación' => (string) $r['comprobacion'],
                    'Nombre' => (string) $v['revisor'],
                    'Estado vigente' => (string) ($v['nuevo_estado'] ?? ''),
                    'Explicación' => 'Decisión: ' . $v['decision'] . ' · Motivo: ' . $v['motivo'],
                    'Consecuencia' => (string) $v['justificacion'],
                    'Evidencias' => implode("\n", array_map(
                        static fn (string $c): string => $c . ': ' . (string) ($evidencias[$c]['contenido'] ?? $evidencias[$c]['captura'] ?? ''),
                        $v['evidencias'],
                    )),
                    'Datos de prueba' => $prueba,
                ]);
            }
        }

        foreach ($datos['recursos'] as $rec) {
            if ($rec['resultado'] !== 'correcto' || $rec['truncado']) {
                $filas[] = self::fila([
                    'Tipo' => $rec['resultado'] !== 'correcto' ? 'Recurso no descargado' : 'Recurso incompleto',
                    'Página o recurso' => (string) $rec['url'],
                    'Estado vigente' => (string) $rec['resultado'],
                    'Valor encontrado' => $rec['estado_http'] === null ? '' : 'HTTP ' . $rec['estado_http'],
                    'Límite' => (string) ($rec['motivo'] ?? ($rec['truncado'] ? 'Superó el tamaño máximo: se analizó solo una parte.' : '')),
                    'Datos de prueba' => $prueba,
                ]);
            }
        }

        foreach ($datos['fases'] as $f) {
            if (in_array($f['estado'], ['omitida', 'parcial', 'fallida'], true)) {
                $filas[] = self::fila(['Tipo' => 'Límite', 'Nombre' => 'Fase ' . $f['fase'], 'Estado vigente' => (string) $f['estado'], 'Límite' => (string) ($f['mensaje'] ?? ''), 'Datos de prueba' => $prueba]);
            }
        }
        foreach ($datos['eventos_seguridad'] as $ev) {
            $filas[] = self::fila(['Tipo' => 'Límite', 'Nombre' => (string) $ev['regla'], 'Estado vigente' => (string) $ev['decision'], 'Página o recurso' => (string) ($ev['url'] ?? ''), 'Límite' => (string) $ev['condicion'], 'Datos de prueba' => $prueba]);
        }

        $salida = fopen('php://temp', 'r+');
        if ($salida === false) {
            throw new \RuntimeException('No se puede generar el CSV.');
        }
        fputcsv($salida, self::COLUMNAS, ';', '"', '', "\r\n");
        foreach ($filas as $fila) {
            fputcsv($salida, array_map(self::neutralizar(...), $fila), ';', '"', '', "\r\n");
        }
        rewind($salida);
        $csv = (string) stream_get_contents($salida);
        fclose($salida);

        return self::BOM . $csv;
    }

    /**
     * Evita la inyección de fórmulas: Excel ejecuta las celdas que empiezan por = + - @ (y,
     * según la versión, por tabulador o retorno de carro). Se antepone una comilla simple.
     */
    public static function neutralizar(string $celda): string
    {
        return $celda !== '' && in_array($celda[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $celda : $celda;
    }

    /** @param array<string, string> $valores */
    private static function fila(array $valores): array
    {
        return array_map(static fn (string $columna): string => $valores[$columna] ?? '', self::COLUMNAS);
    }

    private static function referencia(array $r): string
    {
        if ($r['wcag'] !== null) {
            return 'WCAG ' . $r['wcag']['criterio'] . ' ' . ($r['wcag']['nombre'] ?? '') . ' · ' . ($r['wcag']['nivel'] ?? '')
                . ' · ' . ((($r['wcag']['version'] ?? null) === '2.2' || in_array($r['wcag']['criterio'], Etiquetas::WCAG_NUEVOS_22, true)) ? 'nuevo en 2.2' : 'en 2.1');
        }

        return $r['normativa'] !== null ? 'Normativa: ' . $r['normativa'] : '';
    }
}
