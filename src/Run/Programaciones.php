<?php

declare(strict_types=1);

namespace ProconSEO\Run;

use PDO;
use ProconSEO\Fetch\DestinoRechazado;
use ProconSEO\Fetch\ValidadorUrl;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Error\ErrorDominio;

/**
 * Análisis programados: una web que se vuelve a analizar sola cada cierto número de días.
 *
 * El worker llama a lanzarPendientes() cada minuto. Cada análisis programado pasa por el mismo
 * lanzador que uno lanzado a mano: validación de la URL, interruptor general y límites contra
 * el abuso (SEG-11). Si no se puede lanzar ahora (límite por hora, interruptor), se reintenta
 * en una hora; si la URL ya no es válida, se apunta el motivo y se espera al siguiente periodo.
 */
final class Programaciones
{
    /** Periodos que se ofrecen: días => etiqueta. */
    public const PERIODOS = [1 => 'Cada día', 7 => 'Cada semana', 14 => 'Cada dos semanas', 30 => 'Cada mes'];

    /** Cuántas programaciones se lanzan como mucho en cada revisión del worker. */
    private const MAX_POR_REVISION = 20;

    public function __construct(
        private readonly PDO $bd,
        private readonly LanzadorAnalisis $lanzador,
        private readonly ValidadorUrl $validadorUrl = new ValidadorUrl(),
    ) {
    }

    /** La primera vez se analiza en cuanto pase el worker; después, cada $cadaDias días. */
    public function crear(int $proyectoId, string $url, string $alcance, int $cadaDias, string $hostsExtra = ''): int
    {
        if (!isset(self::PERIODOS[$cadaDias])) {
            throw new ErrorDominio('periodo_no_valido', 'Elige cada cuánto se analiza.', 422);
        }
        if (Alcance::tryFrom($alcance) === null) {
            throw new ErrorDominio('alcance_no_valido', 'Elige un alcance válido.', 422);
        }
        try {
            $url = $this->validadorUrl->validar(trim($url))->url();
        } catch (DestinoRechazado $e) {
            throw new ErrorDominio('url_no_valida', $e->getMessage(), 422);
        }
        $existe = $this->bd->prepare('SELECT 1 FROM proyectos WHERE id = ?');
        $existe->execute([$proyectoId]);
        if ($existe->fetchColumn() === false) {
            throw new ErrorDominio('proyecto_no_encontrado', 'El proyecto no existe.', 404);
        }

        $this->bd->prepare('INSERT INTO programaciones (proyecto_id, url, alcance, hosts_extra, cada_dias, siguiente_en) VALUES (?, ?, ?, ?, ?, NOW(3))')
            ->execute([$proyectoId, $url, $alcance, mb_substr(trim($hostsExtra), 0, 500), $cadaDias]);

        return (int) $this->bd->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function deProyecto(int $proyectoId): array
    {
        $consulta = $this->bd->prepare(
            'SELECT p.*, a.estado AS ultimo_estado, a.creado_en AS ultimo_creado_en
             FROM programaciones p
             LEFT JOIN analisis a ON a.id = p.ultimo_analisis_id
             WHERE p.proyecto_id = ?
             ORDER BY p.id'
        );
        $consulta->execute([$proyectoId]);

        return $consulta->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function obtener(int $id): ?array
    {
        $consulta = $this->bd->prepare('SELECT * FROM programaciones WHERE id = ?');
        $consulta->execute([$id]);
        $fila = $consulta->fetch();

        return $fila === false ? null : $fila;
    }

    /** Al reanudarla no se recupera lo perdido: el siguiente análisis es ahora. */
    public function activar(int $id, bool $activa): void
    {
        $this->bd->prepare('UPDATE programaciones SET activa = ?, siguiente_en = IF(? = 1, NOW(3), siguiente_en) WHERE id = ?')
            ->execute([(int) $activa, (int) $activa, $id]);
    }

    /** Los análisis ya hechos se conservan; solo dejan de repetirse. */
    public function borrar(int $id): void
    {
        $this->bd->prepare('DELETE FROM programaciones WHERE id = ?')->execute([$id]);
    }

    /**
     * Lanza las programaciones a las que les toca.
     *
     * @return list<int> ids de los análisis lanzados
     */
    public function lanzarPendientes(): array
    {
        $pendientes = $this->bd->query(
            'SELECT id, proyecto_id, url, alcance, hosts_extra, cada_dias, siguiente_en, ultimo_analisis_id
             FROM programaciones
             WHERE activa = 1 AND siguiente_en <= NOW(3)
             ORDER BY siguiente_en
             LIMIT ' . self::MAX_POR_REVISION
        )->fetchAll();

        $lanzados = [];
        foreach ($pendientes as $p) {
            $id = (int) $p['id'];
            if ($this->sigueEnMarcha($p['ultimo_analisis_id'])) {
                $this->aplazar($id, (string) $p['siguiente_en'], 'El análisis anterior todavía no ha terminado.');
                continue;
            }
            // Se reserva antes de lanzar: si hay dos workers, solo uno la coge.
            $reservar = $this->bd->prepare('UPDATE programaciones SET siguiente_en = NOW(3) + INTERVAL cada_dias DAY WHERE id = ? AND siguiente_en = ? AND activa = 1');
            $reservar->execute([$id, $p['siguiente_en']]);
            if ($reservar->rowCount() !== 1) {
                continue;
            }

            try {
                $analisis = $this->lanzador->lanzar((int) $p['proyecto_id'], (string) $p['url'], (string) $p['alcance'],
                    'Análisis programado (' . mb_strtolower(self::PERIODOS[(int) $p['cada_dias']] ?? 'cada ' . $p['cada_dias'] . ' días') . ').',
                    (string) $p['hosts_extra']);
            } catch (ErrorDominio $e) {
                // Límite por hora o herramienta detenida: se vuelve a intentar dentro de una hora.
                if (in_array($e->estadoHttp, [429, 503], true)) {
                    $this->bd->prepare('UPDATE programaciones SET siguiente_en = NOW(3) + INTERVAL 1 HOUR, ultimo_error = ? WHERE id = ?')
                        ->execute([mb_substr($e->getMessage(), 0, 1000), $id]);
                } else {
                    $this->bd->prepare('UPDATE programaciones SET ultimo_error = ? WHERE id = ?')->execute([mb_substr($e->getMessage(), 0, 1000), $id]);
                }
                continue;
            }

            $this->bd->prepare('UPDATE analisis SET programacion_id = ? WHERE id = ?')->execute([$id, $analisis]);
            $this->bd->prepare('UPDATE programaciones SET ultimo_analisis_id = ?, ultimo_error = NULL WHERE id = ?')->execute([$analisis, $id]);
            $lanzados[] = $analisis;
        }

        return $lanzados;
    }

    private function sigueEnMarcha(mixed $analisisId): bool
    {
        if ($analisisId === null) {
            return false;
        }
        $consulta = $this->bd->prepare("SELECT 1 FROM analisis WHERE id = ? AND estado IN ('en_cola', 'en_curso', 'cancelando')");
        $consulta->execute([$analisisId]);

        return $consulta->fetchColumn() !== false;
    }

    private function aplazar(int $id, string $siguiente, string $motivo): void
    {
        $this->bd->prepare('UPDATE programaciones SET siguiente_en = NOW(3) + INTERVAL 1 HOUR, ultimo_error = ? WHERE id = ? AND siguiente_en = ?')
            ->execute([$motivo, $id, $siguiente]);
    }
}
