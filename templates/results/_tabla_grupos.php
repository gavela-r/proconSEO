<?php
/**
 * Tabla compacta de resultados agrupados por comprobación (correctos y no aplicables): una fila por
 * comprobación con en cuántas páginas, en vez de una tarjeta por página. Todo se imprime escapado.
 *
 * @var list<array{principal: array<string, mixed>, resultados: list<array<string, mixed>>, paginas: int}> $grupos
 * @var array{titulo: string, campo: string}|null $motivo columna extra con el porqué (no aplica, no se pudo comprobar)
 */

use ProconSEO\Results\Etiquetas;

$motivo ??= null;
?>
<div class="tabla-desplazable">
    <table class="tabla tabla--grupos">
        <thead>
            <tr>
                <th scope="col">Comprobación</th>
                <th scope="col">Bloque</th>
                <th scope="col">Páginas</th>
                <?php if ($motivo !== null): ?><th scope="col"><?= e($motivo['titulo']) ?></th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($grupos as $g): ?>
                <?php $r = $g['principal']; ?>
                <tr>
                    <th scope="row">
                        <a href="/resultados/<?= e($r['id']) ?>"><?= e($r['titulo']) ?></a>
                        <?php if ($r['titulo'] !== $r['id_comprobacion']): ?><span class="codigo tabla__codigo"><?= e($r['id_comprobacion']) ?></span><?php endif; ?>
                    </th>
                    <td><?= e(Etiquetas::bloque((string) $r['bloque'])) ?></td>
                    <td>
                        <?php if ($g['paginas'] <= 1): ?>
                            <span class="codigo"><?= e($r['url_recurso'] ?? '—') ?></span>
                        <?php else: ?>
                            <details class="repeticion__paginas">
                                <summary><?= e($g['paginas']) ?> páginas</summary>
                                <ul>
                                    <?php foreach ($g['resultados'] as $otra): ?>
                                        <li><a class="codigo" href="/resultados/<?= e($otra['id']) ?>"><?= e($otra['url_recurso'] ?? $otra['id_comprobacion']) ?></a></li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php endif; ?>
                    </td>
                    <?php if ($motivo !== null): ?><td><?= e($r[$motivo['campo']] ?? $r['explicacion_sencilla']) ?></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
