<?php

declare(strict_types=1);

namespace ProconSEO\Run;

use PDO;

/**
 * SEG-15: registro de auditoría. Quién lanzó cada análisis (su IP), cuándo, qué se bloqueó
 * y por qué. Solo inserta: a propósito no hay ningún método para modificar ni borrar.
 */
final class RegistroAuditoria
{
    public const ANALISIS_LANZADO = 'analisis_lanzado';
    public const LANZAMIENTO_RECHAZADO = 'lanzamiento_rechazado';
    public const CANCELACION_PEDIDA = 'cancelacion_pedida';
    public const BLOQUEO_SEGURIDAD = 'bloqueo_seguridad';
    public const INTERRUPTOR_ACTIVADO = 'interruptor_activado';
    public const INTERRUPTOR_DESACTIVADO = 'interruptor_desactivado';

    private readonly SanitizadorEvidencias $sanitizador;

    public function __construct(private readonly PDO $bd)
    {
        $this->sanitizador = new SanitizadorEvidencias();
    }

    public function registrar(
        string $accion,
        ?int $analisisId = null,
        ?int $proyectoId = null,
        ?string $ip = null,
        ?string $url = null,
        ?string $regla = null,
        ?string $detalle = null,
    ): void {
        [$url, $detalle] = $this->limpiar($url, $detalle);
        $this->bd->prepare('INSERT INTO registro_auditoria (accion, analisis_id, proyecto_id, ip_origen, url, regla, detalle) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$accion, $analisisId, $proyectoId, $ip, $url, $regla, $detalle]);
    }

    /** Bloqueo durante la ejecución: copia el proyecto y la IP del análisis, por si este se borra. */
    public function bloqueo(int $analisisId, ?string $url, string $regla, string $detalle): void
    {
        [$url, $detalle] = $this->limpiar($url, $detalle);
        $this->bd->prepare(
            'INSERT INTO registro_auditoria (accion, analisis_id, proyecto_id, ip_origen, url, regla, detalle)
             SELECT ?, id, proyecto_id, ip_origen, ?, ?, ? FROM analisis WHERE id = ?'
        )->execute([self::BLOQUEO_SEGURIDAD, $url, $regla, $detalle, $analisisId]);
    }

    /** @return list<array<string, mixed>> Las últimas entradas, de la más reciente a la más antigua */
    public function ultimas(int $cuantas = 50): array
    {
        $consulta = $this->bd->prepare('SELECT * FROM registro_auditoria ORDER BY id DESC LIMIT ?');
        $consulta->bindValue(1, max(1, min($cuantas, 1000)), PDO::PARAM_INT);
        $consulta->execute();

        return $consulta->fetchAll();
    }

    /** @return array{0: string|null, 1: string|null} */
    private function limpiar(?string $url, ?string $detalle): array
    {
        return [
            $url === null ? null : mb_substr($this->sanitizador->url($url), 0, 2048),
            $detalle === null ? null : mb_substr($this->sanitizador->texto($detalle), 0, 2000),
        ];
    }
}
