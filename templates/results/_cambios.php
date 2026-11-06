<?php
/**
 * Qué ha cambiado respecto a otro análisis del histórico del proyecto: por defecto, el anterior
 * de la misma dirección y alcance; se puede elegir cualquier otro anterior.
 * Sin notas ni porcentajes: solo qué se ha arreglado, qué es nuevo y qué sigue.
 *
 * @var array<string, mixed> $analisis Este análisis
 * @var array<string, mixed>|null $anterior El análisis con el que se compara
 * @var \ProconSEO\Results\ComparacionAnalisis|null $comparacion
 * @var list<array<string, mixed>> $candidatos Análisis anteriores del proyecto
 */

use ProconSEO\Projects\Formato;
use ProconSEO\Results\ComparacionAnalisis as C;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\EstadoResultado;
use ProconSEO\Shared\Enum\Gravedad;

$grupos = [
    C::NUEVOS => ['Nuevos', 'Problemas o mejoras que en el análisis anterior no estaban.', true],
    C::EMPEORADOS => ['Han empeorado', 'Siguen sin estar bien y ahora son más graves.', true],
    C::ARREGLADOS => ['Arreglados', 'Antes eran un problema o una mejora y ahora están bien o ya no aplican.', false],
    C::MEJORADOS => ['Han mejorado', 'Siguen sin estar bien del todo, pero son menos graves.', false],
    C::SIGUEN => ['Siguen igual', 'El mismo problema o mejora que en el análisis anterior.', false],
];
$estado = static function (?array $r): string {
    if ($r === null) {
        return '<span class="vacio">no se comprobaba</span>';
    }
    $e = EstadoResultado::tryFrom((string) $r['estado_vigente']);
    $g = $e === EstadoResultado::Problema ? Gravedad::tryFrom((string) ($r['gravedad'] ?? '')) : null;

    return '<span class="estado estado--' . e($e?->value ?? '') . '">' . e($e?->etiqueta() ?? (string) $r['estado_vigente']) . '</span>'
        . ($g !== null ? ' <span class="gravedad gravedad--' . e($g->value) . '">' . e($g->etiqueta()) . '</span>' : '');
};
$fecha = static fn (array $a): string => Formato::fecha($a['terminado_en'] ?? $a['creado_en']);
$etiquetaAlcance = static fn (mixed $v): string => Alcance::tryFrom((string) $v)?->etiqueta() ?? (string) $v;
$versionCatalogo = $analisis['version_catalogo'] ?? null;
$elegidoId = $anterior === null ? 0 : (int) $anterior['id'];
$otraDireccion = $anterior !== null && ($anterior['url_normalizada'] ?? null) !== ($analisis['url_normalizada'] ?? null);
$otroAlcance = $anterior !== null && ($anterior['alcance'] ?? null) !== ($analisis['alcance'] ?? null);
$otraVersion = $anterior !== null && ($anterior['version_catalogo'] ?? null) !== null && $versionCatalogo !== null && $anterior['version_catalogo'] !== $versionCatalogo;
$recuento = $comparacion?->recuento() ?? [];
?>
<section class="seccion" aria-labelledby="t-cambios">
    <h2 id="t-cambios">Cambios desde un análisis anterior</h2>

    <form class="cambios__elegir" method="get" action="/analisis/<?= e($analisis['id']) ?>#t-cambios">
        <label for="comparar">Comparar con</label>
        <select id="comparar" name="comparar">
            <?php foreach ($candidatos as $c): ?>
                <option value="<?= e($c['id']) ?>"<?= (int) $c['id'] === $elegidoId ? ' selected' : '' ?>>
                    <?= e($fecha($c)) ?> · <?= e($etiquetaAlcance($c['alcance'])) ?> · <?= e($c['url_normalizada'] ?? $c['url_introducida']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button class="boton boton--secundario boton--pequeno" type="submit">Comparar</button>
    </form>

    <?php if ($comparacion === null || $anterior === null): ?>
        <p class="vacio">No hay un análisis anterior de esta misma dirección y con este alcance. Elige otro del histórico del proyecto para ver qué ha cambiado.</p>
    <?php else: ?>
    <p class="seccion__ayuda">
        Comparado con el <a href="/analisis/<?= e($anterior['id']) ?>">análisis del <?= e($fecha($anterior)) ?></a>, después de las revisiones humanas.
    </p>
    <?php if ($otraDireccion || $otroAlcance): ?>
        <p class="aviso" role="note">
            Ese análisis es de <?= $otraDireccion ? 'otra dirección (<span class="codigo">' . e($anterior['url_normalizada'] ?? $anterior['url_introducida']) . '</span>)' : '' ?><?= $otraDireccion && $otroAlcance ? ' y ' : '' ?><?= $otroAlcance ? 'otro alcance (' . e($etiquetaAlcance($anterior['alcance'])) . ')' : '' ?>.
            Solo se comparan las comprobaciones de las páginas que coinciden en los dos.
        </p>
    <?php endif; ?>

    <?php if (!$comparacion->hayCambios() && $comparacion->comparadas === 0): ?>
        <p class="aviso" role="note">No se puede comparar: entre los dos análisis no hay ninguna comprobación que se haya podido hacer en ambos. No significa que no haya cambiado nada.</p>
    <?php elseif (!$comparacion->hayCambios()): ?>
        <p class="vacio">No ha cambiado nada: lo que estaba bien sigue bien<?= ($recuento[C::SIGUEN] ?? 0) > 0 ? ' y lo pendiente sigue pendiente' : '' ?>.</p>
    <?php else: ?>
        <ul class="cambios__recuento">
            <?php foreach ($grupos as $clave => [$titulo]): ?>
                <?php if (($recuento[$clave] ?? 0) > 0): ?>
                    <li><a href="#cambios-<?= e($clave) ?>"><strong><?= e($recuento[$clave]) ?></strong> <?= e(mb_strtolower($titulo)) ?></a></li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php foreach ($grupos as $clave => [$titulo, $ayuda, $abierto]): ?>
        <?php if ($comparacion->grupos[$clave] !== []): ?>
            <details class="plegable" id="cambios-<?= e($clave) ?>"<?= $abierto ? ' open' : '' ?>>
                <summary><?= e($titulo) ?> (<?= e($recuento[$clave]) ?>)</summary>
                <p class="ayuda"><?= e($ayuda) ?></p>
                <ul class="resultados">
                    <?php foreach ($comparacion->grupos[$clave] as $par): ?>
                        <?php $r = $par['ahora']; ?>
                        <li>
                            <a href="/resultados/<?= e($r['id']) ?>"><?= e($r['titulo'] ?? $r['id_comprobacion']) ?></a>
                            <span class="codigo"><?= e($r['id_comprobacion']) ?></span>
                            <?php if (($r['url_recurso'] ?? null) !== null): ?><br><span class="codigo"><?= e($r['url_recurso']) ?></span><?php endif; ?>
                            <br>Antes: <?= $estado($par['antes']) ?> → Ahora: <?= $estado($r) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>
    <?php endforeach; ?>

    <ul class="ayuda">
        <?php if ($comparacion->grupos[C::ARREGLADOS] !== []): ?>
            <li>Que algo esté arreglado en la web no significa que Google o los buscadores con IA ya lo hayan visto: pueden tardar días o semanas en volver a pasar por ella.</li>
        <?php endif; ?>
        <?php if ($comparacion->sinComparar > 0): ?>
            <li><?= e($comparacion->sinComparar) ?> <?= $comparacion->sinComparar === 1 ? 'comprobación no se puede comparar' : 'comprobaciones no se pueden comparar' ?>: en alguno de los dos análisis no se pudo comprobar o la página no se analizó.</li>
        <?php endif; ?>
        <?php if ($otraVersion): ?>
            <li>Los dos análisis usan versiones distintas del catálogo (<?= e($anterior['version_catalogo']) ?> y <?= e($versionCatalogo) ?>): algún cambio puede deberse a que ahora se comprueba algo más o de otra forma.</li>
        <?php endif; ?>
    </ul>
    <?php endif; ?>
</section>
