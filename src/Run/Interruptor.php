<?php

declare(strict_types=1);

namespace ProconSEO\Run;

use PDO;

/**
 * SEG-11: interruptor general. Al activarlo se detienen todos los análisis en cola y en curso
 * (con la cancelación normal, que conserva lo obtenido) y no se pueden lanzar más.
 */
final class Interruptor
{
    private const CLAVE = 'interruptor_general';

    public function __construct(
        private readonly PDO $bd,
        private readonly RegistroAuditoria $auditoria,
    ) {
    }

    public function activo(): bool
    {
        return $this->motivo() !== null;
    }

    /** Motivo con el que se activó, o null si está desactivado. */
    public function motivo(): ?string
    {
        $consulta = $this->bd->prepare('SELECT valor FROM ajustes_sistema WHERE clave = ?');
        $consulta->execute([self::CLAVE]);
        $valor = $consulta->fetchColumn();

        return $valor === false ? null : (string) $valor;
    }

    /** @return int Análisis que se han mandado detener */
    public function activar(string $motivo, string $quien): int
    {
        $motivo = trim($motivo) === '' ? 'Sin motivo indicado' : mb_substr(trim($motivo), 0, 500);
        $this->bd->prepare('INSERT INTO ajustes_sistema (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)')
            ->execute([self::CLAVE, $motivo]);

        $cierre = 'Detenido por el interruptor general: ' . $motivo;
        $enCola = $this->bd->prepare("UPDATE analisis SET estado = 'cancelado', cancelacion_pedida = 1, terminado_en = NOW(3), motivo_cierre = ? WHERE estado = 'en_cola'");
        $enCola->execute([$cierre]);
        $enCurso = $this->bd->prepare("UPDATE analisis SET estado = 'cancelando', cancelacion_pedida = 1 WHERE estado = 'en_curso'");
        $enCurso->execute();
        $detenidos = $enCola->rowCount() + $enCurso->rowCount();

        $this->auditoria->registrar(RegistroAuditoria::INTERRUPTOR_ACTIVADO, regla: 'SEG-11', detalle: "{$motivo} (por {$quien}; {$detenidos} análisis detenidos)");

        return $detenidos;
    }

    public function desactivar(string $quien): void
    {
        $this->bd->prepare('DELETE FROM ajustes_sistema WHERE clave = ?')->execute([self::CLAVE]);
        $this->auditoria->registrar(RegistroAuditoria::INTERRUPTOR_DESACTIVADO, regla: 'SEG-11', detalle: "Por {$quien}");
    }
}
