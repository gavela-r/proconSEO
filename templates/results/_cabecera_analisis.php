<?php
/**
 * Cabecera común del dashboard y de la vista Kit Digital: aviso de prueba, migas, título y pestañas.
 *
 * @var array<string, mixed> $analisis
 * @var string $vista 'todos' o 'kit'
 * @var bool $conKitDigital
 */

use ProconSEO\Projects\Formato;
use ProconSEO\Shared\Enum\EstadoAnalisis;
use ProconSEO\Shared\Vista;

$id = (int) $analisis['id'];
$estado = EstadoAnalisis::tryFrom((string) $analisis['estado']);
$enMarcha = in_array($estado, [EstadoAnalisis::EnCola, EstadoAnalisis::EnCurso, EstadoAnalisis::Cancelando], true);
$url = $analisis['url_final'] ?? $analisis['url_normalizada'] ?? $analisis['url_introducida'];
?>
<?php if ($analisis['es_dato_prueba'] || $analisis['proyecto_es_dato_prueba']): ?>
    <?= Vista::render('layout/aviso_prueba', [], false) ?>
<?php endif; ?>

<nav class="migas" aria-label="Estás en">
    <ol>
        <li><a href="/proyectos">Proyectos</a></li>
        <li><a href="/proyectos/<?= e($analisis['proyecto_id']) ?>"><?= e($analisis['proyecto_nombre']) ?></a></li>
        <li aria-current="page">Análisis del <?= e(Formato::fecha((string) $analisis['creado_en'])) ?></li>
    </ol>
</nav>

<div class="cabecera-pagina">
    <div>
        <h1><?= $vista === 'kit' ? 'Compromisos Kit Digital' : 'Resultados del análisis' ?></h1>
        <p><span class="codigo"><?= e($url) ?></span> · <?= e(Formato::fecha((string) $analisis['creado_en'])) ?> · <?= e($estado?->etiqueta() ?? $analisis['estado']) ?></p>
    </div>
</div>

<?php if ($enMarcha): ?>
    <p class="aviso aviso--info" role="status">El análisis todavía no ha terminado: estos resultados pueden cambiar. <a href="/analisis/<?= e($id) ?>/progreso">Ver el progreso</a>.</p>
<?php elseif ($analisis['motivo_cierre'] !== null): ?>
    <p class="aviso" role="note"><strong><?= e($estado?->etiqueta() ?? '') ?>.</strong> <?= e($analisis['motivo_cierre']) ?></p>
<?php endif; ?>

<?php if ($conKitDigital): ?>
    <nav aria-label="Vista de los resultados">
        <ul class="pestanas">
            <li><a href="/analisis/<?= e($id) ?>"<?= $vista === 'todos' ? ' aria-current="page"' : '' ?>>Todos los resultados</a></li>
            <li><a href="/analisis/<?= e($id) ?>/kit-digital"<?= $vista === 'kit' ? ' aria-current="page"' : '' ?>>Compromisos Kit Digital</a></li>
        </ul>
    </nav>
<?php endif; ?>
