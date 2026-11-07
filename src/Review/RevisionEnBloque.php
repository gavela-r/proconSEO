<?php

declare(strict_types=1);

namespace ProconSEO\Review;

/**
 * A qué otros resultados se puede aplicar la misma revisión de una vez: la misma comprobación,
 * en el mismo análisis, con el mismo estado vigente y sin ninguna revisión humana todavía.
 *
 * Así, si una plantilla repite el mismo hallazgo en 28 páginas, se revisa una vez. Lo que ya ha
 * revisado alguien no se toca: esa decisión es suya.
 */
final class RevisionEnBloque
{
    /**
     * @param list<array<string, mixed>> $todos resultados del análisis (RepositorioResultados::resultados)
     * @param array<string, mixed>       $fila  el resultado que se está revisando
     * @return list<array<string, mixed>>
     */
    public static function iguales(array $todos, array $fila): array
    {
        return array_values(array_filter($todos, static fn (array $o): bool => $o['id_comprobacion'] === $fila['id_comprobacion']
            && (int) $o['analisis_id'] === (int) $fila['analisis_id']
            && $o['estado_vigente'] === $fila['estado_vigente']
            && !$o['revisado']
            && (int) $o['id'] !== (int) $fila['id']));
    }
}
