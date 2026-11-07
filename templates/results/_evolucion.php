<?php
/**
 * Evolución del proyecto: barras apiladas (problemas, decisiones, mejorables) por análisis, en SVG
 * sin estilos en línea (CSP) y con la tabla de datos para quien no ve el gráfico. Sin notas ni
 * porcentajes: recuentos de resultados.
 *
 * @var \ProconSEO\Results\Evolucion $evolucion
 */

use ProconSEO\Projects\Formato;
use ProconSEO\Results\Evolucion;

if ($evolucion->series === []) {
    return;
}
$alto = 140;
$ancho = 46;
$hueco = 18;
?>
<section class="seccion" aria-labelledby="t-evolucion">
    <h2 id="t-evolucion">Evolución</h2>
    <p class="seccion__ayuda">Cuántos problemas, decisiones y mejorables tuvo cada análisis, del más antiguo al más reciente. Solo se comparan análisis de la misma dirección y el mismo alcance. Son recuentos, no una nota.</p>
    <ul class="evolucion__leyenda" aria-hidden="true">
        <?php foreach (Evolucion::SERIES as $clave => $texto): ?>
            <li><svg viewBox="0 0 12 12" width="12" height="12" focusable="false"><rect class="barra barra--<?= e($clave) ?>" width="12" height="12" rx="2"/></svg><?= e($texto) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php foreach ($evolucion->series as $n => $serie): ?>
        <?php
        $puntos = $serie['puntos'];
        $anchoTotal = count($puntos) * ($ancho + $hueco) + $hueco;
        $idTitulo = 'evol-' . $n;
        ?>
        <figure class="evolucion">
            <figcaption id="<?= e($idTitulo) ?>"><?= e($serie['titulo']) ?></figcaption>
            <div class="evolucion__grafico">
                <svg viewBox="0 0 <?= e($anchoTotal) ?> <?= e($alto + 34) ?>" width="<?= e($anchoTotal) ?>" height="<?= e($alto + 34) ?>" role="img" aria-labelledby="<?= e($idTitulo) ?>" focusable="false">
                    <line class="evolucion__eje" x1="0" y1="<?= e($alto) ?>" x2="<?= e($anchoTotal) ?>" y2="<?= e($alto) ?>"/>
                    <?php foreach ($puntos as $i => $p): ?>
                        <?php
                        $x = $hueco + $i * ($ancho + $hueco);
                        $y = $alto;
                        $total = (int) array_sum($p['valores']);
                        $incompleto = $p['estado'] !== 'completado';
                        ?>
                        <a href="/analisis/<?= e($p['id']) ?>">
                            <title><?= e(Formato::fecha($p['fecha'])) ?><?= $incompleto ? ' (incompleto)' : '' ?>: <?= e(implode(', ', array_map(static fn (string $k): string => $p['valores'][$k] . ' ' . mb_strtolower(Evolucion::SERIES[$k]), array_keys(Evolucion::SERIES)))) ?></title>
                            <?php foreach (array_keys(Evolucion::SERIES) as $clave): ?>
                                <?php $h = (int) round($p['valores'][$clave] / $serie['maximo'] * ($alto - 14)); ?>
                                <?php if ($h > 0): ?>
                                    <?php $y -= $h; ?>
                                    <rect class="barra barra--<?= e($clave) ?>" x="<?= e($x) ?>" y="<?= e($y) ?>" width="<?= e($ancho) ?>" height="<?= e($h) ?>"/>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <text class="evolucion__total" x="<?= e($x + $ancho / 2) ?>" y="<?= e($y - 4) ?>" text-anchor="middle"><?= e($total) ?><?= $incompleto ? '*' : '' ?></text>
                            <text class="evolucion__fecha" x="<?= e($x + $ancho / 2) ?>" y="<?= e($alto + 16) ?>" text-anchor="middle"><?= e(substr($p['fecha'], 8, 2) . '/' . substr($p['fecha'], 5, 2)) ?></text>
                            <text class="evolucion__fecha" x="<?= e($x + $ancho / 2) ?>" y="<?= e($alto + 29) ?>" text-anchor="middle"><?= e(substr($p['fecha'], 11, 5)) ?></text>
                        </a>
                    <?php endforeach; ?>
                </svg>
            </div>
            <details class="plegable plegable--dentro">
                <summary>Ver los datos</summary>
                <div class="tabla-desplazable">
                    <table>
                        <thead><tr><th scope="col">Análisis</th><?php foreach (Evolucion::SERIES as $texto): ?><th scope="col" class="num"><?= e($texto) ?></th><?php endforeach; ?></tr></thead>
                        <tbody>
                            <?php foreach ($puntos as $p): ?>
                                <tr>
                                    <th scope="row"><a href="/analisis/<?= e($p['id']) ?>"><?= e(Formato::fecha($p['fecha'])) ?></a><?= $p['estado'] !== 'completado' ? ' (incompleto)' : '' ?></th>
                                    <?php foreach (array_keys(Evolucion::SERIES) as $clave): ?><td class="num"><?= e($p['valores'][$clave]) ?></td><?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
            <?php if (array_filter($puntos, static fn (array $p): bool => $p['estado'] !== 'completado') !== []): ?>
                <p class="ayuda">* Análisis incompleto (parcial o cancelado): revisó menos páginas, así que sus números no se comparan del todo con los demás.</p>
            <?php endif; ?>
        </figure>
    <?php endforeach; ?>
</section>
