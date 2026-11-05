<?php

declare(strict_types=1);

namespace ProconSEO\Review;

use ProconSEO\Projects\ErroresFormulario;
use ProconSEO\Shared\Enum\DecisionRevision;
use ProconSEO\Shared\Enum\EstadoResultado;

/**
 * Datos de una revisión humana ya validados (especificación 4.6, RF-40 a RF-43).
 *
 * - El nombre del revisor es obligatorio, porque no hay login (RF-40).
 * - Motivo, decisión y justificación son obligatorios (RF-41).
 * - "Cambiar estado" exige un estado nuevo distinto del actual.
 * - Un comentario sin evidencia no puede convertir un resultado en "Correcto" (RF-43).
 */
final class DatosRevision
{
    public const MAX_REVISOR = 120;
    public const MAX_TEXTO = 5000;
    public const MAX_EVIDENCIA = 20000;

    private function __construct(
        public readonly string $revisor,
        public readonly string $motivo,
        public readonly DecisionRevision $decision,
        public readonly ?EstadoResultado $nuevoEstado,
        public readonly string $justificacion,
        public readonly ?string $evidenciaTexto,
    ) {
    }

    /**
     * @param array<string, mixed> $entrada    campos del formulario
     * @param string               $estadoActual estado vigente del resultado
     * @param bool                 $hayCaptura   si se ha subido una captura válida
     *
     * @throws ErroresFormulario
     */
    public static function desdeFormulario(array $entrada, string $estadoActual, bool $hayCaptura = false): self
    {
        $errores = [];

        $revisor = self::texto($entrada, 'revisor', true);
        if ($revisor === null) {
            $errores['revisor'] = 'Escribe tu nombre: queda como autor de la revisión.';
        } elseif (mb_strlen($revisor) > self::MAX_REVISOR) {
            $errores['revisor'] = 'El nombre no puede tener más de ' . self::MAX_REVISOR . ' caracteres.';
        }

        $motivo = self::texto($entrada, 'motivo');
        if ($motivo === null) {
            $errores['motivo'] = 'Explica qué has revisado.';
        } elseif (mb_strlen($motivo) > self::MAX_TEXTO) {
            $errores['motivo'] = 'El motivo es demasiado largo.';
        }

        $decision = DecisionRevision::tryFrom(self::texto($entrada, 'decision', true) ?? '');
        if ($decision === null) {
            $errores['decision'] = 'Elige una decisión.';
        }

        $nuevoEstado = null;
        if ($decision === DecisionRevision::CambiarEstado) {
            $nuevoEstado = EstadoResultado::tryFrom(self::texto($entrada, 'nuevo_estado', true) ?? '');
            if ($nuevoEstado === null) {
                $errores['nuevo_estado'] = 'Elige el estado nuevo.';
            } elseif ($nuevoEstado->value === $estadoActual) {
                $errores['nuevo_estado'] = 'El resultado ya está en ese estado. Si estás de acuerdo, elige «Confirmar resultado».';
            }
        }

        $justificacion = self::texto($entrada, 'justificacion');
        if ($justificacion === null) {
            $errores['justificacion'] = 'Explica por qué decides esto.';
        } elseif (mb_strlen($justificacion) > self::MAX_TEXTO) {
            $errores['justificacion'] = 'La justificación es demasiado larga.';
        }

        $evidencia = self::texto($entrada, 'evidencia_texto');
        if ($evidencia !== null && mb_strlen($evidencia) > self::MAX_EVIDENCIA) {
            $errores['evidencia_texto'] = 'La evidencia es demasiado larga: resume lo importante o adjunta una captura.';
        }

        // RF-43: para pasar a "Correcto" hace falta evidencia, no basta con la justificación.
        if ($nuevoEstado === EstadoResultado::Correcto && $evidencia === null && !$hayCaptura) {
            $errores['evidencia_texto'] = 'Para pasar a «Correcto» hay que añadir evidencia: un texto o una captura. Un comentario solo no basta.';
        }

        if ($errores !== [] || $revisor === null || $motivo === null || $decision === null || $justificacion === null) {
            throw new ErroresFormulario($errores);
        }

        return new self($revisor, $motivo, $decision, $nuevoEstado, $justificacion, $evidencia);
    }

    private static function texto(array $entrada, string $campo, bool $unaLinea = false): ?string
    {
        $valor = $entrada[$campo] ?? null;
        if (!is_string($valor)) {
            return null;
        }
        $valor = trim($unaLinea ? (preg_replace('/\s+/u', ' ', $valor) ?? '') : $valor);

        return $valor === '' ? null : $valor;
    }
}
