<?php
/**
 * Vista "Compromisos Kit Digital" (especificación 6.7, RF-07). No es una nota ni un aprobado:
 * es la lista de resultados agrupada por lo que Proconsi promete en el Kit Digital.
 *
 * @var array<string, mixed> $analisis
 * @var list<array<string, mixed>>|null $compromisos null si el catálogo no se pudo cargar
 * @var bool $hayResultados
 */

use ProconSEO\Projects\Formato;
use ProconSEO\Shared\Vista;

$conCriticos = $compromisos === null ? [] : array_values(array_filter($compromisos, static fn (array $c): bool => $c['aplica'] && $c['criticos'] > 0));
?>
<?= Vista::render('results/_cabecera_analisis', ['analisis' => $analisis, 'vista' => 'kit', 'conKitDigital' => true], false) ?>

<p class="seccion__ayuda">Lo que Proconsi promete en las webs y tiendas del Kit Digital, con los resultados de este análisis que lo comprueban. Sirve como control de calidad antes de entregar la web. No es una nota ni un aprobado.</p>

<?php if ($compromisos === null): ?>
    <p class="aviso aviso--error" role="alert">No se ha podido cargar el catálogo de comprobaciones, así que no se sabe qué resultado corresponde a cada compromiso. Los resultados siguen disponibles en «Todos los resultados».</p>
<?php elseif (!$hayResultados): ?>
    <p class="aviso" role="note">Este análisis no tiene resultados: no se puede decir si la web cumple los compromisos.</p>
<?php else: ?>

    <?php if ($conCriticos !== []): ?>
        <div class="aviso aviso--error" role="note">
            <p><strong><?= count($conCriticos) === 1 ? '1 compromiso con problemas críticos' : e(count($conCriticos)) . ' compromisos con problemas críticos' ?>.</strong> Revísalos antes de entregar la web:</p>
            <ul>
                <?php foreach ($conCriticos as $c): ?>
                    <li><a href="#k-<?= e($c['codigo']) ?>"><?= e($c['nombre']) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php foreach ($compromisos as $c): ?>
        <section class="seccion" aria-labelledby="k-<?= e($c['codigo']) ?>">
            <h2 id="k-<?= e($c['codigo']) ?>"><?= e($c['nombre']) ?>
                <span class="contador">
                    <?php if (!$c['aplica']): ?>no aplica
                    <?php elseif ($c['resultados'] === []): ?>sin resultados
                    <?php else: ?><?= e(Formato::recuento($c['recuento'])) ?>
                    <?php endif; ?>
                </span>
            </h2>
            <p class="seccion__ayuda"><?= e($c['ayuda']) ?></p>

            <?php if (!$c['aplica']): ?>
                <p class="vacio">Solo para proyectos «Tienda Kit Digital». Este proyecto es una «Web Kit Digital».</p>
            <?php elseif ($c['resultados'] === []): ?>
                <p class="vacio">Este análisis no tiene resultados de las comprobaciones de este compromiso, así que no se puede decir si se cumple.</p>
            <?php else: ?>
                <ul class="resultados">
                    <?php foreach ($c['resultados'] as $r): ?>
                        <li><?= Vista::render('results/_resultado', ['r' => $r], false) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($c['fuera'] !== []): ?>
                <details class="plegable">
                    <summary>Criterios nuevos de WCAG 2.2, fuera del compromiso 2.1: <?= e(Formato::recuento(array_count_values(array_map('strval', array_column($c['fuera'], 'estado_vigente'))))) ?></summary>
                    <p class="ayuda">Se muestran para tenerlos en cuenta, pero no son un incumplimiento de lo que Proconsi promete.</p>
                    <ul class="resultados">
                        <?php foreach ($c['fuera'] as $r): ?>
                            <li><?= Vista::render('results/_resultado', ['r' => $r], false) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
