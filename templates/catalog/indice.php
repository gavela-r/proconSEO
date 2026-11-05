<?php
/**
 * Catálogo y cobertura (RF-60). Lista todas las subcomprobaciones con su cobertura real.
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
?>
<h1>Catálogo y cobertura</h1>
<p class="entradilla">
    Todo lo que proconSEO revisa de una web, criterio a criterio, y qué parte está ya programada.
    Lo que no está implementado aparece en los informes como «No verificable», nunca como correcto.
</p>
<p class="catalogo__version">Versión del catálogo: <strong><?= e($version) ?></strong></p>

<h2 id="resumen">Resumen por bloque</h2>
<div class="tabla-desplazable">
    <table class="catalogo__resumen">
        <caption>Número de subcomprobaciones según su cobertura</caption>
        <thead>
        <tr>
            <th scope="col">Bloque</th>
            <?php foreach ($coberturas as $c): ?>
                <th scope="col"><?= e($c->etiqueta()) ?></th>
            <?php endforeach; ?>
            <th scope="col">Total</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($bloques as $b): ?>
            <tr>
                <th scope="row"><?= e($b->etiqueta()) ?></th>
                <?php foreach ($coberturas as $c): ?>
                    <td><?= e($resumen[$b->value][$c->value]) ?></td>
                <?php endforeach; ?>
                <td><?= e($resumen[$b->value]['total']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
        <tr>
            <th scope="row">Total</th>
            <?php foreach ($coberturas as $c): ?>
                <td><?= e($resumen['total'][$c->value]) ?></td>
            <?php endforeach; ?>
            <td><?= e($resumen['total']['total']) ?></td>
        </tr>
        </tfoot>
    </table>
</div>

<h2 id="subcomprobaciones">Subcomprobaciones</h2>
<form method="get" action="/catalogo" class="catalogo__filtros" role="search" aria-label="Filtrar el catálogo">
    <div>
        <label for="filtro-bloque">Bloque</label>
        <select id="filtro-bloque" name="bloque">
            <option value="">Todos</option>
            <?php foreach ($bloques as $b): ?>
                <option value="<?= e($b->value) ?>" <?= $filtros['bloque'] === $b ? 'selected' : '' ?>><?= e($b->etiqueta()) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="filtro-cobertura">Cobertura</label>
        <select id="filtro-cobertura" name="cobertura">
            <option value="">Todas</option>
            <?php foreach ($coberturas as $c): ?>
                <option value="<?= e($c->value) ?>" <?= $filtros['cobertura'] === $c ? 'selected' : '' ?>><?= e($c->etiqueta()) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="filtro-texto">Buscar</label>
        <input id="filtro-texto" name="texto" type="search" maxlength="100" value="<?= e($filtros['texto']) ?>" placeholder="title, 1.1.1, robots…">
    </div>
    <div class="catalogo__botones">
        <button type="submit" class="boton">Filtrar</button>
        <?php if ($filtrado): ?>
            <a class="boton boton--secundario" href="/catalogo#subcomprobaciones">Quitar filtros</a>
        <?php endif; ?>
    </div>
</form>

<p role="status">Se muestran <?= e($mostradas) ?> de <?= e($total) ?> subcomprobaciones.</p>

<?php if ($criterios === []): ?>
    <p class="aviso">Ninguna subcomprobación coincide con los filtros.</p>
<?php endif; ?>

<?php foreach ($criterios as $criterio): ?>
    <section class="criterio" id="<?= e($criterio['id']) ?>" aria-labelledby="titulo-<?= e($criterio['id']) ?>">
        <h3 id="titulo-<?= e($criterio['id']) ?>">
            <span class="criterio__id"><?= e($criterio['id']) ?></span> <?= e($criterio['nombre']) ?>
            <span class="criterio__bloque"><?= e($criterio['bloque']->etiqueta()) ?></span>
        </h3>
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
    </section>
<?php endforeach; ?>
