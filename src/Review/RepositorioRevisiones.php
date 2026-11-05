<?php

declare(strict_types=1);

namespace ProconSEO\Review;

use PDO;

/**
 * Guarda revisiones humanas (RF-41, RF-42). Solo INSERTA: nunca modifica el resultado original
 * ni una revisión anterior. La evidencia que aporta el revisor se guarda como evidencia nueva
 * del análisis, vinculada a la revisión (revision_id). Solo sentencias preparadas.
 */
final class RepositorioRevisiones
{
    public function __construct(private readonly PDO $bd)
    {
    }

    /**
     * @param array{ruta: string, mime: string, bytes: int}|null $captura captura ya validada y guardada
     * @return int id de la revisión
     */
    public function guardar(int $resultadoId, int $analisisId, DatosRevision $datos, ?array $captura): int
    {
        $this->bd->beginTransaction();
        try {
            // Bloquea el análisis para numerar las evidencias (E-007, E-008…) sin repetir códigos.
            $this->bd->prepare('SELECT id FROM analisis WHERE id = ? FOR UPDATE')->execute([$analisisId]);

            $anterior = $this->bd->prepare('SELECT id FROM revisiones WHERE resultado_id = ? ORDER BY creado_en DESC, id DESC LIMIT 1');
            $anterior->execute([$resultadoId]);
            $sustituyeA = $anterior->fetchColumn();

            $this->bd->prepare(
                'INSERT INTO revisiones (resultado_id, revisor, motivo, decision, nuevo_estado, justificacion, sustituye_a)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $resultadoId, $datos->revisor, $datos->motivo, $datos->decision->value,
                $datos->nuevoEstado?->value, $datos->justificacion, $sustituyeA === false ? null : (int) $sustituyeA,
            ]);
            $revisionId = (int) $this->bd->lastInsertId();

            $evidencia = $this->bd->prepare(
                'INSERT INTO evidencias (analisis_id, codigo, tipo, contenido, ruta_fichero, tipo_mime, revision_id) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            if ($datos->evidenciaTexto !== null) {
                $evidencia->execute([$analisisId, $this->siguienteCodigo($analisisId), 'texto', $datos->evidenciaTexto, null, null, $revisionId]);
            }
            if ($captura !== null) {
                $evidencia->execute([$analisisId, $this->siguienteCodigo($analisisId), 'captura', null, $captura['ruta'], $captura['mime'], $revisionId]);
            }

            $this->bd->commit();
        } catch (\Throwable $e) {
            $this->bd->rollBack();
            throw $e;
        }

        return $revisionId;
    }

    /** Captura añadida por un revisor, para servirla. @return array{ruta_fichero: string, tipo_mime: string}|null */
    public function captura(int $evidenciaId): ?array
    {
        $consulta = $this->bd->prepare(
            "SELECT ruta_fichero, tipo_mime FROM evidencias
             WHERE id = ? AND tipo = 'captura' AND revision_id IS NOT NULL AND ruta_fichero IS NOT NULL"
        );
        $consulta->execute([$evidenciaId]);
        $fila = $consulta->fetch();

        return $fila === false ? null : ['ruta_fichero' => (string) $fila['ruta_fichero'], 'tipo_mime' => (string) $fila['tipo_mime']];
    }

    /** Siguiente código E-NNN del análisis. Se llama con el análisis bloqueado. */
    private function siguienteCodigo(int $analisisId): string
    {
        $consulta = $this->bd->prepare(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(codigo, 3) AS UNSIGNED)), 0) FROM evidencias WHERE analisis_id = ? AND codigo REGEXP '^E-[0-9]+$'"
        );
        $consulta->execute([$analisisId]);

        return sprintf('E-%03d', (int) $consulta->fetchColumn() + 1);
    }
}
