<?php
/**
 * Criterios WCAG 2.2 A y AA con lo que dice el análisis de cada uno. Nunca dice «cumple»: como
 * mucho «sin problemas detectados». Todo se imprime escapado (SEG-09).
 *
 * @var array<string, mixed> $analisis
 * @var \ProconSEO\Results\VistaWcag $wcag
 * @var bool $conKitDigital
 */

use ProconSEO\Results\Etiquetas;
use ProconSEO\Results\Iconos;
use ProconSEO\Results\VistaWcag;
use ProconSEO\Shared\Vista;

$id = (int) $analisis['id'];
/** @var array<string, string> $principios */
$principios = ['1' => 'Perceptible', '2' => 'Operable', '3' => 'Comprensible', '4' => 'Robusto'];
$porPrincipio = [];
foreach ($wcag->criterios as $c) {
    $porPrincipio[explode('.', $c['numero'])[0]][] = $c;
}
?>
<?= Vista::render('results/_cabecera_analisis', ['analisis' => $analisis, 'vista' => 'wcag', 'conKitDigital' => $conKitDigital], false) ?>

<section class="seccion" aria-labelledby="t-wcag-resumen">
    <h2 id="t-wcag-resumen">Resumen de los <?= e(count($wcag->criterios)) ?> criterios A y AA</h2>
    <p class="seccion__ayuda">
        Sirve para preparar la declaración de accesibilidad. «Sin problemas detectados» no es lo mismo que «cumple»: una revisión automática no lo puede garantizar,
        y los criterios que la herramienta no comprueba hay que revisarlos a mano.
    </p>
    <ul class="wcag-resumen">
        <?php foreach (VistaWcag::SITUACIONES as $clave => $texto): ?>
            <li class="wcag-situacion wcag-situacion--<?= e($clave) ?>"><span class="cifras__numero"><?= e($wcag->resumen[$clave]) ?></span> <?= e($texto) ?></li>
        <?php endforeach; ?>
    </ul>
</section>

<?php foreach ($porPrincipio as $principio => $criterios): ?>
    <section class="seccion" aria-labelledby="t-principio-<?= e($principio) ?>">
        <h2 id="t-principio-<?= e($principio) ?>"><?= e($principio) ?>. <?= e($principios[$principio] ?? '') ?></h2>
        <div class="tabla-desplazable">
            <table class="tabla-wcag">
                <thead><tr><th scope="col">Criterio</th><th scope="col">Nivel</th><th scope="col">Situación</th><th scope="col">Qué ha comprobado la herramienta</th></tr></thead>
                <tbody>
                    <?php foreach ($criterios as $c): ?>
                        <tr>
                            <th scope="row"><span class="codigo"><?= e($c['numero']) ?></span> <?= e($c['nombre']) ?></th>
                            <td><?= e($c['nivel']) ?><?= $c['nuevo22'] ? ' <span class="chip chip--wcag22">nuevo en 2.2</span>' : '' ?></td>
                            <td><span class="wcag-situacion wcag-situacion--<?= e($c['situacion']) ?>"><?= e(VistaWcag::SITUACIONES[$c['situacion']]) ?></span></td>
                            <td>
                                <?php if ($c['comprobaciones'] === []): ?>
                                    <span class="vacio">Nada: hay que revisarlo a mano.</span>
                                <?php else: ?>
                                    <ul class="tabla-wcag__lista">
                                        <?php foreach ($c['comprobaciones'] as $comp): ?>
                                            <li>
                                                <span class="estado estado--<?= e($comp['estado']) ?>"><?= Iconos::estado($comp['estado']) ?><span class="solo-lectores"><?= e(Etiquetas::estado($comp['estado'])) ?>: </span></span>
                                                <a href="/resultados/<?= e($comp['id']) ?>"><?= e($comp['titulo']) ?></a>
                                                <?php if ($comp['total'] > 1): ?><span class="meta">· <?= e(mb_strtolower(Etiquetas::estado($comp['estado']))) ?> en <?= e($comp['paginas']) ?> de <?= e($comp['total']) ?> páginas</span><?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endforeach; ?>

<p><a href="/analisis/<?= e($id) ?>?bloque=acc#resultados">Ver todos los resultados de accesibilidad</a></p>
