<?php
/**
 * Una incidencia del dashboard: un resultado suelto o una causa con todos sus efectos (RF-24).
 *
 * @var array<string, mixed> $entrada elemento de Informe::$bloqueos o Informe::$incidencias
 */

use ProconSEO\Results\Etiquetas;
use ProconSEO\Shared\Enum\Esfuerzo;
use ProconSEO\Shared\Vista;

if ($entrada['tipo'] === 'repetido') {
    // La misma comprobación en varias páginas: una tarjeta con la lista de páginas afectadas.
    echo Vista::render('results/_resultado', ['r' => $entrada['principal'], 'repetidos' => $entrada['resultados']], false);

    return;
}

if ($entrada['tipo'] !== 'causa' || count($entrada['resultados']) === 1) {
    foreach ($entrada['resultados'] as $r) {
        echo Vista::render('results/_resultado', ['r' => $r], false);
    }

    return;
}

$principal = $entrada['principal'];
$estado = (string) $principal['estado_vigente'];
$gravedad = Etiquetas::gravedad($principal);
$esfuerzo = Esfuerzo::tryFrom((string) $principal['esfuerzo']);
$arreglo = null;
$arregloCms = null;
foreach ($entrada['resultados'] as $r) {
    $arreglo ??= $r['correccion'];
    $arregloCms ??= $r['correccion_cms'];
}
$bloques = count($entrada['bloques']);
?>
<article class="causa" aria-labelledby="causa-<?= e($entrada['codigo']) ?>">
    <div class="resultado__cabecera">
        <span class="estado estado--<?= e($estado) ?>"><?= \ProconSEO\Results\Iconos::estado($estado) ?><?= e(Etiquetas::estado($estado)) ?></span>
        <?php if ($gravedad !== null): ?>
            <span class="gravedad gravedad--<?= e(Etiquetas::claseGravedad($principal)) ?>"><?= e($gravedad) ?></span>
        <?php endif; ?>
        <?php if ($esfuerzo !== null): ?>
            <span class="chip">Esfuerzo: <?= e(mb_strtolower($esfuerzo->etiqueta())) ?></span>
        <?php endif; ?>
        <span class="chip">Causa <?= e($entrada['codigo']) ?><?= $bloques > 1 ? ' · afecta a ' . e($bloques) . ' bloques' : '' ?></span>
    </div>
    <h3 id="causa-<?= e($entrada['codigo']) ?>"><?= e($entrada['descripcion']) ?></h3>
    <p>Una sola causa con <?= e(count($entrada['resultados'])) ?> efectos:</p>
    <ul class="causa__efectos">
        <?php foreach ($entrada['resultados'] as $r): ?>
            <li>
                <strong><?= e(Etiquetas::bloque((string) $r['bloque'])) ?></strong> —
                <?= \ProconSEO\Results\Glosario::comun()->enlazar($r['consecuencia'] ?? $r['explicacion_sencilla']) ?>
                <a href="/resultados/<?= e($r['id']) ?>"><?= e($r['titulo']) ?><?php if ($r['titulo'] !== $r['id_comprobacion']): ?><span class="solo-lectores"> (<?= e($r['id_comprobacion']) ?>)</span><?php endif; ?></a>
                <?php if ($r['estado_vigente'] !== $estado): ?>
                    <span class="chip"><?= e(Etiquetas::estado((string) $r['estado_vigente'])) ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if ($arreglo !== null || $arregloCms !== null): ?>
        <div class="causa__arreglo">
            <?php if ($arreglo !== null): ?><p><strong>Cómo corregirlo:</strong> <?= e($arreglo) ?></p><?php endif; ?>
            <?php if ($arregloCms !== null): ?><p><strong>En el gestor de contenidos:</strong> <?= e($arregloCms) ?></p><?php endif; ?>
        </div>
    <?php endif; ?>
</article>
