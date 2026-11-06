<?php
/**
 * Formulario de revisión humana (especificación 4.6, RF-40 a RF-43; capturas SEG-14).
 * La revisión se guarda aparte: el resultado original no se modifica nunca.
 *
 * @var array<string, mixed> $resultado
 * @var array<string, mixed>|null $analisis
 * @var list<array<string, mixed>> $evidencias
 * @var list<array<string, mixed>> $revisiones
 * @var array<string, string> $valores
 * @var array<string, string> $errores
 * @var array<string, string> $decisiones
 * @var array<string, string> $estados
 * @var int $maxCaptura
 */

use ProconSEO\Projects\Formato;
use ProconSEO\Results\Etiquetas;
use ProconSEO\Shared\Vista;

$valores ??= [];
$errores ??= [];
$r = $resultado;
$estadoActual = (string) $r['estado_vigente'];
$v = static fn (string $campo): string => $valores[$campo] ?? '';
$describir = static function (string $campo, ?string $ayuda = null) use ($errores): string {
    $ids = array_filter([$ayuda, isset($errores[$campo]) ? "error-{$campo}" : null]);

    return ($ids === [] ? '' : ' aria-describedby="' . e(implode(' ', $ids)) . '"') . (isset($errores[$campo]) ? ' aria-invalid="true"' : '');
};
$error = static fn (string $campo): string => isset($errores[$campo])
    ? '<span class="error-campo" id="error-' . e($campo) . '">' . e($errores[$campo]) . '</span>'
    : '';
$ayudaDecision = [
    'confirmar' => 'Estoy de acuerdo con lo que dice la herramienta.',
    'cambiar_estado' => 'El estado correcto es otro (elígelo abajo).',
    'mantener_no_verificable' => 'Tampoco se puede comprobar a mano ahora.',
    'pedir_evidencia' => 'Falta información, por ejemplo, preguntar al cliente. La revisión sigue pendiente.',
];
$esPrueba = $r['es_dato_prueba'] || ($analisis !== null && ($analisis['es_dato_prueba'] || $analisis['proyecto_es_dato_prueba']));
$wcag = Etiquetas::wcag($r);
?>
<?php if ($esPrueba): ?>
    <?= Vista::render('layout/aviso_prueba', [], false) ?>
<?php endif; ?>

<nav class="migas" aria-label="Estás en">
    <ol>
        <li><a href="/proyectos">Proyectos</a></li>
        <?php if ($analisis !== null): ?>
            <li><a href="/proyectos/<?= e($analisis['proyecto_id']) ?>"><?= e($analisis['proyecto_nombre']) ?></a></li>
            <li><a href="/analisis/<?= e($analisis['id']) ?>">Análisis del <?= e(Formato::fecha((string) $analisis['creado_en'])) ?></a></li>
        <?php endif; ?>
        <li><a href="/resultados/<?= e($r['id']) ?>"><?= e($r['id_comprobacion']) ?></a></li>
        <li aria-current="page">Revisar</li>
    </ol>
</nav>

<h1>Revisar resultado</h1>

<?php if ($errores !== []): ?>
    <div class="aviso aviso--error" role="alert">
        <p><strong>No se ha guardado.</strong> Revisa <?= count($errores) === 1 ? 'el campo marcado' : 'los ' . e(count($errores)) . ' campos marcados' ?>:</p>
        <ul>
            <?php foreach ($errores as $campo => $mensaje): ?>
                <li><a href="#<?= e($campo) ?>"><?= e($mensaje) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="columnas">
    <div>
        <form class="formulario" action="/resultados/<?= e($r['id']) ?>/revisiones" method="post" enctype="multipart/form-data" novalidate>
            <?= csrf_campo() ?>
            <p>Los campos con <span class="obligatorio" aria-hidden="true">*</span> son obligatorios. El resultado original no se modifica: tu revisión se guarda aparte.</p>

            <label for="revisor">Tu nombre <span class="obligatorio" aria-hidden="true">*</span><span class="solo-lectores">(obligatorio)</span></label>
            <input id="revisor" name="revisor" type="text" required maxlength="120" autocomplete="name" value="<?= e($v('revisor')) ?>"<?= $describir('revisor', 'ayuda-revisor') ?>>
            <span class="ayuda" id="ayuda-revisor">No hay inicio de sesión, así que tu nombre queda como autor de la revisión.</span>
            <?= $error('revisor') ?>

            <label for="motivo">Qué has revisado <span class="obligatorio" aria-hidden="true">*</span><span class="solo-lectores">(obligatorio)</span></label>
            <textarea id="motivo" name="motivo" rows="2" required maxlength="5000"<?= $describir('motivo', 'ayuda-motivo') ?>><?= e($v('motivo')) ?></textarea>
            <span class="ayuda" id="ayuda-motivo">Por ejemplo: «He comprobado con el cliente si el bloqueo de GPTBot es intencionado».</span>
            <?= $error('motivo') ?>

            <fieldset<?= isset($errores['decision']) ? ' aria-describedby="error-decision"' : '' ?>>
                <legend>Decisión <span class="obligatorio" aria-hidden="true">*</span><span class="solo-lectores">(obligatorio)</span></legend>
                <?php foreach ($decisiones as $valor => $texto): ?>
                    <label class="opcion">
                        <input type="radio" name="decision" value="<?= e($valor) ?>"<?= $v('decision') === $valor ? ' checked' : '' ?><?= $valor === array_key_first($decisiones) ? ' id="decision"' : '' ?>>
                        <span><strong><?= e($texto) ?></strong><?= e($ayudaDecision[$valor] ?? '') ?></span>
                    </label>
                <?php endforeach; ?>
                <?= $error('decision') ?>

                <label for="nuevo_estado">Nuevo estado <span class="ayuda">Solo si eliges «Cambiar estado». Ahora es «<?= e(Etiquetas::estado($estadoActual)) ?>».</span></label>
                <select id="nuevo_estado" name="nuevo_estado"<?= $describir('nuevo_estado', 'ayuda-estado') ?>>
                    <option value="">Elige un estado</option>
                    <?php foreach ($estados as $valor => $texto): ?>
                        <?php if ($valor === $estadoActual) {
                            continue;
                        } ?>
                        <option value="<?= e($valor) ?>"<?= $v('nuevo_estado') === $valor ? ' selected' : '' ?>><?= e($texto) ?><?= $valor === 'correcto' ? ' (exige evidencia)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="ayuda" id="ayuda-estado">Para pasar a «Correcto» hay que añadir evidencia abajo: un comentario solo no basta.</span>
                <?= $error('nuevo_estado') ?>
            </fieldset>

            <label for="justificacion">Justificación <span class="obligatorio" aria-hidden="true">*</span><span class="solo-lectores">(obligatorio)</span></label>
            <textarea id="justificacion" name="justificacion" rows="4" required maxlength="5000"<?= $describir('justificacion', 'ayuda-justificacion') ?>><?= e($v('justificacion')) ?></textarea>
            <span class="ayuda" id="ayuda-justificacion">Por qué decides esto. Se verá en el informe y en las exportaciones.</span>
            <?= $error('justificacion') ?>

            <fieldset>
                <legend>Evidencia que aportas</legend>
                <label for="evidencia_texto">Texto</label>
                <textarea id="evidencia_texto" name="evidencia_texto" rows="3" maxlength="20000"<?= $describir('evidencia_texto', 'ayuda-evidencia') ?>><?= e($v('evidencia_texto')) ?></textarea>
                <span class="ayuda" id="ayuda-evidencia">Por ejemplo, el texto de un correo del cliente o lo que has visto al probar con un lector de pantalla.</span>
                <?= $error('evidencia_texto') ?>

                <label for="captura">Captura de pantalla</label>
                <input id="captura" name="captura" type="file" accept="image/png,image/jpeg"<?= $describir('captura', 'ayuda-captura') ?>>
                <span class="ayuda" id="ayuda-captura">Solo PNG o JPG, como mucho <?= e(intdiv($maxCaptura, 1024 * 1024)) ?> MB. Se guarda una copia limpia, sin los datos ocultos de la imagen original.</span>
                <?= $error('captura') ?>
            </fieldset>

            <div class="formulario__botones">
                <button class="boton" type="submit">Guardar revisión</button>
                <a class="boton boton--secundario" href="/resultados/<?= e($r['id']) ?>">Cancelar</a>
            </div>
        </form>
    </div>

    <aside>
        <section class="panel" aria-labelledby="t-original">
            <h2 id="t-original">Resultado que revisas</h2>
            <div class="resultado__cabecera">
                <span class="estado estado--<?= e($estadoActual) ?>"><?= e(Etiquetas::estado($estadoActual)) ?></span>
                <?php if ($wcag !== null): ?>
                    <span class="chip <?= Etiquetas::esNuevo22($r) ? 'chip--wcag22' : 'chip--wcag21' ?>"><?= e($wcag) ?></span>
                <?php elseif (($normativa = Etiquetas::normativa($r)) !== null): ?>
                    <span class="chip chip--normativa"><?= e($normativa) ?></span>
                <?php endif; ?>
            </div>
            <p><strong><?= e($r['titulo']) ?></strong></p>
            <p><?= e($r['explicacion_sencilla']) ?></p>
            <?php if ($r['motivo_revision'] !== null): ?>
                <p><strong>Qué hay que revisar:</strong> <?= e($r['motivo_revision']) ?></p>
            <?php endif; ?>
            <?php foreach ($evidencias as $ev): ?>
                <div class="evidencia">
                    <div class="evidencia__cabecera"><strong><?= e($ev['codigo']) ?></strong><?php if ($ev['url_recurso'] !== null): ?><span class="codigo"><?= e($ev['url_recurso']) ?></span><?php endif; ?></div>
                    <?php if ($ev['contenido'] !== null): ?><pre><code><?= e($ev['contenido']) ?></code></pre><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if ($r['limitacion'] !== null): ?>
                <p class="ayuda"><?= e($r['limitacion']) ?></p>
            <?php endif; ?>
        </section>

        <section class="panel panel--separado" aria-labelledby="t-anteriores">
            <h2 id="t-anteriores">Revisiones anteriores</h2>
            <?php if ($revisiones === []): ?>
                <p class="vacio">Ninguna todavía.</p>
            <?php else: ?>
                <ol class="historial">
                    <?php foreach ($revisiones as $rev): ?>
                        <li>
                            <strong><?= e($decisiones[$rev['decision']] ?? $rev['decision']) ?></strong><?= $rev['nuevo_estado'] !== null ? ' → ' . e(Etiquetas::estado((string) $rev['nuevo_estado'])) : '' ?>
                            <br><span class="ayuda"><?= e($rev['revisor']) ?> · <?= e(Formato::fecha((string) $rev['creado_en'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>
    </aside>
</div>
