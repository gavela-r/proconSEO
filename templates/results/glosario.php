<?php
/**
 * Glosario de los términos técnicos que aparecen en los resultados (catalog/glosario.json).
 *
 * @var \ProconSEO\Results\Glosario $glosario
 */

use ProconSEO\Shared\Enum\Bloque;

$porBloque = [];
foreach ($glosario->terminos as $t) {
    $porBloque[$t['bloque']][] = $t;
}
$orden = ['ia', 'seo', 'acc'];
?>
<h1>Glosario</h1>
<p class="entradilla">
    Los términos técnicos que usan los resultados, explicados en pocas palabras. En los resultados aparecen
    <a class="termino" href="#noindex">subrayados así</a>: al pasar el ratón por encima se ve la definición, y al pulsar se llega aquí.
</p>

<nav aria-label="Bloques del glosario">
    <ul class="glosario__indice">
        <?php foreach ($orden as $b): ?>
            <?php if (isset($porBloque[$b])): ?>
                <li><a href="#bloque-<?= e($b) ?>"><?= e(Bloque::tryFrom($b)?->etiqueta() ?? $b) ?></a></li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ul>
</nav>

<?php foreach ($orden as $b): ?>
    <?php if (!isset($porBloque[$b])) { continue; } ?>
    <section class="seccion" aria-labelledby="bloque-<?= e($b) ?>">
        <h2 id="bloque-<?= e($b) ?>"><?= e(Bloque::tryFrom($b)?->etiqueta() ?? $b) ?></h2>
        <dl class="glosario">
            <?php foreach ($porBloque[$b] as $t): ?>
                <dt id="<?= e($t['ancla']) ?>"><?= e($t['termino']) ?><?= $t['variantes'] !== [] ? ' <span class="glosario__variantes">(' . e(implode(', ', $t['variantes'])) . ')</span>' : '' ?></dt>
                <dd><?= e($t['definicion']) ?></dd>
            <?php endforeach; ?>
        </dl>
    </section>
<?php endforeach; ?>
