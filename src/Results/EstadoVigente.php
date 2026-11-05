<?php

declare(strict_types=1);

namespace ProconSEO\Results;

/**
 * Estado vigente de un resultado (RF-42): el nuevo_estado de su última revisión con
 * decisión "cambiar_estado" o, si no tiene ninguna, el estado original. El resultado
 * original nunca se modifica.
 *
 * Revisión pendiente (RF-44): el resultado pide revisión humana y todavía no tiene ninguna,
 * o la última revisión pidió más evidencia.
 *
 * Son fragmentos SQL fijos (sin datos de entrada) para combinarlos en otras consultas.
 */
final class EstadoVigente
{
    /**
     * Subconsulta con una fila por resultado revisado: resultado_id, ultimo_estado (de la última
     * revisión "cambiar_estado") y ultima_decision (de la última revisión de cualquier tipo).
     * Úsala con LEFT JOIN (...) AS rev ON rev.resultado_id = r.id.
     */
    public const SUBCONSULTA_REVISIONES = <<<'SQL'
        SELECT resultado_id,
               MAX(CASE WHEN n_cambio = 1 THEN nuevo_estado END) AS ultimo_estado,
               MAX(CASE WHEN n_total = 1 THEN decision END)      AS ultima_decision
        FROM (
            SELECT resultado_id, decision, nuevo_estado,
                   CASE WHEN decision = 'cambiar_estado'
                        THEN ROW_NUMBER() OVER (PARTITION BY resultado_id, decision = 'cambiar_estado' ORDER BY creado_en DESC, id DESC)
                   END AS n_cambio,
                   ROW_NUMBER() OVER (PARTITION BY resultado_id ORDER BY creado_en DESC, id DESC) AS n_total
            FROM revisiones
        ) AS ordenadas
        GROUP BY resultado_id
        SQL;

    /** Expresión del estado vigente, con r = resultados y rev = SUBCONSULTA_REVISIONES. */
    public const EXPRESION_ESTADO = 'COALESCE(rev.ultimo_estado, r.estado)';

    /** Expresión (0/1) de revisión pendiente, con r = resultados y rev = SUBCONSULTA_REVISIONES. */
    public const EXPRESION_PENDIENTE = "(r.requiere_revision_humana = 1 AND (rev.ultima_decision IS NULL OR rev.ultima_decision = 'pedir_evidencia'))";
}
