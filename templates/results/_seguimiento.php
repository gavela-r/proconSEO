<?php
/**
 * Seguimiento del hallazgo en el proyecto: estado actual, historial y formulario para cambiarlo.
 * Todo dato se imprime escapado (SEG-09). No modifica el resultado (RF-42).
 *
 * @var array<string, mixed> $r
 * @var array<string, mixed>|null $seguimiento estado actual (Seguimientos::actuales) o null
 * @var list<array<string, mixed>> $historial
 * @var string|null $error
 * @var array<string, string> $valores lo escrito si el formulario volvió con un error
 * @var bool $guardado
 */

use ProconSEO\Projects\Formato;
use ProconSEO\Results\Seguimientos;

$actual = $seguimiento === null ? Seguimientos::PENDIENTE : (string) $seguimiento['estado'];
$elegido = $valores['estado'] ?? '';
$comprobacion = $seguimiento['comprobacion'] ?? null;
$opciones = [
    Seguimientos::PENDIENTE => 'Todavía nadie se ha puesto con ello.',
    Seguimientos::EN_CURSO => 'Alguien lo está arreglando.',
    Seguimientos::CORREGIDO => 'Ya está arreglado. El próximo análisis de esta dirección lo comprobará.',
    Seguimientos::ACEPTADO => 'Se deja así a propósito. Hay que explicar por qué; se puede deshacer.',
];
?>
<section class="panel panel--separado seguimiento" id="t-seguimiento" aria-labelledby="t-seguimiento-titulo">
    <h2 id="t-seguimiento-titulo">Seguimiento</h2>
    <?php if ($guardado): ?>
        <p class="aviso aviso--info" role="status"><strong>Seguimiento guardado.</strong> Vale para este hallazgo en todo el proyecto.</p>
    <?php endif; ?>

    <p class="seguimiento__actual seguimiento__actual--<?= e($comprobacion ?? $actual) ?>">
        <span class="seguimiento__estado"><?= e(Seguimientos::etiqueta($seguimiento)) ?></span>
        <?php if ($seguimiento !== null): ?>
            <span class="meta"><?= e((string) $seguimiento['autor']) ?> · <?= e(Formato::fecha((string) $seguimiento['creado_en'])) ?></span>
        <?php endif; ?>
    </p>
    <?php if ($comprobacion === 'comprobado'): ?>
        <p class="meta">El análisis siguiente de esta dirección ya no lo encuentra.</p>
    <?php elseif ($comprobacion === 'reaparece'): ?>
        <p class="aviso aviso--error">Se marcó como corregido, pero el análisis siguiente lo sigue encontrando.</p>
    <?php elseif ($actual === Seguimientos::CORREGIDO): ?>
        <p class="meta">Se comprobará en el próximo análisis de esta dirección y alcance.</p>
    <?php endif; ?>
    <?php if ($actual === Seguimientos::ACEPTADO && ($seguimiento['motivo'] ?? null) !== null): ?>
        <p class="seguimiento__motivo">«<?= e((string) $seguimiento['motivo']) ?>»</p>
    <?php endif; ?>

    <details class="seguimiento__cambiar"<?= $error !== null ? ' open' : '' ?>>
        <summary>Cambiar el seguimiento</summary>
        <form method="post" action="/resultados/<?= e($r['id']) ?>/seguimiento" novalidate<?= $error !== null ? ' aria-describedby="error-seguimiento"' : '' ?>>
            <?= csrf_campo() ?>
            <?php if ($error !== null): ?>
                <p class="aviso aviso--error" role="alert" id="error-seguimiento"><?= e($error) ?></p>
            <?php endif; ?>
            <fieldset>
                <legend>Estado</legend>
                <?php foreach ($opciones as $valor => $explicacion): ?>
                    <label class="opcion">
                        <input type="radio" name="estado" value="<?= e($valor) ?>"<?= ($elegido !== '' ? $elegido === $valor : $actual === $valor) ? ' checked' : '' ?>>
                        <span><strong><?= e(Seguimientos::ETIQUETAS[$valor]) ?></strong><?= e($explicacion) ?></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <label for="seguimiento-autor">Tu nombre</label>
            <input id="seguimiento-autor" name="autor" type="text" maxlength="<?= e(Seguimientos::MAX_AUTOR) ?>" autocomplete="name" required value="<?= e($valores['autor'] ?? '') ?>">
            <label for="seguimiento-motivo">Motivo <span class="opcional">(obligatorio para aceptar)</span></label>
            <textarea id="seguimiento-motivo" name="motivo" rows="3" maxlength="<?= e(Seguimientos::MAX_MOTIVO) ?>" placeholder="Por ejemplo: el cliente no quiere que se use para entrenar modelos de IA"><?= e($valores['motivo'] ?? '') ?></textarea>
            <p><button class="boton" type="submit">Guardar seguimiento</button></p>
        </form>
    </details>

    <?php if (count($historial) > 1): ?>
        <details class="seguimiento__historial">
            <summary>Historial (<?= e(count($historial)) ?> cambios)</summary>
            <ol class="historial">
                <?php foreach ($historial as $h): ?>
                    <li>
                        <strong><?= e(Seguimientos::ETIQUETAS[(string) $h['estado']] ?? (string) $h['estado']) ?></strong> · <?= e((string) $h['autor']) ?> · <?= e(Formato::fecha((string) $h['creado_en'])) ?>
                        <?php if ($h['motivo'] !== null): ?><p class="notas"><?= e((string) $h['motivo']) ?></p><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </details>
    <?php endif; ?>
</section>
