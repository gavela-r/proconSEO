<?php

declare(strict_types=1);

namespace ProconSEO\Export;

use ProconSEO\Results\Etiquetas;
use ProconSEO\Results\Informe;
use ProconSEO\Results\Responsable;
use ProconSEO\Shared\Enum\Gravedad;

/**
 * CSV «por hallazgo»: una fila por comprobación y estado, con en cuántas páginas está y cuáles.
 * Es el que sirve para repartir el trabajo (una tarea por fila). El CSV completo, con una fila por
 * resultado, sigue en ExportadorCsv.
 *
 * Mismo formato que el completo (UTF-8 con BOM, punto y coma, CRLF) y la misma protección contra
 * la inyección de fórmulas. Sin notas ni porcentajes: solo recuentos.
 */
final class ExportadorCsvHallazgos
{
    public const COLUMNAS = [
        'Prioridad', 'Bloque', 'Comprobación', 'Nombre', 'Estado vigente', 'Gravedad', 'Esfuerzo', 'Responsable',
        'Páginas', 'Lista de páginas', 'Revisiones pendientes', 'Explicación', 'Corrección', 'Corrección en el CMS',
        'Referencia', 'Datos de prueba',
    ];

    private const ORDEN_ESTADOS = ['problema', 'decision_revisar', 'mejorable', 'no_verificable', 'correcto', 'no_aplicable'];
    private const BOM = "\xEF\xBB\xBF";

    /**
     * @param list<array<string, mixed>> $resultados filas con estado_vigente y título (Informe::conTitulos)
     */
    public static function generar(array $resultados, bool $datosDePrueba): string
    {
        $grupos = Informe::agrupar($resultados);
        usort($grupos, static fn (array $a, array $b): int => self::orden($a) <=> self::orden($b));

        $salida = fopen('php://temp', 'r+');
        if ($salida === false) {
            throw new \RuntimeException('No se puede generar el CSV.');
        }
        fputcsv($salida, self::COLUMNAS, ';', '"', '', "\r\n");
        foreach ($grupos as $i => $g) {
            $r = $g['principal'];
            $estado = (string) $r['estado_vigente'];
            $urls = array_values(array_unique(array_filter(array_map(static fn (array $o): string => (string) ($o['url_recurso'] ?? ''), $g['resultados']))));
            $incidencia = in_array($estado, ['problema', 'decision_revisar', 'mejorable'], true);
            $fila = [
                (string) ($i + 1),
                Etiquetas::bloque((string) $r['bloque']),
                (string) $r['id_comprobacion'],
                (string) ($r['titulo'] ?? $r['id_comprobacion']),
                Etiquetas::estado($estado),
                $estado === 'problema' ? (Gravedad::tryFrom((string) $r['gravedad'])?->etiqueta() ?? '') : '',
                (string) ($r['esfuerzo'] ?? ''),
                $incidencia ? Responsable::etiqueta($r) : '',
                (string) max(1, $g['paginas']),
                implode("\n", $urls),
                (string) count(array_filter($g['resultados'], static fn (array $o): bool => (bool) $o['revision_pendiente'])),
                (string) $r['explicacion_sencilla'],
                (string) ($r['correccion'] ?? ''),
                (string) ($r['correccion_cms'] ?? ''),
                Etiquetas::wcag($r) ?? Etiquetas::normativa($r) ?? '',
                $datosDePrueba ? 'SÍ' : 'No',
            ];
            fputcsv($salida, array_map(ExportadorCsv::neutralizar(...), $fila), ';', '"', '', "\r\n");
        }
        rewind($salida);
        $csv = (string) stream_get_contents($salida);
        fclose($salida);

        return self::BOM . $csv;
    }

    /**
     * De lo más urgente a lo menos: estado, gravedad y, a igualdad, el que está en más páginas.
     *
     * @param array{principal: array<string, mixed>, resultados: list<array<string, mixed>>, paginas: int} $g
     * @return list<int>
     */
    private static function orden(array $g): array
    {
        $r = $g['principal'];
        $estado = array_search($r['estado_vigente'], self::ORDEN_ESTADOS, true);
        $gravedad = $r['estado_vigente'] === 'problema' ? Gravedad::tryFrom((string) $r['gravedad']) : null;
        $rangoGravedad = $gravedad === null ? false : array_search($gravedad, Gravedad::cases(), true);

        return [$estado === false ? 9 : (int) $estado, $rangoGravedad === false ? 9 : (int) $rangoGravedad, -$g['paginas']];
    }
}
