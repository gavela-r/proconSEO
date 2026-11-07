<?php

declare(strict_types=1);

namespace ProconSEO\Results;

use PDO;
use ProconSEO\Shared\Error\ErrorDominio;

/**
 * Seguimiento de cada hallazgo de un proyecto (docs/diseno/analisis-herramientas.md, mejoras 4 y 5),
 * como el flujo de «Validar corrección» de Search Console:
 *
 *   pendiente → en curso → corregido → comprobado
 *
 * y «aceptado», cuando el equipo decide dejarlo así con un motivo (siempre se puede deshacer).
 *
 * - Nunca se edita: cada cambio es una fila nueva con autor y fecha. El resultado original y su
 *   estado no se tocan (RF-42).
 * - «Comprobado» no se marca a mano: se deduce cuando un análisis posterior de la misma dirección y
 *   alcance ya no encuentra el hallazgo. Si lo sigue encontrando, se avisa de que reaparece.
 */
final class Seguimientos
{
    public const PENDIENTE = 'pendiente';
    public const EN_CURSO = 'en_curso';
    public const CORREGIDO = 'corregido';
    public const ACEPTADO = 'aceptado';

    public const ETIQUETAS = [
        self::PENDIENTE => 'Pendiente',
        self::EN_CURSO => 'En curso',
        self::CORREGIDO => 'Corregido',
        self::ACEPTADO => 'Aceptado',
    ];

    /** Estados de resultado que cuentan como «el hallazgo sigue ahí». */
    private const ESTADOS_ABIERTOS = ['problema', 'mejorable', 'decision_revisar'];

    public const MAX_AUTOR = 120;
    public const MAX_MOTIVO = 2000;

    public function __construct(private readonly PDO $bd)
    {
    }

    /**
     * Guarda un cambio de seguimiento. Lanza ErrorDominio con un mensaje para el formulario si algo
     * falta o no es válido.
     */
    public function registrar(int $proyectoId, string $idComprobacion, string $estado, string $autor, ?string $motivo, ?int $analisisId): int
    {
        $autor = trim($autor);
        $motivo = $motivo === null ? null : trim($motivo);
        if (!isset(self::ETIQUETAS[$estado])) {
            throw new ErrorDominio('seguimiento_no_valido', 'Elige un estado de seguimiento.', 422);
        }
        if ($autor === '' || mb_strlen($autor) > self::MAX_AUTOR) {
            throw new ErrorDominio('seguimiento_no_valido', 'Escribe tu nombre (como mucho ' . self::MAX_AUTOR . ' caracteres).', 422);
        }
        if ($estado === self::ACEPTADO && ($motivo === null || $motivo === '')) {
            throw new ErrorDominio('seguimiento_no_valido', 'Para aceptar un hallazgo hay que explicar por qué.', 422);
        }
        if ($motivo !== null && mb_strlen($motivo) > self::MAX_MOTIVO) {
            throw new ErrorDominio('seguimiento_no_valido', 'El motivo es demasiado largo (como mucho ' . self::MAX_MOTIVO . ' caracteres).', 422);
        }

        $this->bd->prepare(
            'INSERT INTO seguimientos (proyecto_id, id_comprobacion, estado, autor, motivo, analisis_id) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$proyectoId, $idComprobacion, $estado, $autor, $motivo === '' ? null : $motivo, $analisisId]);

        return (int) $this->bd->lastInsertId();
    }

    /**
     * Estado actual de cada hallazgo con seguimiento en el proyecto, por id de comprobación.
     * Los «corregido» llevan además 'comprobacion': 'comprobado', 'reaparece' o null (aún sin análisis).
     *
     * @return array<string, array<string, mixed>>
     */
    public function actuales(int $proyectoId): array
    {
        $consulta = $this->bd->prepare(
            'SELECT s.*, a.url_normalizada, a.alcance, a.creado_en AS analisis_creado_en
             FROM seguimientos s
             LEFT JOIN analisis a ON a.id = s.analisis_id
             WHERE s.proyecto_id = ?
               AND s.id = (SELECT MAX(s2.id) FROM seguimientos s2 WHERE s2.proyecto_id = s.proyecto_id AND s2.id_comprobacion = s.id_comprobacion)'
        );
        $consulta->execute([$proyectoId]);

        $actuales = [];
        foreach ($consulta->fetchAll() as $fila) {
            $fila['comprobacion'] = $fila['estado'] === self::CORREGIDO ? $this->comprobar($fila) : null;
            $actuales[(string) $fila['id_comprobacion']] = $fila;
        }

        return $actuales;
    }

    /**
     * Todos los cambios de un hallazgo, del más reciente al más antiguo.
     *
     * @return list<array<string, mixed>>
     */
    public function historial(int $proyectoId, string $idComprobacion): array
    {
        $consulta = $this->bd->prepare('SELECT * FROM seguimientos WHERE proyecto_id = ? AND id_comprobacion = ? ORDER BY id DESC');
        $consulta->execute([$proyectoId, $idComprobacion]);

        return $consulta->fetchAll();
    }

    /**
     * Texto del estado para mostrar, con la comprobación automática si la hay.
     *
     * @param array<string, mixed>|null $actual
     */
    public static function etiqueta(?array $actual): string
    {
        if ($actual === null) {
            return self::ETIQUETAS[self::PENDIENTE];
        }

        return match ($actual['comprobacion'] ?? null) {
            'comprobado' => 'Comprobado',
            'reaparece' => 'Corregido, pero reaparece',
            default => self::ETIQUETAS[(string) $actual['estado']] ?? (string) $actual['estado'],
        };
    }

    /**
     * ¿Qué dice el primer análisis completado después de marcarlo como corregido (y después del
     * análisis desde el que se marcó, que nunca cuenta), de la misma dirección y alcance? Sin ese
     * análisis no se puede afirmar nada (null).
     *
     * @param array<string, mixed> $fila
     */
    private function comprobar(array $fila): ?string
    {
        if ($fila['url_normalizada'] === null) {
            return null;
        }
        $consulta = $this->bd->prepare(
            "SELECT a.id,
                    (SELECT COUNT(*) FROM resultados r
                      WHERE r.analisis_id = a.id AND r.id_comprobacion = ?
                        AND r.estado IN ('" . implode("','", self::ESTADOS_ABIERTOS) . "')) AS abiertos
             FROM analisis a
             WHERE a.proyecto_id = ? AND a.url_normalizada = ? AND a.alcance = ?
               AND a.creado_en > GREATEST(?, ?) AND a.id <> ? AND a.estado IN ('completado', 'parcial')
             ORDER BY a.creado_en ASC, a.id ASC
             LIMIT 1"
        );
        $consulta->execute([$fila['id_comprobacion'], $fila['proyecto_id'], $fila['url_normalizada'], $fila['alcance'],
            $fila['creado_en'], $fila['analisis_creado_en'], $fila['analisis_id']]);
        $siguiente = $consulta->fetch();
        if ($siguiente === false) {
            return null;
        }

        return (int) $siguiente['abiertos'] === 0 ? 'comprobado' : 'reaparece';
    }
}
