<?php
/**
 * «Por dónde empezar» y «Arreglos rápidos» del dashboard. Todo dato se imprime escapado (SEG-09).
 *
 * @var array{empezar: list<array<string, mixed>>, rapidos: list<array<string, mixed>>} $prioridades
 */

use ProconSEO\Results\Etiquetas;
use ProconSEO\Results\Iconos;
use ProconSEO\Shared\Enum\Esfuerzo;
use ProconSEO\Shared\Enum\Gravedad;

if ($prioridades['empezar'] === []) {
    return;
}
$paginas = static fn (int $n): string => $n === 1 ? '1 página' : $n . ' páginas';
?>
<section class="seccion" aria-labelledby="t-empezar">
    <div class="titulo-con-pista">
    <h2 id="t-empezar">Por dónde empezar</h2>
        <span class="pista">
            <button type="button" class="pista__boton" aria-describedby="ayuda-empezar"><span class="solo-lectores">Cómo se ordena</span><span aria-hidden="true">?</span></button>
            <span class="pista__globo" id="ayuda-empezar" role="tooltip" hidden><strong>Cómo se ordena</strong>Primero la gravedad; después, cuántas páginas afecta y lo poco que cuesta arreglarlo. Es una ayuda para decidir, no una nota.</span>
        </span>
    </div>
    <div class="empezar">
        <ol class="empezar__lista">
            <?php foreach ($prioridades['empezar'] as $i): ?>
                <li>
                    <a href="<?= e($i['enlace']) ?>"><?= e($i['titulo']) ?></a>
                    <span class="empezar__datos">
                        <?php if ($i['gravedad'] !== null): ?>
                            <span class="gravedad gravedad--<?= e($i['gravedad']) ?>"><?= e(Gravedad::from((string) $i['gravedad'])->etiqueta()) ?></span>
                        <?php else: ?>
                            <span class="estado estado--<?= e($i['estado']) ?>"><?= Iconos::estado((string) $i['estado']) ?><?= e(Etiquetas::estado((string) $i['estado'])) ?></span>
                        <?php endif; ?>
                        <span class="chip"><?= e($paginas((int) $i['paginas'])) ?><?= $i['repetido'] ? ' · se repite' : '' ?></span>
                        <?php if ($i['causa'] ?? false): ?><span class="chip">Causa común</span><?php endif; ?>
                        <?php if ($i['esfuerzo'] !== null): ?>
                            <span class="chip">Esfuerzo <?= e(mb_strtolower(Esfuerzo::from((string) $i['esfuerzo'])->etiqueta())) ?></span>
                        <?php endif; ?>
                        <span class="chip"><?= e(implode(' · ', array_map(static fn (string $b): string => Etiquetas::bloque($b), $i['bloques']))) ?></span>
                    </span>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php if ($prioridades['rapidos'] !== []): ?>
            <aside class="rapidos" aria-labelledby="t-rapidos">
                <h3 id="t-rapidos">Arreglos rápidos</h3>
                <p>Mucho efecto y poco esfuerzo: se pueden hacer hoy mismo.</p>
                <ul>
                    <?php foreach ($prioridades['rapidos'] as $i): ?>
                        <li><a href="<?= e($i['enlace']) ?>"><?= e($i['titulo']) ?></a><span class="meta"><?= e($paginas((int) $i['paginas'])) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </aside>
        <?php endif; ?>
    </div>
</section>
