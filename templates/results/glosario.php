<?php
/**
 * Glosario de los términos técnicos que aparecen en los resultados (catalog/glosario.json).
 *
 * Buscador, filtro por bloque e índice de letras para no tener que bajar hasta el final.
 * Sin JavaScript se ve todo y el índice de letras funciona igual (son anclas).
 *
 * @var \ProconSEO\Results\Glosario $glosario
 */

use ProconSEO\Shared\Enum\Bloque;

/** Primera letra para agrupar: sin tildes y en mayúscula («índice» → I). */
$letra = static function (string $termino): string {
    $l = mb_strtoupper(mb_substr($termino, 0, 1));
    $l = strtr($l, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U']);

    return preg_match('/^[A-ZÑ]$/u', $l) === 1 ? $l : '#';
};

$porLetra = [];
$porBloque = [];
foreach ($glosario->terminos as $t) {
    $porLetra[$letra($t['termino'])][] = $t;
    $porBloque[$t['bloque']] = ($porBloque[$t['bloque']] ?? 0) + 1;
}
ksort($porLetra);
$abecedario = [...range('A', 'N'), 'Ñ', ...range('O', 'Z')];
$bloques = ['ia', 'seo', 'acc'];
$total = count($glosario->terminos);
?>
<div class="titular">
    <div>
        <h1>Glosario</h1>
        <p class="entradilla">Los términos técnicos que usan los resultados, explicados en pocas palabras. En los resultados aparecen subrayados con puntos: al pasar el ratón o llegar con el teclado se ve la definición.</p>
    </div>
    <p class="glosario__total"><span class="glosario__cifra"><?= e($total) ?></span> términos</p>
</div>

<div class="glosario__barra" id="glosario-barra">
    <div class="glosario__buscar">
        <label for="glosario-texto" class="visualmente-oculto">Buscar un término</label>
        <svg class="icono" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="10.5" cy="10.5" r="6"/><path d="m15 15 4.5 4.5"/></svg>
        <input type="search" id="glosario-texto" placeholder="Buscar: noindex, canonical, contraste…" autocomplete="off" spellcheck="false" aria-describedby="glosario-resultado">
    </div>
    <div class="glosario__bloques" role="group" aria-label="Mostrar términos de">
        <button type="button" class="glosario__bloque" data-bloque="" aria-pressed="true">Todos <span><?= e($total) ?></span></button>
        <?php foreach ($bloques as $b): ?>
            <?php if (isset($porBloque[$b])): ?>
                <button type="button" class="glosario__bloque glosario__bloque--<?= e($b) ?>" data-bloque="<?= e($b) ?>" aria-pressed="false"><?= e(Bloque::tryFrom($b)?->etiqueta() ?? $b) ?> <span><?= e($porBloque[$b]) ?></span></button>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <nav class="glosario__letras" aria-label="Ir a la letra">
        <?php foreach ($abecedario as $l): ?>
            <?php if (isset($porLetra[$l])): ?>
                <a href="#letra-<?= e($l) ?>" data-letra="<?= e($l) ?>"><?= e($l) ?></a>
            <?php else: ?>
                <span aria-hidden="true"><?= e($l) ?></span>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <p class="glosario__resultado meta" id="glosario-resultado" role="status" aria-live="polite"></p>
</div>

<div class="glosario__lista">
    <?php foreach ($porLetra as $l => $terminos): ?>
        <section class="glosario__grupo" id="letra-<?= e($l) ?>" data-letra="<?= e($l) ?>" aria-labelledby="titulo-letra-<?= e($l) ?>">
            <h2 class="glosario__letra" id="titulo-letra-<?= e($l) ?>"><?= e($l) ?></h2>
            <dl class="glosario__fichas">
                <?php foreach ($terminos as $t): ?>
                    <?php $etiquetaBloque = Bloque::tryFrom($t['bloque'])?->etiqueta() ?? ''; ?>
                    <div class="glosario__ficha glosario__ficha--<?= e($t['bloque']) ?>" id="<?= e($t['ancla']) ?>" data-bloque="<?= e($t['bloque']) ?>"
                         data-busqueda="<?= e(mb_strtolower($t['termino'] . ' ' . implode(' ', $t['variantes']) . ' ' . $t['definicion'])) ?>">
                        <dt>
                            <span class="glosario__termino"><?= e($t['termino']) ?></span>
                            <?php if ($etiquetaBloque !== ''): ?><span class="glosario__etiqueta"><?= e($etiquetaBloque) ?></span><?php endif; ?>
                        </dt>
                        <dd>
                            <?= e($t['definicion']) ?>
                            <?php if ($t['variantes'] !== []): ?>
                                <span class="glosario__variantes">También: <?= e(implode(', ', $t['variantes'])) ?></span>
                            <?php endif; ?>
                        </dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        </section>
    <?php endforeach; ?>
    <p class="glosario__vacio" id="glosario-vacio" hidden>Ningún término coincide. Prueba con otra palabra o elige «Todos».</p>
</div>

<script src="/assets/js/glosario.js" defer></script>
