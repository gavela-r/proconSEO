<?php
/**
 * Dashboard de un análisis en el orden de la especificación 4.4 (RF-20, RF-21, RF-24).
 * Sin notas ni porcentajes: solo cuántos resultados hay en cada estado.
 *
 * @var array<string, mixed> $analisis
 * @var \ProconSEO\Results\Informe $informe
 * @var list<array<string, mixed>> $eventos
 * @var bool $conKitDigital
 * @var array<string, mixed>|null $anterior
 * @var \ProconSEO\Results\ComparacionAnalisis|null $comparacion
 * @var list<array<string, mixed>> $candidatos Análisis anteriores del proyecto con los que se puede comparar
 */

use ProconSEO\Projects\Formato;
use ProconSEO\Results\Etiquetas;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\Bloque;
use ProconSEO\Shared\Enum\DecisionCierre;
use ProconSEO\Shared\Enum\EstadoAnalisis;
use ProconSEO\Shared\Enum\EstadoFase;
use ProconSEO\Shared\Enum\EstadoResultado;
use ProconSEO\Shared\Enum\Fase;
use ProconSEO\Shared\Enum\ResultadoDescarga;
use ProconSEO\Shared\Vista;

$id = (int) $analisis['id'];
$conKitDigital ??= false;
$estado = EstadoAnalisis::tryFrom((string) $analisis['estado']);
$enMarcha = in_array($estado, [EstadoAnalisis::EnCola, EstadoAnalisis::EnCurso, EstadoAnalisis::Cancelando], true);
$alcance = Alcance::tryFrom((string) $analisis['alcance']);
$filtro = $informe->filtro;
$filtrando = $filtro->activo();
/** Enlace al dashboard con estos filtros (y nada más). */
$enlace = static fn (array $filtros): string => '/analisis/' . $id . ($filtros === [] ? '' : '?' . http_build_query($filtros)) . '#resultados';
$preguntas = [
    'ia' => '¿Pueden los buscadores con IA encontrar la web y usarla como fuente?',
    'seo' => '¿Pueden Google y Bing encontrar, indexar y entender la web?',
    'acc' => '¿Puede usarla cualquier persona, también con lector de pantalla o teclado? (WCAG 2.2 A y AA)',
];
$numeroIncidencias = $informe->numeroIncidencias();
$sinResultados = array_sum(array_column($informe->resumen, 'total')) === 0;
// Primero lo que hay que corregir, después lo que está bien y al final lo que no se pudo comprobar.
$ordenEstados = [
    EstadoResultado::Problema, EstadoResultado::DecisionRevisar, EstadoResultado::Mejorable,
    EstadoResultado::Correcto, EstadoResultado::NoAplicable, EstadoResultado::NoVerificable,
];
$plural = static fn (int $n, string $uno, string $varios): string => $n === 1 ? "1 {$uno}" : "{$n} {$varios}";
?>
<!-- 1. Identificación del análisis y perfil -->
<?= Vista::render('results/_cabecera_analisis', ['analisis' => $analisis, 'vista' => 'todos', 'conKitDigital' => $conKitDigital], false) ?>

<details class="plegable">
    <summary>Cómo se hizo este análisis</summary>
    <dl class="datos">
        <dt>Alcance</dt>
        <dd><?= e($alcance?->etiqueta() ?? $analisis['alcance']) ?><?= $alcance === Alcance::UrlIndividual ? ': solo esta página, su robots.txt y sus redirecciones. No se concluye nada sobre otras páginas.' : '' ?></dd>
        <dt>Dirección introducida</dt><dd class="codigo"><?= e($analisis['url_introducida']) ?></dd>
        <?php if ($analisis['url_final'] !== null && $analisis['url_final'] !== $analisis['url_introducida']): ?>
            <dt>Dirección final</dt><dd class="codigo"><?= e($analisis['url_final']) ?></dd>
        <?php endif; ?>
        <?php if (($hosts = Etiquetas::lista($analisis['hosts_autorizados'])) !== null): ?>
            <dt>Sitios autorizados</dt><dd class="codigo"><?= e($hosts) ?></dd>
        <?php endif; ?>
        <dt>Objetivo declarado</dt><dd><?= $analisis['objetivo'] !== null && $analisis['objetivo'] !== '' ? e($analisis['objetivo']) : '<span class="vacio">Sin indicar</span>' ?></dd>
        <dt>Perfil de ejecución</dt><dd><?= e($analisis['perfil_version'] ?? '—') ?></dd>
        <dt>Versión del catálogo</dt><dd><?= e($analisis['version_catalogo'] ?? '—') ?></dd>
        <dt>Inicio y fin</dt><dd><?= e(Formato::fecha($analisis['iniciado_en'])) ?: '—' ?> → <?= e(Formato::fecha($analisis['terminado_en'])) ?: '—' ?></dd>
    </dl>
</details>

<?php if ($sinResultados): ?>
    <!-- Sin resultados no se puede concluir nada: ni que esté bien ni que esté mal. -->
    <section class="seccion" aria-labelledby="t-sin-resultados">
        <h2 id="t-sin-resultados">Sin resultados</h2>
        <p class="aviso" role="note">
            <?= $enMarcha ? 'Este análisis todavía no ha guardado resultados.' : 'Este análisis terminó sin guardar ningún resultado.' ?>
            No se puede decir nada de la web: ni que esté bien ni que tenga problemas.
            <?php if (!$enMarcha): ?><a href="/analisis/nuevo?proyecto=<?= e($analisis['proyecto_id']) ?>">Lanza un análisis nuevo</a>.<?php endif; ?>
        </p>
    </section>
    <?php if ($informe->recursosBloqueados !== []): ?>
        <section class="seccion" aria-labelledby="t-bloqueos">
            <h2 id="t-bloqueos">Lo que no se pudo descargar</h2>
            <ul>
                <?php foreach ($informe->recursosBloqueados as $rec): ?>
                    <li><span class="codigo"><?= e($rec['url']) ?></span> — <?= e(ResultadoDescarga::tryFrom((string) $rec['resultado'])?->etiqueta() ?? $rec['resultado']) ?><?= $rec['motivo'] !== null ? ' · ' . e($rec['motivo']) : '' ?></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
<?php else: ?>

<!-- 2. Resumen por bloque y cobertura -->
<section class="seccion" aria-labelledby="t-resumen">
    <h2 id="t-resumen">Resumen</h2>
    <p class="seccion__ayuda">Cuántos resultados hay en cada estado, después de las revisiones humanas. No es una nota: un solo problema crítico puede importar más que muchos correctos.</p>
    <div class="resumen">
        <?php foreach ($informe->resumen as $bloque => $datos): ?>
            <article class="bloque" aria-labelledby="b-<?= e($bloque) ?>">
                <h3 id="b-<?= e($bloque) ?>"><?= e(Bloque::from($bloque)->etiqueta()) ?></h3>
                <p class="bloque__que"><?= e($preguntas[$bloque] ?? '') ?></p>
                <ul class="recuento">
                    <?php foreach ($ordenEstados as $e): ?>
                        <?php $n = $datos['recuento'][$e->value] ?? 0; ?>
                        <li>
                            <?php if ($n > 0): ?>
                                <a href="<?= e($enlace(['bloque' => $bloque, 'estado' => $e->value])) ?>">
                                    <span class="estado estado--<?= e($e->value) ?>"><?= e($e->etiqueta()) ?></span>
                                    <span class="numero"><?= e($n) ?><span class="solo-lectores"> · ver estos resultados</span></span>
                                </a>
                            <?php else: ?>
                                <span class="estado estado--<?= e($e->value) ?>"><?= e($e->etiqueta()) ?></span>
                                <span class="numero cero">0</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="bloque__cobertura">
                    <?= e($plural($datos['total'], 'comprobación', 'comprobaciones')) ?>
                    · <?= $datos['no_implementadas'] === 0 ? 'todas implementadas' : e($plural($datos['no_implementadas'], 'aún no implementada', 'aún no implementadas')) ?>
                    <?php if ($datos['pendientes'] > 0): ?> · <?= e($plural($datos['pendientes'], 'revisión pendiente', 'revisiones pendientes')) ?><?php endif; ?>
                </p>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<?php if (($candidatos ?? []) !== []): ?>
    <!-- Cambios desde el análisis anterior de la misma dirección -->
    <?= Vista::render('results/_cambios', ['analisis' => $analisis, 'anterior' => $anterior ?? null, 'comparacion' => $comparacion ?? null, 'candidatos' => $candidatos], false) ?>
<?php endif; ?>

<!-- Filtros y búsqueda por texto (RF-23). Sin JavaScript: es un formulario GET normal. -->
<form class="filtros" id="resultados" role="search" action="/analisis/<?= e($id) ?>#resultados" method="get" aria-label="Filtrar resultados">
    <div class="filtros__texto">
        <label for="f-q">Buscar en los resultados</label>
        <input id="f-q" name="q" type="search" maxlength="<?= e(\ProconSEO\Results\FiltroResultados::MAX_TEXTO) ?>" placeholder="Texto, código (SEO-03) o elemento" value="<?= e($filtro->texto) ?>">
    </div>
    <div>
        <label for="f-bloque">Bloque</label>
        <select id="f-bloque" name="bloque">
            <option value="">Todos</option>
            <?php foreach (Bloque::cases() as $b): ?>
                <option value="<?= e($b->value) ?>"<?= $filtro->bloque === $b ? ' selected' : '' ?>><?= e($b->etiqueta()) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="f-estado">Estado</label>
        <select id="f-estado" name="estado">
            <option value="">Todos</option>
            <?php foreach ($ordenEstados as $e): ?>
                <option value="<?= e($e->value) ?>"<?= $filtro->estado === $e ? ' selected' : '' ?>><?= e($e->etiqueta()) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="f-criterio">Criterio</label>
        <select id="f-criterio" name="criterio">
            <option value="">Todos</option>
            <?php foreach ($informe->criterios as $c): ?>
                <option value="<?= e($c) ?>"<?= $filtro->criterio === $c ? ' selected' : '' ?>><?= e($c) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if (count($informe->urls) > 1): ?>
        <div>
            <label for="f-url">Página o recurso</label>
            <select id="f-url" name="url">
                <option value="">Todas</option>
                <?php foreach ($informe->urls as $u): ?>
                    <option value="<?= e($u) ?>"<?= $filtro->url === $u ? ' selected' : '' ?>><?= e($u) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>
    <label class="opcion"><input type="checkbox" name="pendiente" value="1"<?= $filtro->pendiente ? ' checked' : '' ?>><span>Solo con revisión pendiente</span></label>
    <div class="filtros__botones">
        <button class="boton" type="submit">Filtrar</button>
        <?php if ($filtrando): ?>
            <a class="boton boton--secundario" href="<?= e($enlace([])) ?>">Quitar filtros</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($filtrando): ?>
    <p class="aviso aviso--info" role="status">
        Se muestran <strong><?= e($informe->mostrados) ?> de <?= e($informe->total) ?></strong> resultados con estos filtros. El resumen de arriba sigue contando todos.
        <a href="<?= e($enlace([])) ?>">Quitar filtros</a>
    </p>
<?php endif; ?>

<!-- 3. Bloqueos de acceso, recuperación o indexación -->
<section class="seccion" aria-labelledby="t-bloqueos">
    <h2 id="t-bloqueos">1. Bloqueos</h2>
    <p class="seccion__ayuda">Lo que impide que la web se pueda descargar o aparecer en los buscadores. Va primero porque, mientras siga, lo demás importa menos.</p>

    <?php if ($informe->recursosBloqueados !== []): ?>
        <p>La herramienta no pudo descargar:</p>
        <ul>
            <?php foreach ($informe->recursosBloqueados as $rec): ?>
                <li>
                    <span class="codigo"><?= e($rec['url']) ?></span> —
                    <?= e(ResultadoDescarga::tryFrom((string) $rec['resultado'])?->etiqueta() ?? $rec['resultado']) ?>
                    <?= $rec['estado_http'] !== null ? '(HTTP ' . e($rec['estado_http']) . ')' : '' ?>
                    <?= $rec['motivo'] !== null ? '· ' . e($rec['motivo']) : '' ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="ayuda">Lo que dependía de estos recursos aparece como «No verificable»: no se dan por buenos ni por malos.</p>
    <?php endif; ?>

    <?php if ($informe->bloqueos !== []): ?>
        <ul class="resultados">
            <?php foreach ($informe->bloqueos as $entrada): ?>
                <li><?= Vista::render('results/_entrada', ['entrada' => $entrada], false) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php elseif ($informe->recursosBloqueados === []): ?>
        <p class="vacio"><?= $filtrando ? 'Ningún bloqueo coincide con los filtros.' : 'Entre los resultados obtenidos no hay ningún bloqueo de acceso ni de indexación.' ?></p>
    <?php endif; ?>
</section>

<!-- 4. Incidencias agrupadas por causa -->
<section class="seccion" aria-labelledby="t-incidencias">
    <h2 id="t-incidencias">2. Qué hay que corregir <span class="contador"><?= $informe->incidencias === [] ? 'nada más' : 'de lo más importante a lo menos' ?></span></h2>
    <p class="seccion__ayuda">Problemas, decisiones que hay que confirmar con el cliente y mejoras. Si una misma causa afecta a varios bloques, aparece una sola vez con todos sus efectos.</p>
    <?php if ($informe->incidencias === []): ?>
        <p class="vacio"><?= $filtrando ? 'Ninguna incidencia coincide con los filtros.' : ($numeroIncidencias === 0 ? 'No hay incidencias.' : 'No hay más incidencias aparte de los bloqueos.') ?></p>
    <?php else: ?>
        <ul class="resultados">
            <?php foreach ($informe->incidencias as $entrada): ?>
                <li><?= Vista::render('results/_entrada', ['entrada' => $entrada], false) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<!-- 5. Correctos y no aplicables -->
<section class="seccion" aria-labelledby="t-correctos">
    <h2 id="t-correctos">3. Lo que está bien o no aplica
        <span class="contador"><?= e($plural(count($informe->correctos), 'correcto', 'correctos')) ?> · <?= e($plural(count($informe->noAplicables), 'no aplicable', 'no aplicables')) ?></span>
    </h2>
    <?php foreach (['correctos' => $informe->correctos, 'no aplicables' => $informe->noAplicables] as $nombre => $lista): ?>
        <?php if ($lista !== []): ?>
            <details class="plegable">
                <summary>Ver <?= count($lista) === 1 ? 'el 1 ' . e(rtrim($nombre, 's')) : 'los ' . e(count($lista)) . ' ' . e($nombre) ?></summary>
                <ul class="resultados">
                    <?php foreach ($lista as $r): ?>
                        <li><?= Vista::render('results/_resultado', ['r' => $r], false) ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>
    <?php endforeach; ?>
</section>

<!-- 6. No verificables y revisiones pendientes -->
<section class="seccion" aria-labelledby="t-noverif">
    <h2 id="t-noverif">4. Lo que no se ha podido comprobar
        <span class="contador"><?= e($plural(count($informe->noVerificables), 'no verificable', 'no verificables')) ?><?= $informe->pendientesDeRevision() > 0 ? ' · ' . e($plural($informe->pendientesDeRevision(), 'pendiente de revisión', 'pendientes de revisión')) : '' ?></span>
    </h2>
    <p class="seccion__ayuda">«No verificable» no significa que esté mal ni que esté bien: la herramienta no tiene pruebas suficientes y lo dice.</p>
    <?php if ($informe->noVerificables === []): ?>
        <p class="vacio"><?= $filtrando ? 'Ningún no verificable coincide con los filtros.' : 'Ningún resultado de este análisis ha quedado como no verificable.' ?></p>
    <?php else: ?>
        <ul class="resultados">
            <?php foreach ($informe->noVerificables as $r): ?>
                <li><?= Vista::render('results/_resultado', ['r' => $r], false) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php endif; ?>

<!-- 7. Capacidades pendientes y límites -->
<section class="seccion" aria-labelledby="t-limites">
    <h2 id="t-limites"><?= $sinResultados ? 'Límites de este análisis' : '5. Límites de este análisis' ?></h2>
    <ul>
        <?php if ($alcance === Alcance::UrlIndividual): ?>
            <li><strong>Solo se ha analizado una página</strong> (alcance «URL individual»). No se ha revisado ninguna otra.</li>
        <?php endif; ?>
        <?php foreach ($informe->fasesIncompletas as $f): ?>
            <li>
                <strong>Fase «<?= e(Fase::tryFrom((string) $f['fase'])?->etiqueta() ?? $f['fase']) ?>»: <?= e(mb_strtolower(EstadoFase::tryFrom((string) $f['estado'])?->etiqueta() ?? (string) $f['estado'])) ?>.</strong>
                <?= $f['mensaje'] !== null ? e($f['mensaje']) : '' ?>
            </li>
        <?php endforeach; ?>
        <?php foreach ($informe->recursosNoEncontrados as $rec): ?>
            <li><strong>No existe</strong>: <span class="codigo"><?= e($rec['url']) ?></span> respondió HTTP <?= e($rec['estado_http']) ?>. No es un bloqueo; las comprobaciones que lo usan explican qué supone.</li>
        <?php endforeach; ?>
        <?php foreach ($informe->recursosTruncados as $rec): ?>
            <li><strong>Descarga incompleta</strong>: <span class="codigo"><?= e($rec['url']) ?></span> superó el tamaño máximo y solo se analizó una parte (<?= e(Etiquetas::bytes((int) $rec['bytes'])) ?>).</li>
        <?php endforeach; ?>
        <?php foreach ($eventos as $ev): ?>
            <li>
                <strong>Aviso de seguridad (<?= e($ev['regla']) ?>)</strong>: <?= e($ev['condicion']) ?>
                Decisión: <?= e(mb_strtolower(DecisionCierre::tryFrom((string) $ev['decision'])?->etiqueta() ?? (string) $ev['decision'])) ?>.
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($informe->noImplementadas !== []): ?>
        <details class="plegable">
            <summary><?= e($plural(count($informe->noImplementadas), 'comprobación que la herramienta todavía no hace', 'comprobaciones que la herramienta todavía no hace')) ?></summary>
            <ul class="resultados">
                <?php foreach ($informe->noImplementadas as $r): ?>
                    <li><?= Vista::render('results/_resultado', ['r' => $r], false) ?></li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>
    <p><a href="/catalogo">Ver en el catálogo qué comprueba la herramienta y qué está implementado</a>.</p>
</section>
