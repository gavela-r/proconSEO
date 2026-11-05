<?php

declare(strict_types=1);

namespace ProconSEO\Engine;

use ProconSEO\Shared\Contract\BorradorResultado;
use ProconSEO\Shared\Contract\FichaComprobacion;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\Ejecucion;
use ProconSEO\Shared\Enum\EstadoResultado;
use ProconSEO\Shared\Enum\Gravedad;
use ProconSEO\Shared\Enum\TipoConclusion;

/**
 * Construye el resultado de una subcomprobación con los datos del catálogo (bloque, WCAG,
 * esfuerzo, explicación) para que cada comprobación solo indique lo que ha observado.
 *
 *     return $e->resultado('SEO-03.title-presente')
 *         ->valor($titulo)->evidencia($codigo)
 *         ->correcto();
 */
final class Valoracion
{
    private ?string $explicacion = null;
    private ?string $valor = null;
    private ?string $elemento = null;
    private ?string $consecuencia = null;
    private ?string $correccion = null;
    private ?string $limitacion = null;
    private ?string $motivoRevision = null;
    private ?string $fuente = null;
    private ?string $url = null;
    private ?string $causa = null;
    private TipoConclusion $conclusion = TipoConclusion::Hecho;

    /** @var array<string, string> */
    private array $correccionCms = [];

    /** @var list<string> */
    private array $evidencias = [];

    public function __construct(
        private readonly FichaComprobacion $ficha,
        private readonly string $version,
        private readonly Entorno $entorno,
    ) {
    }

    /** Explicación sencilla propia del resultado; por defecto, la del criterio en el catálogo. */
    public function explicacion(string $texto): self
    {
        $this->explicacion = $texto;
        return $this;
    }

    public function valor(?string $valor): self
    {
        $this->valor = $valor === null ? null : mb_substr($valor, 0, 500);
        return $this;
    }

    public function elemento(?string $elemento): self
    {
        $this->elemento = $elemento === null ? null : mb_substr($elemento, 0, 500);
        return $this;
    }

    public function consecuencia(string $texto): self
    {
        $this->consecuencia = $texto;
        return $this;
    }

    public function correccion(string $texto): self
    {
        $this->correccion = $texto;
        return $this;
    }

    /**
     * Instrucciones por CMS. Se usa la del CMS detectado en la página (WooCommerce antes que
     * WordPress); si no se detecta ninguno, no se añade nada.
     *
     * @param array<string, string> $porCms Cms::WORDPRESS|WOOCOMMERCE|PRESTASHOP => texto
     */
    public function correccionCms(array $porCms): self
    {
        $this->correccionCms = $porCms;
        return $this;
    }

    public function limitacion(string $texto): self
    {
        $this->limitacion = $this->limitacion === null ? $texto : $this->limitacion . ' ' . $texto;
        return $this;
    }

    public function revisionHumana(string $motivo): self
    {
        $this->motivoRevision = $motivo;
        return $this;
    }

    public function conclusion(TipoConclusion $tipo): self
    {
        $this->conclusion = $tipo;
        return $this;
    }

    public function fuente(string $fuente): self
    {
        $this->fuente = $fuente;
        return $this;
    }

    public function url(string $url): self
    {
        $this->url = $url;
        return $this;
    }

    /**
     * La causa que ha provocado este resultado, si se sabe con certeza (código de Causas).
     * Solo sirve para agrupar: no cambia el estado ni la gravedad.
     */
    public function causa(?string $codigo): self
    {
        if ($codigo !== null && !Causas::existe($codigo)) {
            throw new \InvalidArgumentException("Causa desconocida: $codigo");
        }
        $this->causa = $codigo;
        return $this;
    }

    public function evidencia(?string ...$codigos): self
    {
        foreach ($codigos as $c) {
            if ($c !== null) {
                $this->evidencias[] = $c;
            }
        }
        return $this;
    }

    public function correcto(): BorradorResultado
    {
        return $this->crear(EstadoResultado::Correcto);
    }

    public function mejorable(): BorradorResultado
    {
        return $this->crear(EstadoResultado::Mejorable);
    }

    public function problema(Gravedad $gravedad): BorradorResultado
    {
        return $this->crear(EstadoResultado::Problema, $gravedad);
    }

    public function decisionRevisar(): BorradorResultado
    {
        return $this->crear(EstadoResultado::DecisionRevisar);
    }

    /** Se ejecutó pero el resultado no se puede afirmar (falta evidencia o revisión humana). */
    public function noVerificable(string $motivo): BorradorResultado
    {
        $this->limitacion($motivo);
        return $this->crear(EstadoResultado::NoVerificable);
    }

    public function noAplicable(string $motivo): BorradorResultado
    {
        $this->valor ??= $motivo;
        return $this->crear(EstadoResultado::NoAplicable);
    }

    /** Uso del motor: no se pudo ejecutar porque falta una dependencia o se canceló. */
    public function noEjecutada(string $motivo): BorradorResultado
    {
        $this->limitacion($motivo);
        return $this->crear(EstadoResultado::NoVerificable, null, Ejecucion::NoEjecutada);
    }

    private function crear(EstadoResultado $estado, ?Gravedad $gravedad = null, Ejecucion $ejecucion = Ejecucion::Ejecutada): BorradorResultado
    {
        $cobertura = $this->ficha->cobertura;
        if ($cobertura === Cobertura::NoImplementada) {
            // Una comprobación registrada siempre declara su cobertura; esto no debería pasar.
            $ejecucion = Ejecucion::NoEjecutada;
            $estado = EstadoResultado::NoVerificable;
            $gravedad = null;
        }
        if ($cobertura === Cobertura::ParcialmenteImplementada && $ejecucion === Ejecucion::Ejecutada) {
            $ejecucion = Ejecucion::Parcial;
        }

        $explicacion = $this->explicacion ?? (string) ($this->ficha->extra['explicacion_sencilla'] ?? $this->ficha->queComprueba);

        return new BorradorResultado(
            idComprobacion: $this->ficha->id,
            idCriterio: $this->ficha->idCriterio,
            bloque: $this->ficha->bloque,
            estado: $estado,
            ejecucion: $ejecucion,
            cobertura: $cobertura,
            tipoConclusion: $this->conclusion,
            explicacionSencilla: $explicacion,
            versionRegla: $this->version,
            urlRecurso: $this->url ?? $this->entorno->url(),
            elemento: $this->elemento,
            valorObservado: $this->valor,
            consecuencia: $this->consecuencia,
            correccion: $this->correccion,
            correccionCms: $this->textoCms(),
            limitacion: $this->limitacion,
            requiereRevisionHumana: $this->motivoRevision !== null,
            motivoRevision: $this->motivoRevision,
            modo: $this->ficha->modo,
            wcagCriterio: $this->ficha->wcagCriterio,
            wcagNombre: $this->ficha->wcagNombre,
            wcagNivel: $this->ficha->wcagNivel,
            wcagVersion: $this->ficha->wcagVersion,
            gravedad: $gravedad,
            esfuerzo: $this->ficha->esfuerzo,
            codigoGrupoCausa: $this->causa,
            fuente: $this->fuente,
            codigosEvidencia: array_values(array_unique($this->evidencias)),
        );
    }

    private function textoCms(): ?string
    {
        if ($this->correccionCms === []) {
            return null;
        }
        $detectados = $this->entorno->cms();
        foreach ([Cms::WOOCOMMERCE, Cms::PRESTASHOP, Cms::WORDPRESS] as $cms) {
            if (isset($detectados[$cms], $this->correccionCms[$cms])) {
                return Cms::nombre($cms) . ': ' . $this->correccionCms[$cms];
            }
        }
        // WooCommerce sin texto propio usa el de WordPress.
        if (isset($detectados[Cms::WOOCOMMERCE], $this->correccionCms[Cms::WORDPRESS])) {
            return 'WordPress: ' . $this->correccionCms[Cms::WORDPRESS];
        }
        return null;
    }
}
