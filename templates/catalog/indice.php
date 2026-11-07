<?php
/**
 * Catálogo y cobertura (RF-60): todo lo que proconSEO revisa y qué parte está ya programada.
 *
 * Arriba, lo que hay que saber de un vistazo (qué hace sola la herramienta, qué a medias y qué
 * hay que revisar a mano); después, una barra fija para filtrar y saltar a un criterio, y cada
 * criterio plegado con sus subcomprobaciones. Sin JavaScript: los filtros son enlaces y un GET.
 *
 * @var string $version
 * @var array<string, array<string, int>> $resumen
 * @var list<array<string, mixed>> $criterios
 * @var array{bloque: ?\ProconSEO\Shared\Enum\Bloque, cobertura: ?\ProconSEO\Shared\Enum\Cobertura, texto: string} $filtros
 * @var int $total
 * @var list<\ProconSEO\Shared\Enum\Bloque> $bloques
 * @var list<\ProconSEO\Shared\Enum\Cobertura> $coberturas
 */

use ProconSEO\Shared\Enum\Cobertura;

$mostradas = array_sum(array_map(static fn (array $c): int => count($c['subcomprobaciones']), $criterios));
$filtrado = $filtros['bloque'] !== null || $filtros['cobertura'] !== null || $filtros['texto'] !== '';
/** Enlace al catálogo cambiando un filtro y conservando los demás. */
$actual = array_filter([
    'bloque' => $filtros['bloque']?->value,
    'cobertura' => $filtros['cobertura']?->value,
    'texto' => $filtros['texto'] !== '' ? $filtros['texto'] : null,
], static fn (?string $v): bool => $v !== null);
$enlace = static function (array $cambios) use ($actual): string {
    $consulta = array_filter(array_merge($actual, $cambios), static fn (?string $v): bool => $v !== null && $v !== '');

    return '/catalogo' . ($consulta === [] ? '' : '?' . http_build_query($consulta)) . '#subcomprobaciones';
};
$textos = [
    Cobertura::Implementada->value => ['La hace la herramienta', 'Se comprueba sola y el resultado es fiable dentro de lo que explica.'],
    Cobertura::ParcialmenteImplementada->value => ['La hace a medias', 'Comprueba una parte; el resultado dice qué queda fuera.'],
    Cobertura::NoImplementada->value => ['Hay que revisarla a mano', 'Todavía no la hace: en los informes sale como «No verificable».'],
];
$total = $resumen['total']['total'];
$corto = [Cobertura::Implementada->value => 'hecha', Cobertura::ParcialmenteImplementada->value => 'a medias', Cobertura::NoImplementada->value => 'sin hacer'];
$anchoBarra = 300;
?>
<h1>Catálogo y cobertura</h1>
<p class="entradilla">
    Todo lo que proconSEO revisa de una web, criterio a criterio, y qué parte está ya programada.
    Lo que no está implementado aparece en los informes como «No verificable», nunca como correcto.
</p>
<p class="catalogo__version">Versión del catálogo: <strong><?= e($version) ?></strong> · cada análisis guarda con qué versión se hizo, para comparar con justicia.</p>

<section aria-labelledby="t-vistazo" class="catalogo__vistazo">
    <h2 id="t-vistazo">De un vistazo</h2>
    <ul class="catalogo__cobertura">
        <?php foreach ($coberturas as $c): ?>
            <li class="catalogo__tarjeta catalogo__tarjeta--<?= e($c->value) ?>">
                <a href="<?= e($enlace(['cobertura' => $c->value, 'bloque' => null, 'texto' => null])) ?>">
                    <span class="catalogo__numero"><?= e($resumen['total'][$c->value]) ?></span>
                    <span class="catalogo__tarjeta-titulo"><?= e($textos[$c->value][0]) ?></span>
                </a>
                <span class="catalogo__tarjeta-texto"><?= e($textos[$c->value][1]) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="tabla-desplazable">
        <table class="catalogo__resumen">
            <caption>Subcomprobaciones de cada bloque según su cobertura (de <?= e($total) ?> en total)</caption>
            <thead>
            <tr>
                <th scope="col">Bloque</th>
                <th scope="col" class="catalogo__col-barra"><span class="visualmente-oculto">Reparto</span></th>
                <?php foreach ($coberturas as $c): ?>
                    <th scope="col"><?= e($c->etiqueta()) ?></th>
                <?php endforeach; ?>
                <th scope="col">Total</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($bloques as $b): ?>
                <?php $fila = $resumen[$b->value]; $x = 0; ?>
                <tr>
                    <th scope="row"><a href="<?= e($enlace(['bloque' => $b->value, 'cobertura' => null])) ?>"><?= e($b->etiqueta()) ?></a></th>
                    <td class="catalogo__col-barra">
                        <svg class="catalogo__barra" viewBox="0 0 <?= e($anchoBarra) ?> 14" width="<?= e($anchoBarra) ?>" height="14" aria-hidden="true" focusable="false" preserveAspectRatio="none">
                            <?php foreach ($coberturas as $c): ?>
                                <?php $w = $fila['total'] > 0 ? $fila[$c->value] / $fila['total'] * $anchoBarra : 0; ?>
                                <?php if ($w > 0): ?><rect class="barra-cobertura barra-cobertura--<?= e($c->value) ?>" x="<?= e(round($x, 2)) ?>" y="0" width="<?= e(round($w, 2)) ?>" height="14"/><?php endif; ?>
                                <?php $x += $w; ?>
                            <?php endforeach; ?>
                        </svg>
                    </td>
                    <?php foreach ($coberturas as $c): ?>
                        <td><a href="<?= e($enlace(['bloque' => $b->value, 'cobertura' => $c->value, 'texto' => null])) ?>"><?= e($fila[$c->value]) ?><span class="visualmente-oculto"> de <?= e($b->etiqueta()) ?>: <?= e(mb_strtolower($c->etiqueta())) ?></span></a></td>
                    <?php endforeach; ?>
                    <td><?= e($fila['total']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr>
                <th scope="row">Total</th>
                <td class="catalogo__col-barra"></td>
                <?php foreach ($coberturas as $c): ?>
                    <td><?= e($resumen['total'][$c->value]) ?></td>
                <?php endforeach; ?>
                <td><?= e($total) ?></td>
            </tr>
            </tfoot>
        </table>
    </div>
</section>

<h2 id="subcomprobaciones">Subcomprobaciones</h2>
<div class="catalogo__barra-fija">
    <form method="get" action="/catalogo#subcomprobaciones" class="catalogo__buscar" role="search" aria-label="Buscar en el catálogo">
        <?php if ($filtros['bloque'] !== null): ?><input type="hidden" name="bloque" value="<?= e($filtros['bloque']->value) ?>"><?php endif; ?>
        <?php if ($filtros['cobertura'] !== null): ?><input type="hidden" name="cobertura" value="<?= e($filtros['cobertura']->value) ?>"><?php endif; ?>
        <label for="filtro-texto" class="visualmente-oculto">Buscar en el catálogo</label>
        <input id="filtro-texto" name="texto" type="search" maxlength="100" value="<?= e($filtros['texto']) ?>" placeholder="Buscar: title, 1.1.1, robots, contraste…">
        <button type="submit" class="boton boton--pequeno">Buscar</button>
    </form>
    <nav class="catalogo__chips" aria-label="Filtrar por bloque">
        <a href="<?= e($enlace(['bloque' => null])) ?>"<?= $filtros['bloque'] === null ? ' aria-current="true"' : '' ?>>Todos</a>
        <?php foreach ($bloques as $b): ?>
            <a href="<?= e($enlace(['bloque' => $b->value])) ?>"<?= $filtros['bloque'] === $b ? ' aria-current="true"' : '' ?>><?= e($b->etiqueta()) ?></a>
        <?php endforeach; ?>
    </nav>
    <nav class="catalogo__chips" aria-label="Filtrar por cobertura">
        <a href="<?= e($enlace(['cobertura' => null])) ?>"<?= $filtros['cobertura'] === null ? ' aria-current="true"' : '' ?>>Cualquier cobertura</a>
        <?php foreach ($coberturas as $c): ?>
            <a href="<?= e($enlace(['cobertura' => $c->value])) ?>"<?= $filtros['cobertura'] === $c ? ' aria-current="true"' : '' ?>><?= e($c->etiqueta()) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if ($criterios !== []): ?>
        <nav class="catalogo__indice" aria-label="Ir a un criterio">
            <?php foreach ($criterios as $criterio): ?>
                <a href="#<?= e($criterio['id']) ?>" title="<?= e($criterio['nombre']) ?>"><?= e($criterio['id']) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
</div>

<p role="status" class="catalogo__estado">
    Se muestran <strong><?= e($mostradas) ?> de <?= e($total) ?></strong> subcomprobaciones en <?= e(count($criterios)) ?> criterios.
    <?php if ($filtrado): ?><a href="/catalogo#subcomprobaciones">Quitar filtros</a><?php endif; ?>
</p>

<?php if ($criterios === []): ?>
    <p class="aviso">Ninguna subcomprobación coincide con los filtros.</p>
<?php endif; ?>

<?php foreach ($criterios as $criterio): ?>
    <?php
    $porCobertura = array_count_values(array_map(static fn (array $s): string => $s['cobertura']->value, $criterio['subcomprobaciones']));
    ?>
    <details class="criterio" id="<?= e($criterio['id']) ?>"<?= $filtrado ? ' open' : '' ?>>
        <summary>
            <span class="criterio__id"><?= e($criterio['id']) ?></span>
            <span class="criterio__nombre"><?= e($criterio['nombre']) ?></span>
            <span class="criterio__bloque"><?= e($criterio['bloque']->etiqueta()) ?></span>
            <span class="criterio__recuento">
                <?php foreach ($coberturas as $c): ?>
                    <?php if (($porCobertura[$c->value] ?? 0) > 0): ?>
                        <span class="cobertura cobertura--<?= e($c->value) ?>" title="<?= e($c->etiqueta()) ?>"><?= e($porCobertura[$c->value]) ?> <?= e($corto[$c->value]) ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </span>
        </summary>
        <div class="criterio__cuerpo">
            <?php if ($criterio['explicacion'] !== ''): ?>
                <p><?= e($criterio['explicacion']) ?></p>
            <?php endif; ?>
            <dl class="criterio__datos">
                <?php if ($criterio['modo'] !== ''): ?>
                    <div><dt>Modo</dt><dd><?= e($criterio['modo']) ?></dd></div>
                <?php endif; ?>
                <?php if ($criterio['esfuerzo'] !== ''): ?>
                    <div><dt>Esfuerzo orientativo</dt><dd><?= e($criterio['esfuerzo']) ?></dd></div>
                <?php endif; ?>
                <?php if (($criterio['responsable'] ?? '') !== ''): ?>
                    <div><dt>Quién suele arreglarlo</dt><dd><?= e($criterio['responsable']) ?></dd></div>
                <?php endif; ?>
                <?php if ($criterio['fuentes'] !== []): ?>
                    <div><dt>Fuente</dt><dd>
                        <?php foreach ($criterio['fuentes'] as $i => $fuente): ?>
                            <?= $i > 0 ? ' · ' : '' ?><?php if (str_starts_with((string) ($fuente['url'] ?? ''), 'https://')): ?><a href="<?= e($fuente['url']) ?>" rel="noopener noreferrer"><?= e($fuente['nombre']) ?></a><?php else: ?><?= e($fuente['nombre']) ?><?php endif; ?>
                        <?php endforeach; ?>
                    </dd></div>
                <?php endif; ?>
            </dl>

            <div class="tabla-desplazable">
                <table class="catalogo__tabla">
                    <caption class="visualmente-oculto">Subcomprobaciones de <?= e($criterio['id']) ?></caption>
                    <thead>
                    <tr>
                        <th scope="col">Subcomprobación</th>
                        <th scope="col">Qué comprueba</th>
                        <th scope="col">Cobertura</th>
                        <th scope="col">Detalles</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($criterio['subcomprobaciones'] as $s): ?>
                        <tr id="<?= e($s['id']) ?>">
                            <th scope="row">
                                <code><?= e($s['id']) ?></code><br>
                                <?= e($s['nombre']) ?>
                            </th>
                            <td data-etiqueta="Qué comprueba"><?= e($s['que_comprueba']) ?></td>
                            <td data-etiqueta="Cobertura">
                                <span class="cobertura cobertura--<?= e($s['cobertura']->value) ?>"><?= e($s['cobertura']->etiqueta()) ?></span>
                            </td>
                            <td data-etiqueta="Detalles">
                                <ul class="catalogo__detalles">
                                    <?php foreach ($s['notas'] as $nota): ?>
                                        <li><?= e($nota) ?></li>
                                    <?php endforeach; ?>
                                    <?php if ($s['modo'] !== ''): ?><li>Modo: <?= e($s['modo']) ?></li><?php endif; ?>
                                    <?php if ($s['alcance'] !== ''): ?><li>Alcance mínimo: <?= e($s['alcance']) ?></li><?php endif; ?>
                                    <?php foreach ($s['wcag'] as $w): ?>
                                        <li>WCAG <?= e($w['criterio']) ?> <?= e($w['nombre']) ?> (nivel <?= e($w['nivel']) ?>, <?= e($w['version']) ?>)</li>
                                    <?php endforeach; ?>
                                    <?php if ($s['kit_digital']): ?><li>Compromiso Kit Digital</li><?php endif; ?>
                                    <?php if ($s['mvp'] !== null && $s['cobertura'] !== Cobertura::Implementada): ?><li><?= e($s['mvp']) ?></li><?php endif; ?>
                                </ul>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </details>
<?php endforeach; ?>
