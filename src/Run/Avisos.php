<?php

declare(strict_types=1);

namespace ProconSEO\Run;

use PDO;
use ProconSEO\Results\ComparacionAnalisis;
use ProconSEO\Results\Informe;
use ProconSEO\Results\RepositorioResultados;
use ProconSEO\Shared\Contract\Catalogo;

/**
 * Avisos dentro de la herramienta cuando un análisis programado encuentra algo grave (no hay
 * servidor de correo). Solo para los programados: los lanzados a mano ya los está mirando alguien.
 *
 * - «web_no_responde»: la página analizada no se pudo descargar, o el análisis se detuvo.
 * - «problemas_graves»: comparado con el análisis anterior, hay problemas nuevos o que han
 *   empeorado con gravedad alta o crítica (por ejemplo, un noindex puesto sin querer).
 *
 * Cada análisis da como mucho un aviso de cada tipo. No cuenta lo que no se pudo comprobar.
 */
final class Avisos
{
    public const WEB_NO_RESPONDE = 'web_no_responde';
    public const PROBLEMAS_GRAVES = 'problemas_graves';

    /** Cuántos problemas se nombran en el detalle; el resto se ve en el análisis. */
    private const MAX_EN_DETALLE = 10;

    public function __construct(private readonly PDO $bd, private readonly ?Catalogo $catalogo = null)
    {
    }

    /**
     * @return list<int> ids de los avisos creados
     */
    public function trasAnalisis(int $analisisId): array
    {
        $resultados = new RepositorioResultados($this->bd);
        $analisis = $resultados->analisis($analisisId);
        if ($analisis === null || $analisis['programacion_id'] === null) {
            return [];
        }
        $url = (string) ($analisis['url_normalizada'] ?? $analisis['url_introducida']);
        $avisos = [];

        $caida = $this->motivoCaida($analisis);
        if ($caida !== null) {
            $avisos[] = $this->crear($analisis, null, self::WEB_NO_RESPONDE, "No se pudo analizar {$url}", $caida);
        }
        if (!in_array($analisis['estado'], ['completado', 'parcial'], true)) {
            return array_filter($avisos);
        }

        $anterior = $resultados->analisisAnterior($analisis);
        if ($anterior !== null) {
            $comparacion = new ComparacionAnalisis(
                $resultados->resultados((int) $anterior['id']),
                Informe::conTitulos($resultados->resultados($analisisId), $this->catalogo),
            );
            $graves = array_values(array_filter(
                [...$comparacion->grupos[ComparacionAnalisis::NUEVOS], ...$comparacion->grupos[ComparacionAnalisis::EMPEORADOS]],
                static fn (array $par): bool => ($par['ahora']['estado_vigente'] ?? null) === 'problema'
                    && in_array($par['ahora']['gravedad'] ?? null, ['critico', 'alto'], true),
            ));
            if ($graves !== []) {
                $lineas = array_map(static fn (array $par): string => '· ' . ($par['ahora']['titulo'] ?? $par['ahora']['id_comprobacion'])
                    . ' (' . $par['ahora']['gravedad'] . ')' . (($par['ahora']['url_recurso'] ?? null) !== null ? ' en ' . $par['ahora']['url_recurso'] : ''),
                    array_slice($graves, 0, self::MAX_EN_DETALLE));
                if (count($graves) > self::MAX_EN_DETALLE) {
                    $lineas[] = '· y ' . (count($graves) - self::MAX_EN_DETALLE) . ' más.';
                }
                $titulo = count($graves) === 1 ? "1 problema grave nuevo en {$url}" : count($graves) . " problemas graves nuevos en {$url}";
                $avisos[] = $this->crear($analisis, (int) $anterior['id'], self::PROBLEMAS_GRAVES, $titulo,
                    "Comparado con el análisis anterior, han aparecido o empeorado:\n" . implode("\n", $lineas));
            }
        }

        return array_values(array_filter($avisos));
    }

    public function sinLeer(): int
    {
        return (int) $this->bd->query('SELECT COUNT(*) FROM avisos WHERE leido_en IS NULL')->fetchColumn();
    }

    /** @return list<array<string, mixed>> Los más recientes primero, con el nombre del proyecto */
    public function lista(bool $soloSinLeer = false, int $limite = 100): array
    {
        $consulta = $this->bd->prepare(
            'SELECT av.*, p.nombre AS proyecto_nombre, p.es_dato_prueba AS proyecto_es_dato_prueba
             FROM avisos av JOIN proyectos p ON p.id = av.proyecto_id
             ' . ($soloSinLeer ? 'WHERE av.leido_en IS NULL' : '') . '
             ORDER BY av.id DESC
             LIMIT ' . max(1, min(500, $limite))
        );
        $consulta->execute();

        return $consulta->fetchAll();
    }

    public function marcarLeido(int $id): void
    {
        $this->bd->prepare('UPDATE avisos SET leido_en = NOW(3) WHERE id = ? AND leido_en IS NULL')->execute([$id]);
    }

    public function marcarTodosLeidos(): void
    {
        $this->bd->exec('UPDATE avisos SET leido_en = NOW(3) WHERE leido_en IS NULL');
    }

    /** @param array<string, mixed> $analisis */
    private function motivoCaida(array $analisis): ?string
    {
        if (in_array($analisis['estado'], ['detenido_error', 'detenido_seguridad'], true)) {
            return 'El análisis se detuvo: ' . ($analisis['motivo_cierre'] ?? 'sin motivo') ;
        }
        $consulta = $this->bd->prepare("SELECT resultado, estado_http, motivo FROM recursos WHERE analisis_id = ? AND justificado_por IS NULL AND motivo = 'página analizada' ORDER BY id LIMIT 1");
        $consulta->execute([$analisis['id']]);
        $pagina = $consulta->fetch();
        if ($pagina === false || $pagina['resultado'] === 'correcto') {
            return null;
        }

        return 'La página no se pudo descargar'
            . ($pagina['estado_http'] !== null ? " (respondió HTTP {$pagina['estado_http']})" : '')
            . '. Puede que la web esté caída o que esté bloqueando a la herramienta.';
    }

    /** @param array<string, mixed> $analisis */
    private function crear(array $analisis, ?int $anterior, string $tipo, string $titulo, string $detalle): ?int
    {
        $insertar = $this->bd->prepare('INSERT IGNORE INTO avisos (proyecto_id, analisis_id, analisis_anterior_id, tipo, titulo, detalle) VALUES (?, ?, ?, ?, ?, ?)');
        $insertar->execute([$analisis['proyecto_id'], $analisis['id'], $anterior, $tipo, mb_substr($titulo, 0, 255), $detalle]);

        return $insertar->rowCount() === 1 ? (int) $this->bd->lastInsertId() : null;
    }
}
