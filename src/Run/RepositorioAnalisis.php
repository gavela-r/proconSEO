<?php

declare(strict_types=1);

namespace ProconSEO\Run;

use PDO;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\EstadoAnalisis;
use ProconSEO\Shared\Enum\EstadoFase;
use ProconSEO\Shared\Enum\Fase;

/**
 * Acceso a las tablas analisis y fases_analisis desde la Parte 1 (ejecución).
 * Solo sentencias preparadas.
 */
final class RepositorioAnalisis
{
    public function __construct(private readonly PDO $bd)
    {
    }

    /**
     * Crea el análisis en cola con sus ocho fases en estado pendiente.
     *
     * @param list<string> $hostsAutorizados
     */
    public function crear(
        int $proyectoId,
        string $urlIntroducida,
        string $urlNormalizada,
        Alcance $alcance,
        ?string $objetivo,
        array $hostsAutorizados,
        int $perfilId,
        string $versionCatalogo,
        ?string $ipOrigen = null,
        ?string $dominio = null,
    ): int {
        $this->bd->beginTransaction();
        try {
            $this->bd->prepare(
                'INSERT INTO analisis (proyecto_id, url_introducida, url_normalizada, alcance, objetivo, hosts_autorizados, perfil_id, version_catalogo, estado, ip_origen, dominio)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $proyectoId, $urlIntroducida, $urlNormalizada, $alcance->value, $objetivo,
                json_encode(array_values($hostsAutorizados), JSON_THROW_ON_ERROR), $perfilId, $versionCatalogo,
                EstadoAnalisis::EnCola->value, $ipOrigen, $dominio,
            ]);
            $id = (int) $this->bd->lastInsertId();

            $fase = $this->bd->prepare('INSERT INTO fases_analisis (analisis_id, fase, estado) VALUES (?, ?, ?)');
            foreach (Fase::cases() as $f) {
                $fase->execute([$id, $f->value, EstadoFase::Pendiente->value]);
            }

            $this->bd->commit();
        } catch (\Throwable $e) {
            $this->bd->rollBack();
            throw $e;
        }

        return $id;
    }

    /** @return array<string, mixed>|null */
    public function obtener(int $id): ?array
    {
        $consulta = $this->bd->prepare('SELECT * FROM analisis WHERE id = ?');
        $consulta->execute([$id]);
        $fila = $consulta->fetch();

        return $fila === false ? null : $fila;
    }

    /** @return list<array<string, mixed>> */
    public function fases(int $id): array
    {
        $consulta = $this->bd->prepare('SELECT fase, estado, hechos, total, mensaje, iniciada_en, terminada_en FROM fases_analisis WHERE analisis_id = ? ORDER BY id');
        $consulta->execute([$id]);

        return $consulta->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function proyecto(int $id): ?array
    {
        $consulta = $this->bd->prepare('SELECT id, nombre, url_principal, tipo, sector, es_dato_prueba FROM proyectos WHERE id = ?');
        $consulta->execute([$id]);
        $fila = $consulta->fetch();

        return $fila === false ? null : $fila;
    }

    /** @return array{id: int, parametros: array<string, mixed>}|null */
    public function perfilVigente(): ?array
    {
        $fila = $this->bd->query('SELECT id, parametros FROM perfiles_ejecucion ORDER BY id DESC LIMIT 1')->fetch();
        if ($fila === false) {
            return null;
        }

        return ['id' => (int) $fila['id'], 'parametros' => json_decode((string) $fila['parametros'], true, 16, JSON_THROW_ON_ERROR)];
    }

    /** @return array<string, mixed> */
    public function parametrosPerfil(int $perfilId): array
    {
        $consulta = $this->bd->prepare('SELECT parametros FROM perfiles_ejecucion WHERE id = ?');
        $consulta->execute([$perfilId]);

        return json_decode((string) $consulta->fetchColumn(), true, 16, JSON_THROW_ON_ERROR);
    }

    /** Análisis creados en la última hora desde esa IP (SEG-11). */
    public function lanzadosUltimaHoraPorIp(string $ip): int
    {
        $consulta = $this->bd->prepare('SELECT COUNT(*) FROM analisis WHERE ip_origen = ? AND creado_en > NOW(3) - INTERVAL 1 HOUR');
        $consulta->execute([$ip]);

        return (int) $consulta->fetchColumn();
    }

    /** Análisis creados en la última hora sobre ese dominio, lance quien los lance (SEG-11). */
    public function lanzadosUltimaHoraPorDominio(string $dominio): int
    {
        $consulta = $this->bd->prepare('SELECT COUNT(*) FROM analisis WHERE dominio = ? AND creado_en > NOW(3) - INTERVAL 1 HOUR');
        $consulta->execute([$dominio]);

        return (int) $consulta->fetchColumn();
    }

    public function enCurso(): int
    {
        return (int) $this->bd->query("SELECT COUNT(*) FROM analisis WHERE estado IN ('en_curso', 'cancelando')")->fetchColumn();
    }

    /**
     * Reserva el siguiente análisis en cola, de forma atómica: aunque haya dos workers, cada
     * análisis lo coge uno solo. Respeta el máximo de análisis simultáneos.
     */
    public function reservarSiguiente(int $maxSimultaneos): ?int
    {
        if ($this->enCurso() >= $maxSimultaneos) {
            return null;
        }

        $candidatos = $this->bd->query("SELECT id FROM analisis WHERE estado = 'en_cola' ORDER BY id LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
        $reservar = $this->bd->prepare("UPDATE analisis SET estado = 'en_curso', iniciado_en = NOW(3) WHERE id = ? AND estado = 'en_cola'");
        foreach ($candidatos as $id) {
            $reservar->execute([$id]);
            if ($reservar->rowCount() === 1) {
                return (int) $id;
            }
        }

        return null;
    }

    public function pedirCancelacion(int $id): void
    {
        // En cola: se cancela directamente. En curso: lo detiene el orquestador en la siguiente comprobación.
        $this->bd->prepare("UPDATE analisis SET estado = 'cancelado', cancelacion_pedida = 1, terminado_en = NOW(3), motivo_cierre = 'Cancelado antes de empezar.' WHERE id = ? AND estado = 'en_cola'")->execute([$id]);
        $this->bd->prepare("UPDATE analisis SET estado = 'cancelando', cancelacion_pedida = 1 WHERE id = ? AND estado = 'en_curso'")->execute([$id]);
    }

    public function cancelacionPedida(int $id): bool
    {
        $consulta = $this->bd->prepare('SELECT cancelacion_pedida FROM analisis WHERE id = ?');
        $consulta->execute([$id]);

        return (bool) $consulta->fetchColumn();
    }

    public function marcarFase(int $id, Fase $fase): void
    {
        $this->bd->prepare('UPDATE analisis SET fase_actual = ? WHERE id = ?')->execute([$fase->value, $id]);
    }

    public function actualizarFase(int $id, Fase $fase, EstadoFase $estado, int $hechos = 0, int $total = 0, string $mensaje = ''): void
    {
        $this->bd->prepare(
            'UPDATE fases_analisis SET estado = ?, hechos = ?, total = ?, mensaje = ?,
                iniciada_en = COALESCE(iniciada_en, NOW(3)),
                terminada_en = IF(? IN (\'terminada\', \'parcial\', \'omitida\', \'fallida\'), NOW(3), NULL)
             WHERE analisis_id = ? AND fase = ?'
        )->execute([$estado->value, $hechos, $total, mb_substr($mensaje, 0, 500), $estado->value, $id, $fase->value]);
    }

    /**
     * Cierra los análisis que se quedaron «en curso» o «cancelando» porque el worker se detuvo a
     * mitad (reinicio del servidor, proceso cerrado…). Sin esto ocuparían para siempre un hueco de
     * los análisis simultáneos. Solo se cierran los que llevan más de $segundos desde que empezaron:
     * el orquestador ya corta cualquier análisis al llegar a su límite de tiempo, así que pasado ese
     * margen no puede seguir vivo. Los resultados que hubiera se conservan.
     *
     * @return list<int> ids cerrados
     */
    public function cerrarHuerfanos(int $segundos): array
    {
        $consulta = $this->bd->prepare(
            "SELECT id, estado FROM analisis
             WHERE estado IN ('en_curso', 'cancelando') AND iniciado_en < NOW(3) - INTERVAL ? SECOND"
        );
        $consulta->execute([max(60, $segundos)]);
        $cerrados = [];
        foreach ($consulta->fetchAll() as $fila) {
            $id = (int) $fila['id'];
            $estado = $fila['estado'] === 'cancelando' ? EstadoAnalisis::Cancelado : EstadoAnalisis::DetenidoError;
            $motivo = $estado === EstadoAnalisis::Cancelado
                ? 'Se pidió cancelar y el análisis se quedó sin cerrar (el worker se detuvo). Se cierra automáticamente; se conservan los resultados obtenidos.'
                : 'El análisis se quedó sin terminar porque el worker se detuvo. Se cierra automáticamente; se conservan los resultados obtenidos.';
            // Solo si sigue en el mismo estado: otro worker podría haberlo cerrado mientras tanto.
            $cerrar = $this->bd->prepare('UPDATE analisis SET estado = ?, motivo_cierre = ?, terminado_en = NOW(3) WHERE id = ? AND estado = ?');
            $cerrar->execute([$estado->value, $motivo, $id, $fila['estado']]);
            if ($cerrar->rowCount() === 1) {
                $this->cerrarFasesPendientes($id, 'No terminó: el worker se detuvo.');
                $cerrados[] = $id;
            }
        }

        return $cerrados;
    }

    /** Marca como omitidas las fases que no llegaron a empezar (al cancelar o al fallar). */
    public function cerrarFasesPendientes(int $id, string $mensaje): void
    {
        $this->bd->prepare("UPDATE fases_analisis SET estado = 'omitida', mensaje = ?, terminada_en = NOW(3) WHERE analisis_id = ? AND estado IN ('pendiente', 'en_curso')")
            ->execute([$mensaje, $id]);
    }

    public function cerrar(int $id, EstadoAnalisis $estado, ?string $motivo, ?string $urlFinal, array $consumo): void
    {
        $this->bd->prepare('UPDATE analisis SET estado = ?, motivo_cierre = ?, url_final = COALESCE(?, url_final), consumo = ?, terminado_en = NOW(3) WHERE id = ?')
            ->execute([$estado->value, $motivo, $urlFinal, json_encode($consumo, JSON_THROW_ON_ERROR), $id]);
    }

    public function guardarConfiguracion(int $id, array $configuracion): void
    {
        $this->bd->prepare('UPDATE analisis SET configuracion_efectiva = ? WHERE id = ?')
            ->execute([json_encode($configuracion, JSON_THROW_ON_ERROR), $id]);
    }
}
