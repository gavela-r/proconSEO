<?php
/**
 * @var array<string, mixed> $analisis
 * @var array<string, mixed>|null $proyecto
 * @var list<array<string, mixed>> $fases
 */
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\EstadoAnalisis;
use ProconSEO\Shared\Enum\EstadoFase;
use ProconSEO\Shared\Enum\Fase;

$estado = EstadoAnalisis::from((string) $analisis['estado']);
$enMarcha = in_array($estado, [EstadoAnalisis::EnCola, EstadoAnalisis::EnCurso, EstadoAnalisis::Cancelando], true);
$hosts = json_decode((string) $analisis['hosts_autorizados'], true) ?: [];
?>
<?php if ($proyecto && $proyecto['es_dato_prueba']): ?>
    <?= \ProconSEO\Shared\Vista::render('layout/aviso_prueba', [], false) ?>
<?php endif; ?>

<div id="progreso" data-id="<?= e($analisis['id']) ?>" data-en-marcha="<?= $enMarcha ? '1' : '0' ?>">
    <div class="titular">
        <div>
            <p class="meta"><?= e($proyecto['nombre'] ?? '—') ?> · <?= e(Alcance::from((string) $analisis['alcance'])->etiqueta()) ?></p>
            <h1>Análisis de la web</h1>
            <p class="progreso__url"><?= e($analisis['url_normalizada']) ?></p>
            <p class="estado-analisis meta" role="status">Estado: <strong id="estado-texto"><?= e($estado->etiqueta()) ?></strong></p>
        </div>
        <form method="post" action="/analisis/<?= e($analisis['id']) ?>/cancelar" id="form-cancelar"<?= $enMarcha ? '' : ' hidden' ?>>
            <?= csrf_campo() ?>
            <button type="submit" class="boton boton--secundario">Cancelar análisis</button>
        </form>
    </div>
    <p id="motivo" class="aviso"<?= $analisis['motivo_cierre'] ? '' : ' hidden' ?>><?= e($analisis['motivo_cierre']) ?></p>

    <h2 class="visualmente-oculto">Fases del análisis</h2>
    <ol class="fases" id="fases" aria-live="polite">
        <?php foreach ($fases as $f): ?>
            <li class="fase fase--<?= e($f['estado']) ?>" data-fase="<?= e($f['fase']) ?>">
                <span class="fase__nombre"><?= e(Fase::from((string) $f['fase'])->etiqueta()) ?></span>
                <span class="fase__estado"><?= e(EstadoFase::from((string) $f['estado'])->etiqueta()) ?></span>
                <span class="fase__mensaje"><?= e($f['mensaje']) ?></span>
            </li>
        <?php endforeach; ?>
    </ol>

    <div class="acciones">
        <a class="boton" id="ver-resultados" href="/analisis/<?= e($analisis['id']) ?>"<?= $enMarcha ? ' hidden' : '' ?>>Ver resultados</a>
    </div>
</div>

<details class="detalles">
    <summary>Ámbito y condiciones del análisis</summary>
    <ul>
        <li>Dominios autorizados: <?= e(implode(', ', $hosts)) ?> (no incluye otros subdominios).</li>
        <li>Perfil de ejecución: <?= e($analisis['perfil_id']) ?> · Catálogo: <?= e($analisis['version_catalogo']) ?></li>
        <li>Sin navegador en esta versión: lo que depende del renderizado aparece como no verificable.</li>
    </ul>
</details>

<script src="/assets/js/run.js" defer></script>
