<?php
/**
 * Informe en PDF (RF-50), en el orden de la especificación 4.4 (RF-21). Lo convierte Dompdf
 * sin cargar recursos remotos. Todo dato se imprime escapado con e() (SEG-09).
 * Sin notas ni porcentajes: solo recuentos por estado.
 *
 * @var array<string, mixed> $analisis fila de RepositorioResultados::analisis()
 * @var \ProconSEO\Results\Informe $informe
 * @var array<string, mixed> $exportacion DatosExportacion::analisis() (evidencias y revisiones)
 * @var string $generado fecha de generación
 */

use ProconSEO\Projects\Formato;
use ProconSEO\Results\Etiquetas;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\Bloque;
use ProconSEO\Shared\Enum\DecisionRevision;
use ProconSEO\Shared\Enum\EstadoAnalisis;
use ProconSEO\Shared\Enum\EstadoFase;
use ProconSEO\Shared\Enum\EstadoResultado;
use ProconSEO\Shared\Enum\Fase;
use ProconSEO\Shared\Enum\ResultadoDescarga;

$esPrueba = (bool) $exportacion['datos_de_prueba'];
$evidencias = array_column($exportacion['evidencias'], null, 'codigo');
$revisiones = [];
foreach ($exportacion['revisiones'] as $v) {
    $revisiones[$v['resultado_id']][] = $v;
}
$ordenEstados = [
    EstadoResultado::Problema, EstadoResultado::DecisionRevisar, EstadoResultado::Mejorable,
    EstadoResultado::Correcto, EstadoResultado::NoAplicable, EstadoResultado::NoVerificable,
];
$recortar = static fn (?string $t, int $max = 1500): string => $t === null ? '' : (mb_strlen($t) > $max ? mb_substr($t, 0, $max) . ' […]' : $t);
$referencia = static fn (array $r): ?string => Etiquetas::wcag($r) ?? Etiquetas::normativa($r);

/** Bloque de un resultado con todo su detalle. */
$detalle = static function (array $r) use ($evidencias, $revisiones, $recortar, $referencia): string {
    ob_start();
    $gravedad = Etiquetas::gravedad($r);
    ?>
    <div class="resultado">
        <p class="etiquetas">
            <span class="estado estado-<?= e($r['estado_vigente']) ?>"><?= e(Etiquetas::estado((string) $r['estado_vigente'])) ?></span>
            <?php if ($gravedad !== null): ?> · <strong><?= e($gravedad) ?></strong><?php endif; ?>
            · <?= e(Etiquetas::bloque((string) $r['bloque'])) ?>
            <?php if (($ref = $referencia($r)) !== null): ?> · <?= e($ref) ?><?php endif; ?>
            <?php if ($r['revisado']): ?> · revisado (antes: <?= e(Etiquetas::estado((string) $r['estado'])) ?>)<?php endif; ?>
            <?php if ($r['revision_pendiente']): ?> · revisión humana pendiente<?php endif; ?>
        </p>
        <h4><?= e($r['titulo']) ?><?php if ($r['titulo'] !== $r['id_comprobacion']): ?> <span class="codigo"><?= e($r['id_comprobacion']) ?></span><?php endif; ?></h4>
        <p><?= e($r['explicacion_sencilla']) ?></p>
        <?php if ($r['consecuencia'] !== null): ?><p><strong>Por qué importa:</strong> <?= e($r['consecuencia']) ?></p><?php endif; ?>
        <?php if ($r['correccion'] !== null): ?><p><strong>Cómo corregirlo:</strong> <?= e($r['correccion']) ?></p><?php endif; ?>
        <?php if ($r['correccion_cms'] !== null): ?><p><strong>En el gestor de contenidos:</strong> <?= e($r['correccion_cms']) ?></p><?php endif; ?>
        <?php if ($r['url_recurso'] !== null || $r['elemento'] !== null || $r['valor_observado'] !== null): ?>
            <p class="pequeno">
                <?php if ($r['url_recurso'] !== null): ?>Página: <span class="codigo"><?= e($r['url_recurso']) ?></span><?php endif; ?>
                <?php if ($r['elemento'] !== null): ?> · Elemento: <span class="codigo"><?= e($recortar($r['elemento'], 300)) ?></span><?php endif; ?>
                <?php if ($r['valor_observado'] !== null): ?> · Valor: <span class="codigo"><?= e($recortar($r['valor_observado'], 300)) ?></span><?php endif; ?>
            </p>
        <?php endif; ?>
        <?php foreach (array_filter(explode(' ', (string) $r['evidencias'])) as $codigo): ?>
            <?php $ev = $evidencias[$codigo] ?? null; ?>
            <?php if ($ev !== null && $ev['contenido'] !== null): ?>
                <p class="pequeno"><strong><?= e($codigo) ?></strong><?= $ev['truncada'] ? ' (recortada)' : '' ?></p>
                <pre><?= e($recortar((string) $ev['contenido'])) ?></pre>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($r['limitacion'] !== null): ?><p class="pequeno"><strong>Qué no demuestra:</strong> <?= e($r['limitacion']) ?></p><?php endif; ?>
        <?php foreach ($revisiones[$r['id']] ?? [] as $v): ?>
            <div class="revision">
                <strong>Revisión de <?= e($v['revisor']) ?></strong> (<?= e(Formato::fecha((string) $v['creada_en_utc'])) ?>):
                <?= e(DecisionRevision::tryFrom((string) $v['decision'])?->etiqueta() ?? $v['decision']) ?><?= $v['nuevo_estado'] !== null ? ' → ' . e(Etiquetas::estado((string) $v['nuevo_estado'])) : '' ?>.
                <br>Motivo: <?= e($v['motivo']) ?> · Justificación: <?= e($v['justificacion']) ?>
                <?php foreach ($v['evidencias'] as $codigo): ?>
                    <?php $ev = $evidencias[$codigo] ?? null; ?>
                    <br><?= e($codigo) ?>: <?= e($ev === null ? '' : ($ev['contenido'] !== null ? $recortar((string) $ev['contenido'], 600) : 'captura (se ve en la herramienta)')) ?>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    return (string) ob_get_clean();
};

/**
 * Páginas en las que se repite un hallazgo. En un sitio completo el mismo fallo puede estar en
 * cientos de páginas: el detalle sale una vez y aquí solo la lista (como mucho 40 direcciones).
 */
$paginas = static function (array $resultados): string {
    $urls = array_values(array_unique(array_filter(array_map(static fn (array $r): string => (string) ($r['url_recurso'] ?? ''), $resultados))));
    if (count($urls) <= 1) {
        return '';
    }
    $mostradas = array_slice($urls, 0, 40);
    $resto = count($urls) - count($mostradas);

    return '<p class="pequeno"><strong>Se repite en ' . e(count($urls)) . ' páginas:</strong></p><p class="paginas codigo">'
        . implode('<br>', array_map(static fn (string $u): string => e($u), $mostradas))
        . ($resto > 0 ? '<br>… y ' . e($resto) . ' más (la lista completa está en la herramienta y en el CSV).' : '') . '</p>';
};

/** Tabla compacta para listas largas (correctos, no aplicables, no verificables): una fila por comprobación. */
$tabla = static function (array $lista, string $columna) use ($referencia): string {
    ob_start();
    ?>
    <table class="compacta">
        <thead><tr><th>Comprobación</th><th><?= e($columna) ?></th><th>Referencia</th><th class="num">Páginas</th></tr></thead>
        <tbody>
        <?php foreach (\ProconSEO\Results\Informe::agrupar($lista) as $g): ?>
            <?php $r = $g['principal']; ?>
            <tr>
                <td><?= e($r['titulo']) ?><br><span class="codigo"><?= e($r['id_comprobacion']) ?></span><?= $r['revision_pendiente'] ? '<br>revisión humana pendiente' : '' ?></td>
                <td><?= e($r['cobertura'] === 'no_implementada' && $r['limitacion'] !== null ? $r['limitacion'] : ($r['estado_vigente'] === 'no_verificable' && $r['limitacion'] !== null ? $r['limitacion'] : $r['explicacion_sencilla'])) ?></td>
                <td><?= e($referencia($r) ?? '') ?></td>
                <td class="num"><?= e(max(1, $g['paginas'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    return (string) ob_get_clean();
};

/** Una incidencia: un resultado, el mismo hallazgo en varias páginas o una causa con varios efectos (RF-24). */
$entrada = static function (array $e) use ($detalle, $paginas): string {
    if ($e['tipo'] === 'repetido') {
        return $detalle($e['principal']) . $paginas($e['resultados']);
    }
    if ($e['tipo'] !== 'causa' || count($e['resultados']) === 1) {
        return implode('', array_map($detalle, $e['resultados']));
    }
    $efectos = $e['efectos'] ?? \ProconSEO\Results\Informe::agrupar($e['resultados']);

    return '<div class="causa"><p class="etiquetas">Causa ' . e($e['codigo']) . ' · afecta a ' . e(count($e['bloques'])) . ' bloque(s)</p>'
        . '<h4>' . e($e['principal']['titulo']) . '</h4><p>' . e($e['descripcion']) . '</p>'
        . implode('', array_map(static fn (array $g): string => $detalle($g['principal']) . $paginas($g['resultados']), $efectos)) . '</div>';
};
$estado = EstadoAnalisis::tryFrom((string) $analisis['estado']);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Informe del análisis <?= e($analisis['id']) ?> · proconSEO</title>
<style>
    @page { margin: 22mm 16mm 18mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; color: #1b1f24; line-height: 1.4; }
    h1 { font-size: 17pt; margin: 0 0 4pt; }
    h2 { font-size: 13pt; margin: 16pt 0 6pt; padding-bottom: 3pt; border-bottom: 1.5pt solid #0b5cad; color: #0b5cad; }
    h3 { font-size: 11pt; margin: 10pt 0 4pt; }
    h4 { font-size: 10pt; margin: 2pt 0 3pt; }
    p { margin: 3pt 0; }
    table { width: 100%; border-collapse: collapse; margin: 4pt 0; }
    th, td { border: 0.5pt solid #cfd4da; padding: 3pt 4pt; text-align: left; vertical-align: top; }
    th { background: #f0f2f5; }
    .num { text-align: right; }
    .codigo, pre { font-family: "DejaVu Sans Mono", monospace; font-size: 8pt; }
    pre { white-space: pre-wrap; background: #f5f6f8; border: 0.5pt solid #cfd4da; padding: 4pt; margin: 2pt 0 4pt; }
    .pequeno { font-size: 8.5pt; color: #4a525c; }
    .resultado { border-left: 2.5pt solid #cfd4da; padding: 2pt 0 2pt 7pt; margin: 6pt 0; page-break-inside: avoid; }
    .causa { border: 1pt solid #b3261e; padding: 4pt 7pt; margin: 6pt 0; }
    .revision { background: #f5f6f8; padding: 3pt 5pt; margin: 3pt 0; font-size: 8.5pt; }
    .etiquetas { font-size: 8.5pt; color: #4a525c; }
    .estado { font-weight: bold; }
    .estado-problema { color: #b3261e; } .estado-mejorable { color: #8a5a00; } .estado-decision_revisar { color: #5b3fa0; }
    .estado-correcto { color: #1e6b34; } .estado-no_verificable, .estado-no_aplicable { color: #4a525c; }
    .compacta td, .compacta th { font-size: 8pt; }
    .paginas { font-size: 7.5pt; color: #4a525c; margin: 0 0 6pt 10pt; }
    .aviso { border: 1.5pt solid #8a5a00; background: #fff6e0; padding: 5pt 7pt; margin: 4pt 0 8pt; }
    .prueba { position: fixed; top: -15mm; left: 0; right: 0; text-align: center; font-weight: bold; color: #8a5a00; font-size: 9pt; }
    .pie { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7.5pt; color: #4a525c; }
    .pie .pagina:after { content: "Página " counter(page); }
</style>
</head>
<body>
<?php if ($esPrueba): ?>
    <div class="prueba">DATOS DE PRUEBA · no corresponden a ninguna web real</div>
<?php endif; ?>
<div class="pie">proconSEO · Informe interno de Proconsi · generado el <?= e($generado) ?> · <span class="pagina"></span></div>

<h1>Informe del análisis</h1>
<p><strong><?= e($analisis['proyecto_nombre']) ?></strong> · <span class="codigo"><?= e($analisis['url_final'] ?? $analisis['url_introducida']) ?></span></p>

<?php if ($esPrueba): ?>
    <p class="aviso"><strong>DATOS DE PRUEBA.</strong> Estos resultados son de demostración y no corresponden a ninguna web real.</p>
<?php endif; ?>

<!-- 1. Identificación del análisis y perfil -->
<table>
    <tr><th>Fecha</th><td><?= e(Formato::fecha((string) $analisis['creado_en'])) ?></td><th>Estado</th><td><?= e($estado?->etiqueta() ?? $analisis['estado']) ?></td></tr>
    <tr><th>Alcance</th><td><?= e(Alcance::tryFrom((string) $analisis['alcance'])?->etiqueta() ?? $analisis['alcance']) ?></td><th>Sitios autorizados</th><td class="codigo"><?= e(Etiquetas::lista($analisis['hosts_autorizados']) ?? '—') ?></td></tr>
    <tr><th>Perfil de ejecución</th><td><?= e($analisis['perfil_version'] ?? '—') ?></td><th>Versión del catálogo</th><td><?= e($analisis['version_catalogo'] ?? '—') ?></td></tr>
    <tr><th>Objetivo declarado</th><td colspan="3"><?= e($analisis['objetivo'] ?? 'Sin indicar') ?></td></tr>
    <?php if ($analisis['motivo_cierre'] !== null): ?>
        <tr><th>Cierre</th><td colspan="3"><?= e($analisis['motivo_cierre']) ?></td></tr>
    <?php endif; ?>
</table>

<!-- 2. Resumen por bloque (sin notas ni porcentajes) -->
<h2>Resumen</h2>
<p>Cuántos resultados hay en cada estado, después de las revisiones humanas. No es una nota: un solo problema crítico puede importar más que muchos correctos.</p>
<table>
    <thead><tr><th>Estado</th><?php foreach (Bloque::cases() as $b): ?><th class="num"><?= e($b->etiqueta()) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($ordenEstados as $e): ?>
        <tr>
            <td class="estado estado-<?= e($e->value) ?>"><?= e($e->etiqueta()) ?></td>
            <?php foreach (Bloque::cases() as $b): ?><td class="num"><?= e($informe->resumen[$b->value]['recuento'][$e->value] ?? 0) ?></td><?php endforeach; ?>
        </tr>
    <?php endforeach; ?>
    <tr>
        <td>Comprobaciones aún no implementadas</td>
        <?php foreach (Bloque::cases() as $b): ?><td class="num"><?= e($informe->resumen[$b->value]['no_implementadas']) ?></td><?php endforeach; ?>
    </tr>
    </tbody>
</table>

<?php if ($informe->total === 0): ?>
    <p class="aviso">Este análisis no tiene resultados: no se puede decir nada de la web, ni que esté bien ni que tenga problemas.</p>
<?php else: ?>

<!-- 3. Bloqueos -->
<h2>1. Bloqueos</h2>
<?php foreach (array_slice($informe->recursosBloqueados, 0, 30) as $rec): ?>
    <p>No se pudo descargar <span class="codigo"><?= e($rec['url']) ?></span>: <?= e(ResultadoDescarga::tryFrom((string) $rec['resultado'])?->etiqueta() ?? $rec['resultado']) ?><?= $rec['estado_http'] !== null ? ' (HTTP ' . e($rec['estado_http']) . ')' : '' ?><?= $rec['motivo'] !== null ? ' · ' . e($rec['motivo']) : '' ?>.</p>
<?php endforeach; ?>
<?= implode('', array_map($entrada, $informe->bloqueos)) ?>
<?php if ($informe->bloqueos === [] && $informe->recursosBloqueados === []): ?>
    <p>Entre los resultados obtenidos no hay ningún bloqueo de acceso ni de indexación.</p>
<?php endif; ?>

<!-- 4. Incidencias agrupadas por causa -->
<h2>2. Qué hay que corregir</h2>
<p class="pequeno">Problemas por gravedad, después decisiones a revisar y al final mejoras. Una misma causa aparece una vez con todos sus efectos.</p>
<?= $informe->incidencias === [] ? '<p>No hay más incidencias.</p>' : implode('', array_map($entrada, $informe->incidencias)) ?>

<!-- 5. Correctos y no aplicables -->
<h2>3. Lo que está bien o no aplica</h2>
<h3>Correctos (<?= e(count($informe->correctos)) ?>)</h3>
<?= $informe->correctos === [] ? '<p>Ninguno.</p>' : $tabla($informe->correctos, 'Qué se ha comprobado') ?>
<h3>No aplicables (<?= e(count($informe->noAplicables)) ?>)</h3>
<?= $informe->noAplicables === [] ? '<p>Ninguno.</p>' : $tabla($informe->noAplicables, 'Por qué no aplica') ?>

<!-- 6. No verificables y revisiones pendientes -->
<h2>4. Lo que no se ha podido comprobar</h2>
<p class="pequeno">«No verificable» no significa que esté mal ni que esté bien: la herramienta no tiene pruebas suficientes y lo dice.</p>
<?= $informe->noVerificables === [] ? '<p>Ninguno.</p>' : $tabla($informe->noVerificables, 'Qué falta') ?>

<?php endif; ?>

<?php
$manuales = [];
foreach ($exportacion['revisiones_manuales'] ?? [] as $m) {
    $manuales[(string) $m['criterio_wcag']] = $m; // la última de cada criterio es la vigente
}
?>
<?php if ($manuales !== []): ?>
    <h2>Revisiones manuales de criterios WCAG</h2>
    <p class="pequeno">Lo que una persona ha revisado a mano siguiendo la guía de cada criterio. No cambia ningún resultado automático.</p>
    <table class="compacta">
        <thead><tr><th>Criterio</th><th>Resultado</th><th>Quién y cuándo</th><th>Qué se revisó y qué se vio</th></tr></thead>
        <tbody>
        <?php foreach ($manuales as $criterio => $m): ?>
            <tr>
                <td><?= e($criterio) ?></td>
                <td><?= e(\ProconSEO\Results\RevisionesManuales::RESULTADOS[(string) $m['resultado']] ?? $m['resultado']) ?></td>
                <td><?= e($m['revisor']) ?><br><?= e(Formato::fecha((string) $m['creada_en_utc'])) ?></td>
                <td><?= $m['paginas'] !== null ? 'Páginas: ' . e($recortar((string) $m['paginas'], 300)) . '<br>' : '' ?><?= e($recortar((string) $m['notas'], 800)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<!-- 7. Capacidades pendientes y límites -->
<h2>5. Límites de este análisis</h2>
<?php if (Alcance::tryFrom((string) $analisis['alcance']) === Alcance::UrlIndividual): ?>
    <p>Solo se ha analizado una página (alcance «URL individual»). No se concluye nada sobre otras páginas.</p>
<?php endif; ?>
<?php foreach ($informe->fasesIncompletas as $f): ?>
    <p>Fase «<?= e(Fase::tryFrom((string) $f['fase'])?->etiqueta() ?? $f['fase']) ?>»: <?= e(mb_strtolower(EstadoFase::tryFrom((string) $f['estado'])?->etiqueta() ?? (string) $f['estado'])) ?>. <?= e($f['mensaje'] ?? '') ?></p>
<?php endforeach; ?>
<?php foreach ($informe->fueraDeAmbito as $fuera): ?>
    <p>No se siguieron <?= e($fuera['total']) ?> enlace(s) a <span class="codigo"><?= e($fuera['host']) ?></span>: no está entre los sitios autorizados del análisis. No es un error.</p>
<?php endforeach; ?>
<?php foreach ($informe->recursosNoEncontrados as $rec): ?>
    <p>No existe <span class="codigo"><?= e($rec['url']) ?></span> (HTTP <?= e($rec['estado_http']) ?>). No es un bloqueo.</p>
<?php endforeach; ?>
<?php foreach ($informe->recursosTruncados as $rec): ?>
    <p>Descarga incompleta de <span class="codigo"><?= e($rec['url']) ?></span>: superó el tamaño máximo y solo se analizó una parte.</p>
<?php endforeach; ?>
<?php foreach ($exportacion['eventos_seguridad'] as $ev): ?>
    <p>Aviso de seguridad (<?= e($ev['regla']) ?>): <?= e($ev['condicion']) ?> Decisión: <?= e($ev['decision']) ?>.</p>
<?php endforeach; ?>
<?php if ($informe->noImplementadas !== []): ?>
    <h3>Comprobaciones que la herramienta todavía no hace (<?= e(count($informe->noImplementadas)) ?>)</h3>
    <?= $tabla($informe->noImplementadas, 'Motivo') ?>
<?php endif; ?>
</body>
</html>
