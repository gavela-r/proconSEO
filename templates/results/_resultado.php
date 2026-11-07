<?php
/**
 * Tarjeta de un resultado en las listas del dashboard. Todo dato se imprime escapado (SEG-09).
 *
 * @var array<string, mixed> $r fila de RepositorioResultados::resultados() con 'titulo'
 * @var list<array<string, mixed>>|null $repetidos si es la misma comprobación en varias páginas, todas ellas
 */

use ProconSEO\Results\Etiquetas;
use ProconSEO\Shared\Enum\Esfuerzo;

$estado = (string) $r['estado_vigente'];
$gravedad = Etiquetas::gravedad($r);
$wcag = Etiquetas::wcag($r);
$esfuerzo = Esfuerzo::tryFrom((string) $r['esfuerzo']);
?>
<article class="resultado resultado--<?= e($estado) ?>" aria-labelledby="r-<?= e($r['id']) ?>">
    <div class="resultado__cabecera">
        <span class="estado estado--<?= e($estado) ?>"><?= \ProconSEO\Results\Iconos::estado($estado) ?><?= e(Etiquetas::estado($estado)) ?></span>
        <?php if ($gravedad !== null): ?>
            <span class="gravedad gravedad--<?= e(Etiquetas::claseGravedad($r)) ?>"><?= e($gravedad) ?></span>
        <?php endif; ?>
        <span class="chip"><?= e(Etiquetas::bloque((string) $r['bloque'])) ?></span>
        <?php if (in_array($estado, ['problema', 'mejorable', 'decision_revisar'], true)): ?>
            <span class="chip chip--responsable"><span class="solo-lectores">Responsable: </span><?= e(\ProconSEO\Results\Responsable::etiqueta($r)) ?></span>
            <?php if (($r['seguimiento'] ?? 'pendiente') !== 'pendiente'): ?>
                <span class="chip chip--seguimiento">Seguimiento: <?= e(\ProconSEO\Results\Seguimientos::etiqueta($r['seguimiento_actual'] ?? null)) ?></span>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($wcag !== null): ?>
            <span class="chip <?= Etiquetas::esNuevo22($r) ? 'chip--wcag22' : 'chip--wcag21' ?>"><?= e($wcag) ?></span>
        <?php elseif (($normativa = Etiquetas::normativa($r)) !== null): ?>
            <span class="chip chip--normativa"><?= e($normativa) ?></span>
        <?php endif; ?>
        <?php if ($r['revisado']): ?>
            <span class="chip">Revisado por una persona · antes: <?= e(Etiquetas::estado((string) $r['estado'])) ?></span>
        <?php endif; ?>
        <?php if ($r['revision_pendiente']): ?>
            <span class="chip">Revisión humana pendiente</span>
        <?php endif; ?>
        <?php if ($r['tipo_conclusion'] !== 'hecho'): ?>
            <span class="chip"><?= $r['tipo_conclusion'] === 'inferencia' ? 'Inferencia' : 'Hipótesis' ?></span>
        <?php endif; ?>
    </div>
    <h3 id="r-<?= e($r['id']) ?>"><a href="/resultados/<?= e($r['id']) ?>"><?= e($r['titulo']) ?></a></h3>
    <p><?= \ProconSEO\Results\Glosario::comun()->enlazar($r['explicacion_sencilla']) ?></p>
    <?php if ($r['revision_pendiente'] && $r['motivo_revision'] !== null): ?>
        <p><strong>Qué hay que revisar:</strong> <?= e($r['motivo_revision']) ?></p>
    <?php endif; ?>
    <?php if ($r['revision_pendiente']): ?>
        <p><a class="boton boton--pequeno" href="/resultados/<?= e($r['id']) ?>/revisar">Revisar ahora<span class="solo-lectores">: <?= e($r['titulo']) ?></span></a></p>
    <?php endif; ?>
    <div class="resultado__meta">
        <?php if ($r['titulo'] !== $r['id_comprobacion']): ?>
            <span class="codigo"><?= e($r['id_comprobacion']) ?></span>
        <?php endif; ?>
        <?php if ($esfuerzo !== null && in_array($estado, ['problema', 'mejorable', 'decision_revisar'], true)): ?>
            <span>Esfuerzo: <?= e(mb_strtolower($esfuerzo->etiqueta())) ?></span>
        <?php endif; ?>
        <?php if ($r['url_recurso'] !== null): ?>
            <span class="codigo"><?= e($r['url_recurso']) ?></span>
        <?php endif; ?>
        <?php if ($r['cobertura'] === 'no_implementada' && $r['limitacion'] !== null): ?>
            <span>Motivo: <?= e($r['limitacion']) ?></span>
        <?php endif; ?>
    </div>
    <?php if (($repetidos ?? []) !== []): ?>
        <?php $paginas = count($repetidos); ?>
        <div class="repeticion">
            <p class="repeticion__aviso">
                <svg class="icono" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="4" width="16" height="16" rx="1.5"/><path d="M4 9h16M9.5 9v11"/></svg>
                <span><strong>Se repite en <?= e($paginas) ?> páginas.</strong> Si todas usan la misma plantilla, arreglándola una vez se corrige en todas.</span>
            </p>
            <details class="repeticion__paginas">
                <summary>Ver las <?= e($paginas) ?> páginas</summary>
                <ul>
                    <?php foreach ($repetidos as $otra): ?>
                        <li><a class="codigo" href="/resultados/<?= e($otra['id']) ?>"><?= e($otra['url_recurso'] ?? $otra['id_comprobacion']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </details>
        </div>
    <?php endif; ?>
</article>
