<?php
/**
 * Revisión manual guiada de un criterio WCAG: lo que ha visto la herramienta, cómo revisarlo a mano
 * y el formulario para apuntarlo. Cada revisión es una fila nueva; nunca cambia un resultado
 * automático (RF-42). Todo se imprime escapado (SEG-09).
 *
 * @var array<string, mixed> $analisis
 * @var array<string, mixed> $criterio fila de VistaWcag::$criterios
 * @var list<string> $pasos
 * @var list<array<string, mixed>> $historial
 * @var string|null $error
 * @var array<string, string> $valores
 * @var bool $guardado
 */

use ProconSEO\Projects\Formato;
use ProconSEO\Results\Etiquetas;
use ProconSEO\Results\Iconos;
use ProconSEO\Results\RevisionesManuales;
use ProconSEO\Results\VistaWcag;
use ProconSEO\Shared\Vista;

$id = (int) $analisis['id'];
$datos = $valores;
$v = static fn (string $campo): string => (string) ($datos[$campo] ?? '');
$manual = $criterio['manual'];
?>
<?php if ($analisis['es_dato_prueba'] || $analisis['proyecto_es_dato_prueba']): ?>
    <?= Vista::render('layout/aviso_prueba', [], false) ?>
<?php endif; ?>

<nav class="migas" aria-label="Estás en">
    <ol>
        <li><a href="/proyectos/<?= e($analisis['proyecto_id']) ?>"><?= e($analisis['proyecto_nombre']) ?></a></li>
        <li><a href="/analisis/<?= e($id) ?>">Análisis del <?= e(Formato::fecha((string) $analisis['creado_en'])) ?></a></li>
        <li><a href="/analisis/<?= e($id) ?>/wcag">Criterios WCAG</a></li>
        <li aria-current="page"><?= e($criterio['numero']) ?></li>
    </ol>
</nav>

<h1><span class="codigo"><?= e($criterio['numero']) ?></span> <?= e($criterio['nombre']) ?></h1>
<p>
    Nivel <?= e($criterio['nivel']) ?><?= $criterio['nuevo22'] ? ' · nuevo en WCAG 2.2' : '' ?> ·
    situación: <span class="wcag-situacion wcag-situacion--<?= e($criterio['situacion']) ?>"><?= e(VistaWcag::SITUACIONES[$criterio['situacion']]) ?></span>
    <?php if ($manual !== null): ?>(revisado a mano)<?php endif; ?>
</p>

<?php if ($guardado): ?>
    <p class="aviso aviso--info" role="status"><strong>Revisión guardada.</strong> Aparece en el historial y en la tabla de criterios WCAG.</p>
<?php endif; ?>

<div class="columnas">
    <div>
        <section class="seccion" aria-labelledby="t-automatico">
            <h2 id="t-automatico">Lo que ha comprobado la herramienta</h2>
            <?php if ($criterio['comprobaciones'] === []): ?>
                <p class="vacio">Nada: este criterio solo se puede revisar a mano.</p>
            <?php else: ?>
                <ul class="tabla-wcag__lista">
                    <?php foreach ($criterio['comprobaciones'] as $comp): ?>
                        <li>
                            <span class="estado estado--<?= e($comp['estado']) ?>"><?= Iconos::estado($comp['estado']) ?><?= e(Etiquetas::estado($comp['estado'])) ?></span>
                            <a href="/resultados/<?= e($comp['id']) ?>"><?= e($comp['titulo']) ?></a>
                            <?php if ($comp['total'] > 1): ?><span class="meta">· en <?= e($comp['paginas']) ?> de <?= e($comp['total']) ?> páginas</span><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($criterio['situacion_automatica'] === 'problemas'): ?>
                    <p class="aviso" role="note">La herramienta ya ha encontrado problemas en este criterio: una revisión manual los completa, pero no los quita.</p>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="seccion" aria-labelledby="t-guia">
            <h2 id="t-guia">Cómo revisarlo a mano</h2>
            <?php if ($pasos === []): ?>
                <p class="vacio">No hay guía para este criterio.</p>
            <?php else: ?>
                <ol class="guia">
                    <?php foreach ($pasos as $paso): ?><li><?= e($paso) ?></li><?php endforeach; ?>
                </ol>
                <p class="ayuda">Basta un navegador y el teclado; para lo que dice «lector de pantalla», NVDA (gratuito, en Windows). Revisa al menos la portada y una página de cada tipo (por ejemplo, una ficha, un artículo y el formulario de contacto).</p>
            <?php endif; ?>
        </section>
    </div>

    <aside>
        <section class="panel" aria-labelledby="t-revisar">
            <h2 id="t-revisar">Apuntar la revisión</h2>
            <?php if ($error !== null): ?>
                <p class="aviso aviso--error" role="alert"><?= e($error) ?></p>
            <?php endif; ?>
            <form class="formulario" method="post" action="/analisis/<?= e($id) ?>/wcag/revisiones">
                <?= csrf_campo() ?>
                <input type="hidden" name="criterio" value="<?= e($criterio['numero']) ?>">
                <fieldset>
                    <legend>Qué has visto</legend>
                    <?php foreach (RevisionesManuales::RESULTADOS as $valor => $texto): ?>
                        <label class="opcion"><input type="radio" name="resultado" value="<?= e($valor) ?>" required<?= $v('resultado') === $valor ? ' checked' : '' ?>><span><?= e($texto) ?></span></label>
                    <?php endforeach; ?>
                </fieldset>
                <label for="revisor">Tu nombre</label>
                <input id="revisor" name="revisor" type="text" required maxlength="<?= e(RevisionesManuales::MAX_REVISOR) ?>" autocomplete="name" value="<?= e($v('revisor')) ?>">
                <label for="paginas">Qué páginas has revisado</label>
                <textarea id="paginas" name="paginas" rows="3" maxlength="<?= e(RevisionesManuales::MAX_TEXTO) ?>" aria-describedby="ayuda-paginas"><?= e($v('paginas')) ?></textarea>
                <span class="ayuda" id="ayuda-paginas">Una por línea, o el recorrido (por ejemplo, «portada, ficha de producto y pago»).</span>
                <label for="notas">Qué has hecho y qué has visto <span class="obligatorio" aria-hidden="true">*</span><span class="solo-lectores">(obligatorio)</span></label>
                <textarea id="notas" name="notas" rows="5" required maxlength="<?= e(RevisionesManuales::MAX_TEXTO) ?>"><?= e($v('notas')) ?></textarea>
                <button class="boton" type="submit">Guardar revisión</button>
            </form>
        </section>

        <section class="panel" aria-labelledby="t-historial">
            <h2 id="t-historial">Historial</h2>
            <?php if ($historial === []): ?>
                <p>Nadie lo ha revisado a mano todavía en este análisis.</p>
            <?php else: ?>
                <ol class="historial">
                    <?php foreach ($historial as $i => $h): ?>
                        <li>
                            <strong><?= e(RevisionesManuales::RESULTADOS[(string) $h['resultado']] ?? $h['resultado']) ?></strong><?= $i === 0 ? ' (la vigente)' : '' ?><br>
                            <?= e($h['revisor']) ?> · <?= e(Formato::fecha((string) $h['creado_en'])) ?>
                            <?php if ($h['paginas'] !== null): ?><br><span class="meta">Páginas: <?= e($h['paginas']) ?></span><?php endif; ?>
                            <p><?= e($h['notas']) ?></p>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>
    </aside>
</div>

<p><a href="/analisis/<?= e($id) ?>/wcag">← Volver a los criterios WCAG</a></p>
