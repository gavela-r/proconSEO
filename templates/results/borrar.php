<?php
/**
 * Confirmación del borrado definitivo de un análisis (RF-16; decisión 16 de docs/decisiones.md).
 *
 * @var array<string, mixed> $analisis
 * @var array{resultados: int, evidencias: int, revisiones: int, capturas: int} $resumen
 * @var bool $enMarcha
 * @var string|null $error
 */

use ProconSEO\Projects\Formato;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\EstadoAnalisis;
use ProconSEO\Shared\Vista;

$id = (int) $analisis['id'];
$plural = static fn (int $n, string $uno, string $varios): string => $n === 1 ? "1 {$uno}" : "{$n} {$varios}";
?>
<?php if ($analisis['es_dato_prueba'] || $analisis['proyecto_es_dato_prueba']): ?>
    <?= Vista::render('layout/aviso_prueba', [], false) ?>
<?php endif; ?>

<nav class="migas" aria-label="Estás en">
    <ol>
        <li><a href="/proyectos">Proyectos</a></li>
        <li><a href="/proyectos/<?= e($analisis['proyecto_id']) ?>"><?= e($analisis['proyecto_nombre']) ?></a></li>
        <li><a href="/analisis/<?= e($id) ?>">Análisis del <?= e(Formato::fecha((string) $analisis['creado_en'])) ?></a></li>
        <li aria-current="page">Borrar</li>
    </ol>
</nav>

<h1>¿Borrar este análisis?</h1>

<?php if ($error !== null): ?>
    <p class="aviso aviso--error" role="alert" id="error-borrado"><?= e($error) ?></p>
<?php endif; ?>

<section class="panel" aria-labelledby="t-que">
    <h2 id="t-que">Qué se va a borrar</h2>
    <dl class="datos">
        <dt>Análisis</dt>
        <dd>
            <?= e(Formato::fecha((string) $analisis['creado_en'])) ?> ·
            <?= e(Alcance::tryFrom((string) $analisis['alcance'])?->etiqueta() ?? $analisis['alcance']) ?> ·
            <?= e(EstadoAnalisis::tryFrom((string) $analisis['estado'])?->etiqueta() ?? $analisis['estado']) ?>
        </dd>
        <dt>Dirección</dt><dd class="codigo"><?= e($analisis['url_final'] ?? $analisis['url_introducida']) ?></dd>
        <dt>Datos</dt>
        <dd>
            <?= e($plural($resumen['resultados'], 'resultado', 'resultados')) ?>,
            <?= e($plural($resumen['evidencias'], 'evidencia', 'evidencias')) ?>,
            <?= e($plural($resumen['revisiones'], 'revisión humana', 'revisiones humanas')) ?>
            <?= $resumen['capturas'] > 0 ? 'y ' . e($plural($resumen['capturas'], 'captura', 'capturas')) : '' ?>
        </dd>
    </dl>
</section>

<?php if ($enMarcha): ?>
    <p class="aviso aviso--error" role="note">
        <strong>Este análisis todavía está en marcha</strong> y no se puede borrar.
        <a href="/analisis/<?= e($id) ?>/progreso">Ve al progreso</a> para cancelarlo, o espera a que termine.
    </p>
    <p><a class="boton boton--secundario" href="/proyectos/<?= e($analisis['proyecto_id']) ?>">Volver al proyecto</a></p>
<?php else: ?>
    <p class="aviso aviso--error" role="note"><strong>No se puede deshacer.</strong> El análisis, sus resultados, evidencias, revisiones y capturas desaparecen para siempre. Solo queda una anotación en el registro de auditoría con quién lo borró y cuándo.</p>

    <section class="panel panel--separado" aria-labelledby="t-exportar">
        <h2 id="t-exportar">Antes de borrar, guarda una copia</h2>
        <p>Si puede hacerte falta más adelante, descárgalo primero:</p>
        <div class="acciones">
            <a class="boton boton--secundario boton--pequeno" href="/analisis/<?= e($id) ?>/exportar/pdf" download>PDF</a>
            <a class="boton boton--secundario boton--pequeno" href="/analisis/<?= e($id) ?>/exportar/csv" download>CSV (Excel)</a>
            <a class="boton boton--secundario boton--pequeno" href="/analisis/<?= e($id) ?>/exportar/json" download>JSON completo</a>
        </div>
    </section>

    <form class="formulario" action="/analisis/<?= e($id) ?>" method="post"<?= $error !== null ? ' aria-describedby="error-borrado"' : '' ?>>
        <?= csrf_campo() ?>
        <input type="hidden" name="_metodo" value="DELETE">
        <label class="opcion"><input type="checkbox" name="confirmo" value="1" required><span>Entiendo que este análisis se borra para siempre.</span></label>
        <div class="formulario__botones">
            <button class="boton boton--peligro" type="submit">Borrar análisis</button>
            <a class="boton boton--secundario" href="/proyectos/<?= e($analisis['proyecto_id']) ?>">No, volver al proyecto</a>
        </div>
    </form>
<?php endif; ?>
