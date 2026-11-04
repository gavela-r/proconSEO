<?php

declare(strict_types=1);

namespace ProconSEO\Engine\Catalogo;

use ProconSEO\Shared\Contract\Catalogo;
use ProconSEO\Shared\Contract\FichaComprobacion;
use ProconSEO\Shared\Enum\Bloque;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\Esfuerzo;
use ProconSEO\Shared\Enum\ModoEvaluacion;
use RuntimeException;

/**
 * Catálogo leído de catalog/v1/: catalogo.json y un fichero por criterio en criterios/.
 *
 * La cobertura no está en los JSON: depende de lo que el motor tenga implementado, y se
 * pasa al construirlo. Lo que no aparece en $coberturas es no_implementada.
 */
final class CatalogoJson implements Catalogo
{
    private readonly string $version;

    /** @var array<string, FichaComprobacion> */
    private array $fichas = [];

    /** @var array<string, array<string, mixed>> */
    private array $criterios = [];

    /**
     * @param array<string, Cobertura> $coberturas id de subcomprobación => cobertura implementada
     */
    public function __construct(string $directorio, array $coberturas = [])
    {
        $meta = self::leer($directorio . '/catalogo.json');
        $this->version = (string) $meta['version'];

        $ficheros = glob($directorio . '/criterios/*.json') ?: [];
        sort($ficheros);
        if ($ficheros === []) {
            throw new RuntimeException("No hay criterios en $directorio/criterios.");
        }

        $criterios = array_map(self::leer(...), $ficheros);
        $wcag = self::indiceWcag($criterios);

        foreach ($criterios as $criterio) {
            $this->criterios[$criterio['id']] = $criterio;
            foreach ($criterio['subcomprobaciones'] as $sub) {
                $id = $sub['id'];
                if (isset($this->fichas[$id])) {
                    throw new RuntimeException("Subcomprobación repetida en el catálogo: $id");
                }
                $this->fichas[$id] = self::ficha($criterio, $sub, $wcag, $coberturas[$id] ?? Cobertura::NoImplementada);
            }
        }

        $desconocidas = array_diff(array_keys($coberturas), array_keys($this->fichas));
        if ($desconocidas !== []) {
            throw new RuntimeException('Cobertura de subcomprobaciones que no están en el catálogo: ' . implode(', ', $desconocidas));
        }
    }

    /** Catálogo v1 del repositorio. */
    public static function v1(array $coberturas = []): self
    {
        return new self(dirname(__DIR__, 3) . '/catalog/v1', $coberturas);
    }

    public function version(): string
    {
        return $this->version;
    }

    public function todas(): array
    {
        return array_values($this->fichas);
    }

    public function obtener(string $idComprobacion): ?FichaComprobacion
    {
        return $this->fichas[$idComprobacion] ?? null;
    }

    /**
     * Datos completos de un criterio (nombre, explicación, fuentes, umbrales…).
     *
     * @return array<string, mixed>|null
     */
    public function criterio(string $idCriterio): ?array
    {
        return $this->criterios[$idCriterio] ?? null;
    }

    /**
     * @param array<string, mixed> $criterio
     * @param array<string, mixed> $sub
     * @param array<string, array<string, mixed>> $wcag
     */
    private static function ficha(array $criterio, array $sub, array $wcag, Cobertura $cobertura): FichaComprobacion
    {
        // La ficha lleva un único criterio WCAG: el primero que cita la subcomprobación.
        // La lista completa queda en extra['wcag'].
        $principal = isset($sub['wcag'][0]) ? ($wcag[$sub['wcag'][0]] ?? null) : null;
        if (isset($sub['wcag'][0]) && $principal === null) {
            throw new RuntimeException("{$sub['id']} cita el criterio WCAG {$sub['wcag'][0]}, que no está en ningún criterio ACC.");
        }

        $fuente = $criterio['fuentes'][0] ?? null;

        return new FichaComprobacion(
            id: $sub['id'],
            idCriterio: $criterio['id'],
            bloque: Bloque::from($criterio['bloque']),
            nombre: $sub['nombre'],
            queComprueba: $sub['que_comprueba'],
            cobertura: $cobertura,
            enMvp: $sub['mvp'] !== 'no',
            modo: ModoEvaluacion::from($sub['modo']),
            esfuerzo: Esfuerzo::from($criterio['esfuerzo']),
            wcagCriterio: $principal['criterio'] ?? null,
            wcagNombre: $principal['nombre'] ?? null,
            wcagNivel: $principal['nivel'] ?? null,
            wcagVersion: $principal['version_wcag'] ?? null,
            referenciaInvestigacion: $criterio['ref_investigacion'] === [] ? null : implode(', ', $criterio['ref_investigacion']),
            fuente: $fuente === null ? null : $fuente['nombre'] . ' — ' . $fuente['url'],
            extra: [
                'nombre_criterio' => $criterio['nombre'],
                'explicacion_sencilla' => $criterio['explicacion_sencilla'],
                'cuando_aplica' => $sub['cuando_aplica'],
                'condicion' => $sub['condicion'],
                'mvp' => $sub['mvp'],
                'motivo_no_mvp' => $sub['motivo_no_mvp'],
                'necesita' => $sub['necesita'],
                'alcance_minimo' => $sub['alcance_minimo'],
                'kit_digital' => $sub['kit_digital'],
                'reutiliza' => $sub['reutiliza'],
                'wcag' => array_map(static fn (string $c): array => $wcag[$c], $sub['wcag']),
                'gravedad_si_problema' => $sub['gravedad_si_problema'] ?? null,
            ],
        );
    }

    /**
     * @param list<array<string, mixed>> $criterios
     * @return array<string, array<string, mixed>>
     */
    private static function indiceWcag(array $criterios): array
    {
        $indice = [];
        foreach ($criterios as $criterio) {
            foreach ($criterio['wcag'] as $w) {
                $indice[$w['criterio']] = $w;
            }
        }
        return $indice;
    }

    /** @return array<string, mixed> */
    private static function leer(string $fichero): array
    {
        $contenido = @file_get_contents($fichero);
        if ($contenido === false) {
            throw new RuntimeException("No se puede leer $fichero.");
        }
        $datos = json_decode($contenido, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($datos)) {
            throw new RuntimeException("$fichero no contiene un objeto JSON.");
        }
        return $datos;
    }
}
