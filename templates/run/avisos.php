<?php
/**
 * Avisos de los análisis programados: lo grave que ha aparecido y las webs que no respondían.
 *
 * @var list<array<string, mixed>> $avisos
 * @var int $sinLeer
 * @var bool $soloSinLeer
 */

use ProconSEO\Projects\Formato;
?>
<h1>Avisos</h1>
<p class="entradilla">
    Lo que han encontrado los <strong>análisis programados</strong>: problemas graves que no estaban en el análisis
    anterior y webs que no se pudieron analizar. Los análisis lanzados a mano no crean avisos.
</p>

<div class="acciones">
    <?php if ($soloSinLeer): ?>
        <a class="boton boton--secundario boton--pequeno" href="/avisos?todos=1">Ver también los leídos</a>
    <?php else: ?>
        <a class="boton boton--secundario boton--pequeno" href="/avisos">Ver solo los no leídos</a>
    <?php endif; ?>
    <?php if ($sinLeer > 0): ?>
        <form method="post" action="/avisos/leidos">
            <?= csrf_campo() ?>
            <button type="submit" class="boton boton--secundario boton--pequeno">Marcar todos como leídos</button>
        </form>
    <?php endif; ?>
</div>

<?php if ($avisos === []): ?>
    <p class="vacio"><?= $soloSinLeer ? 'No hay avisos sin leer.' : 'Todavía no hay ningún aviso.' ?></p>
<?php else: ?>
    <ul class="avisos">
        <?php foreach ($avisos as $a): ?>
            <?php $leido = $a['leido_en'] !== null; ?>
            <li class="aviso-item<?= $leido ? ' aviso-item--leido' : '' ?>">
                <p class="aviso-item__titulo">
                    <strong><?= e($a['titulo']) ?></strong>
                    <?= $leido ? '' : '<span class="etiqueta-nuevo">Sin leer</span>' ?>
                </p>
                <p class="ayuda">
                    <a href="/proyectos/<?= e($a['proyecto_id']) ?>"><?= e($a['proyecto_nombre']) ?></a><?= $a['proyecto_es_dato_prueba'] ? ' (demostración)' : '' ?>
                    · <?= e(Formato::fecha($a['creado_en'])) ?>
                </p>
                <?php if ($a['detalle'] !== null && $a['detalle'] !== ''): ?>
                    <p class="aviso-item__detalle"><?= nl2br(e($a['detalle'])) ?></p>
                <?php endif; ?>
                <div class="acciones">
                    <a class="boton boton--pequeno" href="/analisis/<?= e($a['analisis_id']) ?>#t-cambios">Ver el análisis</a>
                    <?php if (!$leido): ?>
                        <form method="post" action="/avisos/<?= e($a['id']) ?>/leido">
                            <?= csrf_campo() ?>
                            <button type="submit" class="boton boton--secundario boton--pequeno">Marcar como leído</button>
                        </form>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
