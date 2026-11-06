<?php

declare(strict_types=1);

namespace ProconSEO\Results;

use PDO;
use ProconSEO\Review\CapturaSegura;
use ProconSEO\Run\RegistroAuditoria;
use ProconSEO\Shared\Enum\EstadoAnalisis;
use ProconSEO\Shared\Error\ErrorDominio;

/**
 * Borrado definitivo de un análisis (RF-16; decisión 16 de docs/decisiones.md).
 *
 * - No se puede borrar un análisis en cola, en curso o cancelándose: el worker podría seguir escribiendo.
 * - La base de datos borra en cascada fases, recursos, resultados, evidencias y revisiones.
 * - Las capturas de los revisores están en disco: se borran después de confirmar el borrado.
 * - El borrado queda en el registro de auditoría (SEG-15), que no tiene claves ajenas y sobrevive al análisis.
 */
final class BorradoAnalisis
{
    /** Estados en los que el análisis todavía puede cambiar. */
    public const EN_MARCHA = [EstadoAnalisis::EnCola, EstadoAnalisis::EnCurso, EstadoAnalisis::Cancelando];

    public function __construct(
        private readonly PDO $bd,
        private readonly CapturaSegura $capturas,
        private readonly RegistroAuditoria $auditoria,
    ) {
    }

    public static function enMarcha(?string $estado): bool
    {
        return in_array(EstadoAnalisis::tryFrom((string) $estado), self::EN_MARCHA, true);
    }

    /**
     * Lo que se borrará, para la pantalla de confirmación.
     *
     * @return array{resultados: int, evidencias: int, revisiones: int, capturas: int}
     */
    public function resumen(int $analisisId): array
    {
        $consulta = $this->bd->prepare(
            "SELECT (SELECT COUNT(*) FROM resultados WHERE analisis_id = ?) AS resultados,
                    (SELECT COUNT(*) FROM evidencias WHERE analisis_id = ?) AS evidencias,
                    (SELECT COUNT(*) FROM revisiones v JOIN resultados r ON r.id = v.resultado_id WHERE r.analisis_id = ?) AS revisiones,
                    (SELECT COUNT(*) FROM evidencias WHERE analisis_id = ? AND tipo = 'captura' AND ruta_fichero IS NOT NULL) AS capturas"
        );
        $consulta->execute([$analisisId, $analisisId, $analisisId, $analisisId]);
        $fila = $consulta->fetch() ?: [];

        return [
            'resultados' => (int) ($fila['resultados'] ?? 0),
            'evidencias' => (int) ($fila['evidencias'] ?? 0),
            'revisiones' => (int) ($fila['revisiones'] ?? 0),
            'capturas' => (int) ($fila['capturas'] ?? 0),
        ];
    }

    /**
     * Borra el análisis para siempre. Devuelve el id del proyecto al que pertenecía.
     *
     * @throws ErrorDominio si no existe o si está en marcha
     */
    public function borrar(int $analisisId, ?string $ip = null): int
    {
        $this->bd->beginTransaction();
        try {
            // Bloquea la fila: así el worker no puede empezarlo mientras se comprueba y se borra.
            $consulta = $this->bd->prepare('SELECT id, proyecto_id, estado, url_introducida FROM analisis WHERE id = ? FOR UPDATE');
            $consulta->execute([$analisisId]);
            $analisis = $consulta->fetch();
            if ($analisis === false) {
                throw new ErrorDominio('no_encontrado', 'El análisis no existe.', 404);
            }
            if (self::enMarcha((string) $analisis['estado'])) {
                throw new ErrorDominio('analisis_en_marcha', 'No se puede borrar un análisis que todavía está en marcha. Cancélalo o espera a que termine.', 409);
            }

            $resumen = $this->resumen($analisisId);
            $ficheros = $this->bd->prepare("SELECT ruta_fichero FROM evidencias WHERE analisis_id = ? AND ruta_fichero IS NOT NULL");
            $ficheros->execute([$analisisId]);
            $rutas = array_map('strval', $ficheros->fetchAll(PDO::FETCH_COLUMN));

            // La base de datos borra en cascada todo lo que cuelga del análisis.
            $this->bd->prepare('DELETE FROM analisis WHERE id = ?')->execute([$analisisId]);

            $this->auditoria->registrar(
                'analisis_borrado',
                $analisisId,
                (int) $analisis['proyecto_id'],
                $ip,
                (string) $analisis['url_introducida'],
                null,
                'Borrado definitivo: ' . self::cantidad($resumen['resultados'], 'resultado', 'resultados')
                    . ', ' . self::cantidad($resumen['evidencias'], 'evidencia', 'evidencias')
                    . ', ' . self::cantidad($resumen['revisiones'], 'revisión', 'revisiones')
                    . ' y ' . self::cantidad($resumen['capturas'], 'captura', 'capturas') . '.',
            );

            $this->bd->commit();
        } catch (\Throwable $e) {
            $this->bd->rollBack();
            throw $e;
        }

        // Los ficheros se borran solo cuando la base de datos ya ha confirmado el borrado.
        $carpetas = [];
        foreach ($rutas as $ruta) {
            $absoluta = $this->capturas->rutaAbsoluta($ruta);
            if ($absoluta !== null) {
                @unlink($absoluta);
                $carpetas[dirname($absoluta)] = true;
            }
        }
        // La carpeta de capturas del análisis se quita si se ha quedado vacía.
        foreach (array_keys($carpetas) as $carpeta) {
            if (is_dir($carpeta) && (scandir($carpeta) ?: []) === ['.', '..']) {
                @rmdir($carpeta);
            }
        }

        return (int) $analisis['proyecto_id'];
    }

    private static function cantidad(int $n, string $uno, string $varios): string
    {
        return $n . ' ' . ($n === 1 ? $uno : $varios);
    }
}
