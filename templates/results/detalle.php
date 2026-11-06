<?php
/**
 * Detalle de un resultado con los campos de la sección 5 (RF-22), en el orden
 * Estado → Evidencia → Justificación → Impacto → Recomendación.
 * Todo lo que viene de la web analizada se muestra como texto escapado (SEG-09).
 *
 * @var array<string, mixed> $resultado
 * @var \ProconSEO\Shared\Contract\FichaComprobacion|null $ficha
 * @var array<string, mixed>|null $analisis
 * @var list<array<string, mixed>> $evidencias
 * @var list<array<string, mixed>> $revisiones
 */

use ProconSEO\Projects\Formato;
use ProconSEO\Results\Etiquetas;
use ProconSEO\Shared\Enum\Cobertura;
use ProconSEO\Shared\Enum\DecisionRevision;
use ProconSEO\Shared\Enum\Ejecucion;
use ProconSEO\Shared\Enum\Esfuerzo;
use ProconSEO\Shared\Enum\ModoEvaluacion;
use ProconSEO\Shared\Enum\TipoConclusion;
use ProconSEO\Shared\Enum\TipoEvidencia;
use ProconSEO\Shared\Vista;

$r = $resultado;
$estado = (string) $r['estado_vigente'];
$gravedad = Etiquetas::gravedad($r);
$wcag = Etiquetas::wcag($r);
$normativa = Etiquetas::normativa($r);
$esfuerzo = Esfuerzo::tryFrom((string) $r['esfuerzo']);
$titulo = $ficha?->nombre ?? (string) $r['id_comprobacion'];
$esPrueba = $r['es_dato_prueba'] || ($analisis !== null && ($analisis['es_dato_prueba'] || $analisis['proyecto_es_dato_prueba']));
$sinTexto = static fn (?string $valor): bool => $valor === null || trim($valor) === '';
?>
<?php if ($esPrueba): ?>
    <?= Vista::render('layout/aviso_prueba', [], false) ?>
<?php endif; ?>

<nav class="migas" aria-label="Estás en">
    <ol>
        <li><a href="/proyectos">Proyectos</a></li>
        <?php if ($analisis !== null): ?>
            <li><a href="/proyectos/<?= e($analisis['proyecto_id']) ?>"><?= e($analisis['proyecto_nombre']) ?></a></li>
            <li><a href="/analisis/<?= e($analisis['id']) ?>">Análisis del <?= e(Formato::fecha((string) $analisis['creado_en'])) ?></a></li>
        <?php endif; ?>
        <li aria-current="page"><?= e($r['id_comprobacion']) ?></li>
    </ol>
</nav>

<div class="resultado__cabecera">
    <span class="estado estado--<?= e($estado) ?>"><?= e(Etiquetas::estado($estado)) ?></span>
    <?php if ($gravedad !== null): ?>
        <span class="gravedad gravedad--<?= e(Etiquetas::claseGravedad($r)) ?>"><?= e($gravedad) ?></span>
    <?php endif; ?>
    <?php if ($esfuerzo !== null): ?>
        <span class="chip">Esfuerzo: <?= e(mb_strtolower($esfuerzo->etiqueta())) ?></span>
    <?php endif; ?>
    <span class="chip"><?= e(Etiquetas::bloque((string) $r['bloque'])) ?></span>
    <?php if ($wcag !== null): ?>
        <span class="chip <?= Etiquetas::esNuevo22($r) ? 'chip--wcag22' : 'chip--wcag21' ?>"><?= e($wcag) ?></span>
    <?php elseif ($normativa !== null): ?>
        <span class="chip chip--normativa"><?= e($normativa) ?></span>
    <?php endif; ?>
    <?php if ($r['revision_pendiente']): ?>
        <span class="chip">Revisión humana pendiente</span>
    <?php endif; ?>
</div>
<?php if ($revisado ?? false): ?>
    <p class="aviso aviso--info" role="status"><strong>Revisión guardada.</strong> El resultado original se conserva y la revisión aparece en el historial.</p>
<?php endif; ?>
<h1><?= e($titulo) ?></h1>
<p class="explicacion"><?= \ProconSEO\Results\Glosario::comun()->enlazar($r['explicacion_sencilla']) ?></p>

<?php if ($r['revisado']): ?>
    <p class="aviso aviso--info" role="note">
        Una persona ha revisado este resultado: la herramienta dijo <strong><?= e(Etiquetas::estado((string) $r['estado'])) ?></strong>
        y ahora es <strong><?= e(Etiquetas::estado($estado)) ?></strong>. El resultado original se conserva sin cambios.
    </p>
<?php endif; ?>
<?php if (Etiquetas::esNuevo22($r) && $wcag !== null): ?>
    <p class="aviso" role="note">Este criterio es <strong>nuevo en WCAG 2.2</strong>: no forma parte del compromiso WCAG 2.1 AA del Kit Digital.</p>
<?php endif; ?>

<div class="columnas">
    <div>
        <section class="seccion" aria-labelledby="t-evidencia">
            <h2 id="t-evidencia">Qué se ha encontrado</h2>
            <?php if ($evidencias === [] && $sinTexto($r['elemento']) && $sinTexto($r['valor_observado'])): ?>
                <p class="vacio">Este resultado no tiene evidencias guardadas<?= $r['cobertura'] === Cobertura::NoImplementada->value ? ' porque la comprobación aún no está implementada' : '' ?>.</p>
            <?php endif; ?>
            <?php foreach ($evidencias as $ev): ?>
                <div class="evidencia">
                    <div class="evidencia__cabecera">
                        <strong><?= e($ev['codigo']) ?></strong>
                        <span><?= e(TipoEvidencia::tryFrom((string) $ev['tipo'])?->etiqueta() ?? $ev['tipo']) ?></span>
                        <?php if ($ev['url_recurso'] !== null): ?><span class="codigo"><?= e($ev['url_recurso']) ?></span><?php endif; ?>
                        <span>capturada el <?= e(Formato::fecha((string) $ev['capturado_en'])) ?></span>
                        <?php if ($ev['truncado']): ?><span>· recortada</span><?php endif; ?>
                    </div>
                    <?php if ($ev['contenido'] !== null): ?>
                        <pre><code><?= e($ev['contenido']) ?></code></pre>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if (!$sinTexto($r['elemento']) || !$sinTexto($r['valor_observado']) || $r['url_recurso'] !== null): ?>
                <dl class="datos">
                    <?php if ($r['url_recurso'] !== null): ?><dt>Página o recurso</dt><dd class="codigo"><?= e($r['url_recurso']) ?></dd><?php endif; ?>
                    <?php if (!$sinTexto($r['elemento'])): ?><dt>Elemento</dt><dd class="codigo"><?= e($r['elemento']) ?></dd><?php endif; ?>
                    <?php if (!$sinTexto($r['valor_observado'])): ?><dt>Valor encontrado</dt><dd class="codigo"><?= e($r['valor_observado']) ?></dd><?php endif; ?>
                </dl>
            <?php endif; ?>
        </section>

        <?php if (!$sinTexto($r['consecuencia'])): ?>
            <section class="seccion" aria-labelledby="t-importa">
                <h2 id="t-importa">Por qué importa</h2>
                <p><?= \ProconSEO\Results\Glosario::comun()->enlazar($r['consecuencia']) ?></p>
            </section>
        <?php endif; ?>

        <?php if (!$sinTexto($r['correccion']) || !$sinTexto($r['correccion_cms'])): ?>
            <section class="seccion" aria-labelledby="t-corregir">
                <h2 id="t-corregir">Cómo corregirlo</h2>
                <?php if (!$sinTexto($r['correccion'])): ?><p><?= \ProconSEO\Results\Glosario::comun()->enlazar($r['correccion']) ?></p><?php endif; ?>
                <?php if (!$sinTexto($r['correccion_cms'])): ?>
                    <h3>En el gestor de contenidos</h3>
                    <p><?= e($r['correccion_cms']) ?></p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="seccion" aria-labelledby="t-limite">
            <h2 id="t-limite">Qué no demuestra este resultado</h2>
            <?php if ($sinTexto($r['limitacion']) && !$r['requiere_revision_humana']): ?>
                <p class="vacio">Sin limitaciones indicadas.</p>
            <?php endif; ?>
            <?php if (!$sinTexto($r['limitacion'])): ?><p><?= \ProconSEO\Results\Glosario::comun()->enlazar($r['limitacion']) ?></p><?php endif; ?>
            <?php if ($r['requiere_revision_humana']): ?>
                <p><strong>Necesita revisión humana<?= $r['motivo_revision'] !== null ? ':' : '.' ?></strong> <?= e($r['motivo_revision'] ?? '') ?></p>
            <?php endif; ?>
        </section>

        <?php if ($ficha !== null): ?>
            <section class="seccion" aria-labelledby="t-criterio">
                <h2 id="t-criterio">Sobre esta comprobación</h2>
                <p><strong><?= e($r['id_criterio']) ?> · <?= e($ficha->extra['nombre_criterio'] ?? '') ?>.</strong> <?= e($ficha->extra['explicacion_sencilla'] ?? '') ?></p>
                <p><strong>Qué comprueba:</strong> <?= e($ficha->queComprueba) ?></p>
            </section>
        <?php endif; ?>
    </div>

    <aside>
        <section class="panel" aria-labelledby="t-revision">
            <h2 id="t-revision">Revisión humana</h2>
            <?php if ($revisiones === []): ?>
                <p>Sin revisiones: el estado es el que dio la herramienta.</p>
            <?php else: ?>
                <ol class="historial">
                    <?php foreach ($revisiones as $v): ?>
                        <li>
                            <strong><?= e(DecisionRevision::tryFrom((string) $v['decision'])?->etiqueta() ?? $v['decision']) ?></strong>
                            <?php if ($v['nuevo_estado'] !== null): ?> → <?= e(Etiquetas::estado((string) $v['nuevo_estado'])) ?><?php endif; ?>
                            <br><span class="ayuda"><?= e($v['revisor']) ?> · <?= e(Formato::fecha((string) $v['creado_en'])) ?></span>
                            <p><strong>Motivo:</strong> <?= e($v['motivo']) ?></p>
                            <p><strong>Justificación:</strong> <?= e($v['justificacion']) ?></p>
                            <?php foreach ($v['evidencias'] as $ev): ?>
                                <div class="evidencia">
                                    <div class="evidencia__cabecera"><strong><?= e($ev['codigo']) ?></strong><span><?= ($ev['tiene_fichero'] ?? false) ? 'Captura añadida por el revisor' : 'Evidencia añadida por el revisor' ?></span></div>
                                    <?php if ($ev['contenido'] !== null): ?><pre><code><?= e($ev['contenido']) ?></code></pre><?php endif; ?>
                                    <?php if ($ev['tiene_fichero'] ?? false): ?>
                                        <a href="/evidencias/<?= e($ev['id']) ?>/captura" class="captura">
                                            <img src="/evidencias/<?= e($ev['id']) ?>/captura" alt="Captura <?= e($ev['codigo']) ?> añadida por <?= e($v['revisor']) ?>" loading="lazy">
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
            <p><a class="boton" href="/resultados/<?= e($r['id']) ?>/revisar">Revisar este resultado</a></p>
            <p class="ayuda">El resultado original nunca se modifica: cada revisión se guarda aparte, con su autor y su justificación.</p>
        </section>

        <section class="panel panel--separado" aria-labelledby="t-tecnico">
            <h2 id="t-tecnico">Datos técnicos</h2>
            <dl class="datos">
                <dt>Criterio</dt><dd class="codigo"><?= e($r['id_criterio']) ?></dd>
                <dt>Comprobación</dt><dd class="codigo"><?= e($r['id_comprobacion']) ?></dd>
                <?php if ($wcag !== null): ?>
                    <dt>WCAG</dt><dd><?= e($r['wcag_criterio']) ?> <?= e($r['wcag_nombre'] ?? '') ?> · <?= e($r['wcag_nivel'] ?? '') ?> (<?= Etiquetas::esNuevo22($r) ? 'nuevo en 2.2' : 'en 2.1' ?>)</dd>
                <?php elseif ($normativa !== null): ?>
                    <dt>Normativa</dt><dd><?= e(trim((string) $r['normativa'])) ?></dd>
                <?php endif; ?>
                <?php if ($r['modo'] !== null): ?>
                    <dt>Modo</dt><dd><?= e(ModoEvaluacion::tryFrom((string) $r['modo'])?->etiqueta() ?? $r['modo']) ?></dd>
                <?php endif; ?>
                <dt>Tipo de conclusión</dt><dd><?= e(TipoConclusion::tryFrom((string) $r['tipo_conclusion'])?->etiqueta() ?? $r['tipo_conclusion']) ?></dd>
                <dt>Ejecución</dt><dd><?= e(Ejecucion::tryFrom((string) $r['ejecucion'])?->etiqueta() ?? $r['ejecucion']) ?></dd>
                <dt>Cobertura</dt><dd><?= e(Cobertura::tryFrom((string) $r['cobertura'])?->etiqueta() ?? $r['cobertura']) ?></dd>
                <dt>Fuente</dt><dd><?= $sinTexto($r['fuente']) ? 'Análisis propio de proconSEO' : e($r['fuente']) ?></dd>
                <?php if ($r['causa_codigo'] !== null): ?>
                    <dt>Causa</dt><dd><?= e($r['causa_codigo']) ?> · <?= e($r['causa_descripcion']) ?></dd>
                <?php endif; ?>
                <dt>Versión de la regla</dt><dd><?= e($r['version_regla']) ?></dd>
            </dl>
        </section>
    </aside>
</div>

<?php if ($analisis !== null): ?>
    <p><a href="/analisis/<?= e($analisis['id']) ?>">← Volver a los resultados del análisis</a></p>
<?php endif; ?>
