<?php

declare(strict_types=1);

namespace ProconSEO\Shared\Contract;

use InvalidArgumentException;
use ProconSEO\Shared\Enum\Bloque;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\Ejecucion;
use ProconSEO\Shared\Enum\Esfuerzo;
use ProconSEO\Shared\Enum\EstadoResultado;
use ProconSEO\Shared\Enum\Gravedad;
use ProconSEO\Shared\Enum\ModoEvaluacion;
use ProconSEO\Shared\Enum\TipoConclusion;

/**
 * Un resultado tal como lo produce el motor (Parte 2). Los campos siguen la sección 5 de la
 * especificación. La Parte 1 lo guarda en la tabla resultados y nunca se modifica después:
 * los cambios se hacen con revisiones (Parte 3).
 *
 * El constructor impide combinaciones que la especificación prohíbe.
 */
final class BorradorResultado
{
    /**
     * @param string       $idComprobacion  Subcomprobación del catálogo, p. ej. "SEO-03.title-presente"
     * @param string       $idCriterio      Criterio padre, p. ej. "SEO-03"
     * @param list<string> $codigosEvidencia Códigos de BorradorEvidencia que lo sustentan
     * @param string|null  $wcagVersion     "2.1" si el criterio está en WCAG 2.1, "2.2" si es nuevo en 2.2
     * @param string|null  $normativa       Norma de la que sale si no es un criterio WCAG (p. ej. «Real Decreto 1112/2018»).
     *                                     Un resultado de accesibilidad implementado lleva WCAG o normativa.
     */
    public function __construct(
        public readonly string $idComprobacion,
        public readonly string $idCriterio,
        public readonly Bloque $bloque,
        public readonly EstadoResultado $estado,
        public readonly Ejecucion $ejecucion,
        public readonly Cobertura $cobertura,
        public readonly TipoConclusion $tipoConclusion,
        public readonly string $explicacionSencilla,
        public readonly string $versionRegla,
        public readonly ?string $urlRecurso = null,
        public readonly ?string $elemento = null,
        public readonly ?string $valorObservado = null,
        public readonly ?string $consecuencia = null,
        public readonly ?string $correccion = null,
        public readonly ?string $correccionCms = null,
        public readonly ?string $limitacion = null,
        public readonly bool $requiereRevisionHumana = false,
        public readonly ?string $motivoRevision = null,
        public readonly ?ModoEvaluacion $modo = null,
        public readonly ?string $wcagCriterio = null,
        public readonly ?string $wcagNombre = null,
        public readonly ?string $wcagNivel = null,
        public readonly ?string $wcagVersion = null,
        public readonly ?Gravedad $gravedad = null,
        public readonly ?Esfuerzo $esfuerzo = null,
        public readonly ?string $codigoGrupoCausa = null,
        public readonly bool $bloqueaDependientes = false,
        public readonly ?string $fuente = null,
        public readonly array $codigosEvidencia = [],
        public readonly ?string $normativa = null,
    ) {
        $this->validar();
    }

    private function validar(): void
    {
        if ($this->gravedad !== null && $this->estado !== EstadoResultado::Problema) {
            throw new InvalidArgumentException("{$this->idComprobacion}: la gravedad solo se asigna a resultados en estado problema.");
        }

        if ($this->gravedad !== null && $this->tipoConclusion !== TipoConclusion::Hecho) {
            throw new InvalidArgumentException("{$this->idComprobacion}: las inferencias e hipótesis no reciben gravedad.");
        }

        $sinComprobar = $this->cobertura === Cobertura::NoImplementada || $this->ejecucion === Ejecucion::NoEjecutada;
        if ($sinComprobar && $this->estado !== EstadoResultado::NoVerificable) {
            throw new InvalidArgumentException("{$this->idComprobacion}: lo no implementado o no ejecutado solo puede ser no_verificable.");
        }

        if ($this->requiereRevisionHumana && ($this->motivoRevision === null || trim($this->motivoRevision) === '')) {
            throw new InvalidArgumentException("{$this->idComprobacion}: la revisión humana necesita un motivo.");
        }

        if ($this->bloque === Bloque::Acc && $this->cobertura !== Cobertura::NoImplementada && $this->wcagCriterio === null && $this->normativa === null) {
            throw new InvalidArgumentException("{$this->idComprobacion}: los resultados de accesibilidad indican el criterio WCAG o la normativa de la que salen.");
        }

        if ($this->wcagVersion !== null && !in_array($this->wcagVersion, ['2.1', '2.2'], true)) {
            throw new InvalidArgumentException("{$this->idComprobacion}: wcagVersion debe ser '2.1' o '2.2'.");
        }

        if (trim($this->explicacionSencilla) === '') {
            throw new InvalidArgumentException("{$this->idComprobacion}: falta la explicación sencilla.");
        }
    }

    /**
     * Atajo para las subcomprobaciones del catálogo que aún no están implementadas:
     * siempre deben aparecer en el informe (especificación 4.8).
     */
    public static function noImplementada(string $idComprobacion, string $idCriterio, Bloque $bloque, string $motivo, string $versionRegla = 'v1'): self
    {
        return new self(
            idComprobacion: $idComprobacion,
            idCriterio: $idCriterio,
            bloque: $bloque,
            estado: EstadoResultado::NoVerificable,
            ejecucion: Ejecucion::NoEjecutada,
            cobertura: Cobertura::NoImplementada,
            tipoConclusion: TipoConclusion::Hecho,
            explicacionSencilla: 'Esta comprobación todavía no está disponible en la herramienta.',
            versionRegla: $versionRegla,
            limitacion: $motivo,
        );
    }
}
