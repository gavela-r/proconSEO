<?php

declare(strict_types=1);

namespace ProconSEO\Results;

use PDO;
use ProconSEO\Shared\Error\ErrorDominio;

/**
 * Revisiones manuales de los criterios WCAG en un análisis: lo que la herramienta no comprueba
 * sola y una persona revisa siguiendo la guía (teclado, foco, lector de pantalla…).
 *
 * Solo se añaden filas: cada revisión guarda quién, cuándo, qué páginas miró y qué vio; la vigente
 * es la última de su criterio. Nunca cambia un resultado automático (RF-42). Solo usa los estados
 * de siempre: correcto, problema o no_aplicable. SQL con sentencias preparadas.
 */
final class RevisionesManuales
{
    public const RESULTADOS = [
        'correcto' => 'Sin problemas al revisarlo',
        'problema' => 'Hay problemas',
        'no_aplicable' => 'No aplica a esta web',
    ];
    public const MAX_REVISOR = 120;
    public const MAX_TEXTO = 5000;

    public function __construct(private readonly PDO $bd)
    {
    }

    /** Guarda una revisión. Lanza ErrorDominio con un mensaje para el formulario si algo no vale. */
    public function registrar(int $analisisId, string $criterio, string $resultado, string $revisor, string $paginas, string $notas): int
    {
        $revisor = trim($revisor);
        $notas = trim($notas);
        $paginas = trim($paginas);
        if (!self::esCriterio($criterio)) {
            throw new ErrorDominio('revision_manual_no_valida', 'Ese criterio no es de WCAG 2.2 A o AA.', 422);
        }
        if (!isset(self::RESULTADOS[$resultado])) {
            throw new ErrorDominio('revision_manual_no_valida', 'Elige qué has visto al revisarlo.', 422);
        }
        if ($revisor === '' || mb_strlen($revisor) > self::MAX_REVISOR) {
            throw new ErrorDominio('revision_manual_no_valida', 'Escribe tu nombre (como mucho ' . self::MAX_REVISOR . ' caracteres).', 422);
        }
        if ($notas === '') {
            throw new ErrorDominio('revision_manual_no_valida', 'Explica qué has hecho y qué has visto: es lo que justifica la revisión.', 422);
        }
        if (mb_strlen($notas) > self::MAX_TEXTO || mb_strlen($paginas) > self::MAX_TEXTO) {
            throw new ErrorDominio('revision_manual_no_valida', 'El texto es demasiado largo (como mucho ' . self::MAX_TEXTO . ' caracteres).', 422);
        }

        $this->bd->prepare(
            'INSERT INTO revisiones_manuales (analisis_id, criterio_wcag, resultado, revisor, paginas, notas) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$analisisId, $criterio, $resultado, $revisor, $paginas === '' ? null : $paginas, $notas]);

        return (int) $this->bd->lastInsertId();
    }

    /**
     * La revisión vigente (la última) de cada criterio revisado en el análisis.
     *
     * @return array<string, array<string, mixed>> criterio => fila
     */
    public function vigentes(int $analisisId): array
    {
        $consulta = $this->bd->prepare(
            'SELECT id, criterio_wcag, resultado, revisor, paginas, notas, creado_en FROM revisiones_manuales
             WHERE analisis_id = ? ORDER BY creado_en, id'
        );
        $consulta->execute([$analisisId]);
        $vigentes = [];
        foreach ($consulta->fetchAll() as $fila) {
            $vigentes[(string) $fila['criterio_wcag']] = $fila;
        }

        return $vigentes;
    }

    /** @return list<array<string, mixed>> de la más reciente a la más antigua */
    public function historial(int $analisisId, string $criterio): array
    {
        $consulta = $this->bd->prepare(
            'SELECT id, resultado, revisor, paginas, notas, creado_en FROM revisiones_manuales
             WHERE analisis_id = ? AND criterio_wcag = ? ORDER BY creado_en DESC, id DESC'
        );
        $consulta->execute([$analisisId, $criterio]);

        return $consulta->fetchAll();
    }

    public static function esCriterio(string $criterio): bool
    {
        return in_array($criterio, array_column(VistaWcag::CRITERIOS, 0), true);
    }
}
