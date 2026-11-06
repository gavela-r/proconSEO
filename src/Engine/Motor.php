<?php

declare(strict_types=1);

namespace ProconSEO\Engine;

use ProconSEO\Engine\Catalogo\CatalogoJson;
use ProconSEO\Engine\Comprobaciones\AccesibilidadAria;
use ProconSEO\Engine\Comprobaciones\AccesibilidadEntrada;
use ProconSEO\Engine\Comprobaciones\AccesibilidadEstructura;
use ProconSEO\Engine\Comprobaciones\AccesibilidadFormularios;
use ProconSEO\Engine\Comprobaciones\AccesibilidadImagenes;
use ProconSEO\Engine\Comprobaciones\AccesibilidadMeta;
use ProconSEO\Engine\Comprobaciones\Acciones;
use ProconSEO\Engine\Comprobaciones\ComprobacionesSitio;
use ProconSEO\Engine\Comprobaciones\DirectivasIndexacion as Directivas;
use ProconSEO\Engine\Comprobaciones\CanonicalSitemap;
use ProconSEO\Engine\Comprobaciones\DatosEstructurados;
use ProconSEO\Engine\Comprobaciones\DirectivasIndexacion;
use ProconSEO\Engine\Comprobaciones\Enlazado;
use ProconSEO\Engine\Comprobaciones\Idiomas;
use ProconSEO\Engine\Comprobaciones\IdiomaTexto;
use ProconSEO\Engine\Comprobaciones\Metadatos;
use ProconSEO\Engine\Comprobaciones\Multimedia;
use ProconSEO\Engine\Comprobaciones\PageSpeed;
use ProconSEO\Engine\Comprobaciones\EstadoTecnico;
use ProconSEO\Engine\Comprobaciones\PermisoRobots;
use ProconSEO\Engine\Comprobaciones\Redirecciones;
use ProconSEO\Engine\Comprobaciones\RespuestaUtilizable;
use ProconSEO\Engine\Seguridad\DetectorInstrucciones;
use ProconSEO\Shared\Contract\BorradorResultado;
use ProconSEO\Shared\Contract\ConsolidadorSitio;
use ProconSEO\Shared\Contract\ContextoAnalisis;
use ProconSEO\Shared\Contract\FichaComprobacion;
use ProconSEO\Shared\Contract\InformadorProgreso;
use ProconSEO\Shared\Contract\MotorAnalisis;
use ProconSEO\Shared\Contract\ProveedorRecursos;
use ProconSEO\Shared\Contract\ResumenPagina;
use ProconSEO\Shared\Contract\SalidaMotor;
use ProconSEO\Shared\Contract\SenalSeguridad;
use ProconSEO\Shared\Contract\TokenCancelacion;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\DecisionCierre;
use ProconSEO\Shared\Enum\Fase;
use RuntimeException;
use Throwable;

/**
 * Motor de comprobaciones de la Parte 2.
 *
 * Devuelve un resultado por cada subcomprobación del catálogo, en el orden del catálogo:
 * - las implementadas, con lo que observen;
 * - las que no se pudieron ejecutar (dependencia fallida, error o cancelación), no_verificable;
 * - las no implementadas, no_verificable / no_implementada con su motivo.
 *
 * Después agrupa los resultados que comparten causa (AgrupadorCausas) y registra como señal el
 * texto de la web dirigido al analizador (SEG-07), sin que cambie ningún resultado.
 *
 * No hace red (pide a ProveedorRecursos), no escribe en la base de datos y el contenido de la
 * web nunca cambia qué se comprueba ni cómo se valora.
 */
final class Motor implements MotorAnalisis, ConsolidadorSitio
{
    /** Por qué una subcomprobación no está implementada, según motivo_no_mvp del catálogo. */
    public const MOTIVOS = [
        'navegador' => 'Requiere un navegador aislado, previsto para una fase futura.',
        'ia' => 'Requiere IA semántica, prevista para una fase futura.',
        'humano' => 'Requiere revisión humana; esta versión no la automatiza.',
        'baja_prioridad' => 'Poco relevante para las webs de Proconsi; se implementará más adelante.',
    ];

    /** @var array<string, Comprobacion> id de subcomprobación => comprobación que la evalúa */
    private array $porId = [];

    private readonly CatalogoJson $catalogo;

    /** Las que comparan páginas (ConsolidadorSitio); null si este motor no las tiene. */
    private ?ComprobacionesSitio $sitio = null;

    /** @param list<Comprobacion> $comprobaciones */
    public function __construct(array $comprobaciones, ?string $directorioCatalogo = null)
    {
        $coberturas = [];
        foreach ($comprobaciones as $c) {
            if ($c instanceof ComprobacionesSitio) {
                $this->sitio = $c;
            }
            foreach ($c->subcomprobaciones() as $id => $cobertura) {
                if (isset($this->porId[$id])) {
                    throw new RuntimeException("$id lo evalúan dos comprobaciones.");
                }
                $this->porId[$id] = $c;
                $coberturas[$id] = $cobertura;
            }
        }
        $this->catalogo = $directorioCatalogo === null ? CatalogoJson::v1($coberturas) : new CatalogoJson($directorioCatalogo, $coberturas);
    }

    /** Motor con todas las comprobaciones implementadas hasta ahora. */
    public static function v1(): self
    {
        return new self([
            new PermisoRobots(),
            new DirectivasIndexacion(),
            new RespuestaUtilizable(),
            new EstadoTecnico(),
            new Redirecciones(),
            new Metadatos(),
            new Enlazado(),
            new Multimedia(),
            new DatosEstructurados(),
            new CanonicalSitemap(),
            new Idiomas(),
            new IdiomaTexto(),
            new Acciones(),
            new AccesibilidadImagenes(),
            new AccesibilidadEstructura(),
            new AccesibilidadFormularios(),
            new AccesibilidadAria(),
            new AccesibilidadEntrada(),
            new AccesibilidadMeta(),
            new PageSpeed(),
            new ComprobacionesSitio(),
        ]);
    }

    public static function motivoNoImplementada(FichaComprobacion $ficha): string
    {
        return self::MOTIVOS[$ficha->extra['motivo_no_mvp'] ?? ''] ?? 'Esta comprobación todavía no está implementada.';
    }

    public function catalogo(): CatalogoJson
    {
        return $this->catalogo;
    }

    public function ejecutar(
        ContextoAnalisis $contexto,
        ProveedorRecursos $proveedor,
        InformadorProgreso $progreso,
        TokenCancelacion $cancelacion,
    ): SalidaMotor {
        $entorno = new Entorno($contexto, new ProveedorConMemoria($proveedor), $this->catalogo);
        $fichas = $this->catalogo->todas();
        $total = count($fichas);

        $progreso->fase(Fase::Extraccion, 0, 1, 'Leyendo la página');
        if ($entorno->paginaDisponible()) {
            try {
                $entorno->documento();
            } catch (NoVerificable) {
                // Las comprobaciones que dependen del HTML lo declararán una a una.
            }
        }
        $progreso->fase(Fase::Extraccion, 1, 1);

        $resultados = [];
        $cancelado = false;
        foreach ($fichas as $i => $ficha) {
            $comprobacion = $this->porId[$ficha->id] ?? null;

            if ($comprobacion === null) {
                $motivo = self::motivoNoImplementada($ficha);
                $resultados[] = BorradorResultado::noImplementada($ficha->id, $ficha->idCriterio, $ficha->bloque, $motivo, $this->catalogo->version());
                continue;
            }

            if (!$cancelado && $cancelacion->estaCancelado()) {
                $cancelado = true;
            }
            if ($cancelado) {
                $resultados[] = $entorno->resultado($ficha->id)->noEjecutada('El análisis se canceló antes de esta comprobación.');
                continue;
            }

            if ($i % 10 === 0) {
                $progreso->fase(Fase::Comprobaciones, $i, $total, 'Comprobando ' . $ficha->idCriterio);
            }
            $resultados[] = $this->evaluar($comprobacion, $ficha->id, $entorno);
        }
        $progreso->fase(Fase::Comprobaciones, $total, $total);

        $progreso->fase(Fase::Consolidacion, 0, 1, 'Agrupando resultados por causa');
        // En el sitio completo, una causa que sale una vez en esta página puede repetirse en otras:
        // se conserva para que la Parte 1 la junte con las demás en un solo grupo (RF-24).
        $minimo = $contexto->alcance === Alcance::SitioCompleto ? 1 : AgrupadorCausas::MINIMO;
        [$resultados, $grupos] = (new AgrupadorCausas())->agrupar($resultados, $entorno->paginaDisponible(), $minimo);
        $senales = $this->senales($entorno);
        $progreso->fase(Fase::Consolidacion, 1, 1);

        return new SalidaMotor($resultados, $entorno->evidencias(), $grupos, $senales, $this->resumen($entorno));
    }

    /** Lo que ConsolidadorSitio necesita de esta página; null si no se pudo leer. */
    private function resumen(Entorno $entorno): ?ResumenPagina
    {
        if (!$entorno->paginaDisponible()) {
            return null;
        }
        try {
            $doc = $entorno->documento();
            $corto = static fn (array $textos, int $largo): array => array_map(static fn (string $t): string => mb_substr($t, 0, $largo), array_slice($textos, 0, 5));
            return new ResumenPagina(
                url: $entorno->url(),
                estadoHttp: $entorno->pagina()->estadoHttp,
                indexable: Directivas::fuentesNoindex($entorno) === [],
                titulos: $corto($doc->titulos, 300),
                descripciones: $corto($doc->metasPorNombre('description'), 500),
                canonical: $doc->canonicos[0] ?? null,
                enlacesInternos: array_slice($entorno->enlacesInternos(), 0, ResumenPagina::MAXIMO_ENLACES),
            );
        } catch (Throwable) {
            // Sin resumen, la página no entra en las comparaciones del sitio; no rompe el análisis.
            return null;
        }
    }

    public function subcomprobacionesDeSitio(): array
    {
        return $this->sitio === null ? [] : array_keys($this->sitio->subcomprobaciones());
    }

    /**
     * Al terminar el sitio completo: valora las subcomprobaciones que comparan páginas, una vez
     * para todo el análisis y en el orden del catálogo.
     */
    public function consolidarSitio(
        ContextoAnalisis $contexto,
        array $resumenes,
        InformadorProgreso $progreso,
        TokenCancelacion $cancelacion,
    ): SalidaMotor {
        $entorno = new Entorno($contexto, new ProveedorConMemoria(new ProveedorVacio()), $this->catalogo);
        $ids = $this->subcomprobacionesDeSitio();
        $resumenes = array_values(array_filter($resumenes, static fn (mixed $r): bool => $r instanceof ResumenPagina));

        $progreso->fase(Fase::Consolidacion, 0, 1, 'Comparando las páginas del sitio');
        $resultados = [];
        foreach ($this->catalogo->todas() as $ficha) {
            if (!in_array($ficha->id, $ids, true) || $this->sitio === null) {
                continue;
            }
            if ($cancelacion->estaCancelado()) {
                $resultados[] = $entorno->resultado($ficha->id)->noEjecutada('El análisis se canceló antes de comparar las páginas.');
                continue;
            }
            try {
                $resultados[] = $this->sitio->consolidar($ficha->id, $resumenes, $entorno);
            } catch (NoVerificable $e) {
                $resultados[] = $entorno->resultado($ficha->id)->causa($e->causa)->noEjecutada($e->getMessage());
            } catch (Throwable $e) {
                $resultados[] = $entorno->resultado($ficha->id)->noEjecutada('Error interno al ejecutar la comprobación (' . $e::class . ').');
            }
        }
        [$resultados, $grupos] = (new AgrupadorCausas())->agrupar($resultados, true);
        $progreso->fase(Fase::Consolidacion, 1, 1);

        return new SalidaMotor($resultados, $entorno->evidencias(), $grupos);
    }

    /**
     * SEG-07: texto de la web dirigido al analizador. Se registra y nada más: los resultados ya
     * están calculados y no se tocan.
     *
     * @return list<SenalSeguridad>
     */
    private function senales(Entorno $entorno): array
    {
        try {
            $documento = null;
            if ($entorno->paginaDisponible()) {
                try {
                    $documento = $entorno->documento();
                } catch (NoVerificable) {
                }
            }
            $robots = $entorno->robotsLeido();
            return (new DetectorInstrucciones())->detectar($documento, $entorno->url(), $robots?->cuerpo, $robots?->urlFinal);
        } catch (Throwable $e) {
            return [new SenalSeguridad(
                DetectorInstrucciones::REGLA,
                'No se pudo completar la búsqueda de texto dirigido al analizador (error interno ' . $e::class . ').',
                DecisionCierre::Continuar,
                $entorno->url(),
            )];
        }
    }

    private function evaluar(Comprobacion $comprobacion, string $id, Entorno $entorno): BorradorResultado
    {
        try {
            $resultado = $comprobacion->evaluar($id, $entorno);
        } catch (NoVerificable $e) {
            return $entorno->resultado($id)->causa($e->causa)->noEjecutada($e->getMessage());
        } catch (Throwable $e) {
            // Un fallo del código nunca se convierte en un hallazgo sobre la web.
            return $entorno->resultado($id)->noEjecutada('Error interno al ejecutar la comprobación (' . $e::class . ').');
        }

        if ($resultado->idComprobacion !== $id) {
            return $entorno->resultado($id)->noEjecutada("Error interno: la comprobación devolvió {$resultado->idComprobacion}.");
        }
        return $resultado;
    }

    /** @return array<string, Cobertura> */
    public function coberturas(): array
    {
        $coberturas = [];
        foreach ($this->catalogo->todas() as $ficha) {
            $coberturas[$ficha->id] = $ficha->cobertura;
        }
        return $coberturas;
    }
}
