<?php

declare(strict_types=1);

namespace ProconSEO\Results;

use ProconSEO\Shared\Enum\Bloque;
use ProconSEO\Shared\Enum\EstadoResultado;

/**
 * Filtros del dashboard (RF-23): bloque, estado vigente, criterio, revisión pendiente,
 * página o recurso, responsable, nivel WCAG y búsqueda por texto. Los valores que no son válidos
 * se ignoran.
 */
final class FiltroResultados
{
    public const MAX_TEXTO = 200;

    /** Niveles WCAG que se pueden filtrar: A, AA o solo los criterios nuevos de WCAG 2.2. */
    public const NIVELES = ['A' => 'Solo nivel A', 'AA' => 'Solo nivel AA', 'nuevo22' => 'Nuevos en WCAG 2.2'];

    /** Campos en los que busca el texto. */
    private const CAMPOS_TEXTO = [
        'titulo', 'nombre_criterio', 'id_criterio', 'id_comprobacion', 'explicacion_sencilla', 'elemento', 'valor_observado',
        'consecuencia', 'correccion', 'correccion_cms', 'limitacion', 'motivo_revision', 'causa_descripcion', 'url_recurso',
    ];

    private function __construct(
        public readonly ?Bloque $bloque,
        public readonly ?EstadoResultado $estado,
        public readonly ?string $criterio,
        public readonly bool $pendiente,
        public readonly ?string $url,
        public readonly string $texto,
        public readonly ?string $responsable = null,
        public readonly ?string $nivel = null,
    ) {
    }

    /** @param array<string, mixed> $consulta parámetros de la URL (?bloque=seo&estado=problema…) */
    public static function desdeConsulta(array $consulta): self
    {
        $cadena = static fn (string $clave): string => is_string($consulta[$clave] ?? null) ? trim($consulta[$clave]) : '';

        $criterio = strtoupper($cadena('criterio'));
        $url = $cadena('url');

        return new self(
            Bloque::tryFrom($cadena('bloque')),
            EstadoResultado::tryFrom($cadena('estado')),
            preg_match('/^(IA|SEO|ACC)-\d{2}$/', $criterio) === 1 ? $criterio : null,
            $cadena('pendiente') === '1',
            $url !== '' && strlen($url) <= 2048 ? $url : null,
            mb_substr($cadena('q'), 0, self::MAX_TEXTO),
            isset(Responsable::ETIQUETAS[$cadena('responsable')]) ? $cadena('responsable') : null,
            isset(self::NIVELES[$cadena('nivel')]) ? $cadena('nivel') : null,
        );
    }

    public static function ninguno(): self
    {
        return self::desdeConsulta([]);
    }

    public function activo(): bool
    {
        return $this->bloque !== null || $this->estado !== null || $this->criterio !== null
            || $this->pendiente || $this->url !== null || $this->texto !== ''
            || $this->responsable !== null || $this->nivel !== null;
    }

    /**
     * @param list<array<string, mixed>> $resultados filas con estado_vigente y titulo
     * @return list<array<string, mixed>>
     */
    public function aplicar(array $resultados): array
    {
        if (!$this->activo()) {
            return $resultados;
        }

        $buscado = self::normalizar($this->texto);

        return array_values(array_filter($resultados, function (array $r) use ($buscado): bool {
            if ($this->bloque !== null && $r['bloque'] !== $this->bloque->value) {
                return false;
            }
            if ($this->estado !== null && $r['estado_vigente'] !== $this->estado->value) {
                return false;
            }
            if ($this->criterio !== null && $r['id_criterio'] !== $this->criterio) {
                return false;
            }
            if ($this->pendiente && !$r['revision_pendiente']) {
                return false;
            }
            if ($this->url !== null && $r['url_recurso'] !== $this->url) {
                return false;
            }
            if ($this->responsable !== null && Responsable::de($r) !== $this->responsable) {
                return false;
            }
            if ($this->nivel !== null && !self::cumpleNivel($r, $this->nivel)) {
                return false;
            }
            if ($buscado === '') {
                return true;
            }
            foreach (self::CAMPOS_TEXTO as $campo) {
                if (is_string($r[$campo] ?? null) && str_contains(self::normalizar($r[$campo]), $buscado)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /** Parámetros activos, para conservarlos en enlaces y formularios. @return array<string, string> */
    public function consulta(): array
    {
        return array_filter([
            'bloque' => $this->bloque?->value ?? '',
            'estado' => $this->estado?->value ?? '',
            'criterio' => $this->criterio ?? '',
            'pendiente' => $this->pendiente ? '1' : '',
            'url' => $this->url ?? '',
            'responsable' => $this->responsable ?? '',
            'nivel' => $this->nivel ?? '',
            'q' => $this->texto,
        ], static fn (string $v): bool => $v !== '');
    }

    /**
     * Un resultado sin criterio WCAG (SEO, IA o normativa) no cumple ningún filtro de nivel.
     *
     * @param array<string, mixed> $r
     */
    private static function cumpleNivel(array $r, string $nivel): bool
    {
        if (($r['wcag_criterio'] ?? null) === null || $r['wcag_criterio'] === '') {
            return false;
        }

        return $nivel === 'nuevo22' ? Etiquetas::esNuevo22($r) : ($r['wcag_nivel'] ?? null) === $nivel;
    }

    /** Minúsculas y sin tildes, para que «titulo» encuentre «Título». */
    private static function normalizar(string $texto): string
    {
        return strtr(mb_strtolower($texto), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);
    }
}
