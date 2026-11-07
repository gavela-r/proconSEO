<?php
/**
 * Cabecera común del dashboard y de la vista Kit Digital: aviso de prueba, migas, título y pestañas.
 *
 * @var array<string, mixed> $analisis
 * @var string $vista 'todos', 'paginas', 'wcag' o 'kit'
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
        <h1><?= e(['kit' => 'Compromisos Kit Digital', 'paginas' => 'Páginas analizadas', 'wcag' => 'Criterios WCAG 2.2'][$vista] ?? 'Resultados del análisis') ?></h1>
        <p><span class="codigo"><?= e($url) ?></span> · <?= e(Formato::fecha((string) $analisis['creado_en'])) ?> · <?= e($estado?->etiqueta() ?? $analisis['estado']) ?></p>
    </div>
    <div class="acciones">
        <a class="boton boton--secundario boton--pequeno" href="/analisis/<?= e($id) ?>/exportar/pdf" download>Exportar PDF</a>
        <a class="boton boton--secundario boton--pequeno" href="/analisis/<?= e($id) ?>/exportar/csv-hallazgos" download title="Una fila por hallazgo, con sus páginas: para repartir el trabajo">CSV por hallazgo</a>
        <a class="boton boton--secundario boton--pequeno" href="/analisis/<?= e($id) ?>/exportar/csv" download title="Una fila por resultado, con todo el detalle">CSV completo</a>
        <a class="boton boton--secundario boton--pequeno" href="/analisis/<?= e($id) ?>/exportar/json" download>Exportar JSON</a>
    </div>
</div>

<?php if ($enMarcha): ?>
    <p class="aviso aviso--info" role="status">El análisis todavía no ha terminado: estos resultados pueden cambiar. <a href="/analisis/<?= e($id) ?>/progreso">Ver el progreso</a>.</p>
<?php elseif ($analisis['motivo_cierre'] !== null): ?>
    <p class="aviso" role="note"><strong><?= e($estado?->etiqueta() ?? '') ?>.</strong> <?= e($analisis['motivo_cierre']) ?></p>
<?php endif; ?>

<?php
// Formas de mirar el mismo análisis: por hallazgos, por páginas y por criterios WCAG.
$pestanas = ['todos' => ['', 'Hallazgos'], 'paginas' => ['/paginas', 'Por páginas'], 'wcag' => ['/wcag', 'Criterios WCAG']];
if ($conKitDigital) {
    $pestanas['kit'] = ['/kit-digital', 'Compromisos Kit Digital'];
}
?>
<nav aria-label="Vista de los resultados">
    <ul class="pestanas">
        <?php foreach ($pestanas as $clave => [$ruta, $texto]): ?>
            <li><a href="/analisis/<?= e($id) ?><?= e($ruta) ?>"<?= $vista === $clave ? ' aria-current="page"' : '' ?>><?= e($texto) ?></a></li>
        <?php endforeach; ?>
    </ul>
</nav>
