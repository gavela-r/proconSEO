<?php

declare(strict_types=1);

namespace ProconSEO\Run;

use PDO;
use ProconSEO\Shared\Contract\RecursoDescargado;
use ProconSEO\Shared\Contract\SalidaMotor;
use ProconSEO\Shared\Contract\SenalSeguridad;
use ProconSEO\Shared\Enum\Fase;

/**
 * Único punto donde se escriben resultados, evidencias, recursos y eventos de seguridad.
 * Todo pasa por el sanitizador (SEG-09) antes de llegar a la base de datos.
 * Los resultados solo se insertan: nunca se actualizan.
 */
final class GuardadoResultados
{
    public function __construct(
        private readonly PDO $bd,
        private readonly SanitizadorEvidencias $sanitizador = new SanitizadorEvidencias(),
    ) {
    }

    /**
     * @param list<array{recurso: RecursoDescargado, motivo: string, comprobacion: ?string}> $registro
     * @return array<string, int> URL final => id del recurso, para enlazar resultados y evidencias
     */
    public function guardarRecursos(int $analisisId, array $registro): array
    {
        $s = $this->sanitizador;
        $insertar = $this->bd->prepare(
            'INSERT INTO recursos (analisis_id, url, url_final, redirecciones, estado_http, tipo_contenido, bytes, truncado, resultado, motivo, justificado_por)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $ids = [];
        foreach ($registro as $entrada) {
            $r = $entrada['recurso'];
            $redirecciones = array_map(static fn (array $salto): array => [
                'url' => $s->url($salto['url']),
                'estado' => $salto['estado'],
                'destino' => $s->url($salto['destino']),
            ], $r->redirecciones);

            $insertar->execute([
                $analisisId,
                $s->url($r->urlSolicitada),
                $s->url($r->urlFinal),
                $redirecciones === [] ? null : json_encode($redirecciones, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $r->estadoHttp,
                $r->tipoContenido === null ? null : mb_substr($r->tipoContenido, 0, 255),
                $r->bytes,
                (int) $r->truncado,
                $r->resultado->value,
                $r->motivo === null ? null : mb_substr($s->texto($r->motivo), 0, 500),
                $entrada['comprobacion'],
            ]);
            $ids[$r->urlFinal] = (int) $this->bd->lastInsertId();
        }

        return $ids;
    }

    /**
     * @param array<string, int> $idsRecursos
     */
    public function guardarSalida(int $analisisId, SalidaMotor $salida, array $idsRecursos): void
    {
        $s = $this->sanitizador;
        $this->bd->beginTransaction();
        try {
            $grupos = [];
            $insertarGrupo = $this->bd->prepare('INSERT INTO grupos_causa (analisis_id, codigo, descripcion) VALUES (?, ?, ?)');
            foreach ($salida->gruposCausa as $g) {
                $insertarGrupo->execute([$analisisId, $g->codigo, $s->texto($g->descripcion)]);
                $grupos[$g->codigo] = (int) $this->bd->lastInsertId();
            }

            $evidencias = [];
            $insertarEvidencia = $this->bd->prepare(
                'INSERT INTO evidencias (analisis_id, codigo, tipo, contenido, recurso_id, url_recurso, truncado, capturado_en)
                 VALUES (?, ?, ?, ?, ?, ?, ?, COALESCE(?, NOW(3)))'
            );
            foreach ($salida->evidencias as $e) {
                $insertarEvidencia->execute([
                    $analisisId, $e->codigo, $e->tipo->value, $s->texto($e->contenido),
                    $e->urlRecurso === null ? null : ($idsRecursos[$e->urlRecurso] ?? null),
                    $s->url($e->urlRecurso), (int) $e->truncado, $e->capturadoEn?->format('Y-m-d H:i:s.v'),
                ]);
                $evidencias[$e->codigo] = (int) $this->bd->lastInsertId();
            }

            $insertarResultado = $this->bd->prepare(
                'INSERT INTO resultados (analisis_id, id_comprobacion, id_criterio, bloque, estado, ejecucion, cobertura, tipo_conclusion, modo,
                    recurso_id, url_recurso, elemento, valor_observado, consecuencia, correccion, correccion_cms, explicacion_sencilla, limitacion,
                    requiere_revision_humana, motivo_revision, wcag_criterio, wcag_nombre, wcag_nivel, wcag_version, gravedad, esfuerzo,
                    grupo_causa_id, bloquea_dependientes, fuente, version_regla)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $enlazar = $this->bd->prepare('INSERT IGNORE INTO resultado_evidencia (resultado_id, evidencia_id) VALUES (?, ?)');
            $t = static fn (?string $v): ?string => $v === null ? null : $s->texto($v);

            foreach ($salida->resultados as $r) {
                $insertarResultado->execute([
                    $analisisId, $r->idComprobacion, $r->idCriterio, $r->bloque->value, $r->estado->value, $r->ejecucion->value,
                    $r->cobertura->value, $r->tipoConclusion->value, $r->modo?->value,
                    $r->urlRecurso === null ? null : ($idsRecursos[$r->urlRecurso] ?? null), $s->url($r->urlRecurso),
                    $t($r->elemento), $t($r->valorObservado), $t($r->consecuencia), $t($r->correccion), $t($r->correccionCms),
                    $s->texto($r->explicacionSencilla), $t($r->limitacion), (int) $r->requiereRevisionHumana, $t($r->motivoRevision),
                    $r->wcagCriterio, $r->wcagNombre, $r->wcagNivel, $r->wcagVersion, $r->gravedad?->value, $r->esfuerzo?->value,
                    $r->codigoGrupoCausa === null ? null : ($grupos[$r->codigoGrupoCausa] ?? null), (int) $r->bloqueaDependientes,
                    $r->fuente, $r->versionRegla,
                ]);
                $idResultado = (int) $this->bd->lastInsertId();
                foreach ($r->codigosEvidencia as $codigo) {
                    if (isset($evidencias[$codigo])) {
                        $enlazar->execute([$idResultado, $evidencias[$codigo]]);
                    }
                }
            }

            foreach ($salida->senales as $senal) {
                $this->evento($analisisId, Fase::Comprobaciones, $senal);
            }

            $this->bd->commit();
        } catch (\Throwable $e) {
            $this->bd->rollBack();
            throw $e;
        }
    }

    public function evento(int $analisisId, ?Fase $fase, SenalSeguridad $senal): void
    {
        $this->bd->prepare('INSERT INTO eventos_seguridad (analisis_id, fase, url_recurso, regla, condicion, decision) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([
                $analisisId, $fase?->value, $this->sanitizador->url($senal->urlRecurso), $senal->regla,
                mb_substr($this->sanitizador->texto($senal->descripcion), 0, 2000), $senal->decision->value,
            ]);

        // Copia en la auditoría (SEG-15), que se conserva aunque se borre el análisis.
        (new RegistroAuditoria($this->bd))->bloqueo($analisisId, $senal->urlRecurso, $senal->regla, $senal->descripcion);
    }
}
