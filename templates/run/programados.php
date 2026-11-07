<?php
/**
 * Análisis programados de un proyecto: la web se vuelve a analizar sola cada cierto tiempo
 * y, si aparece algo grave, se crea un aviso.
 *
 * @var array<string, mixed> $proyecto
 * @var list<array<string, mixed>> $programaciones
 * @var array<string, string> $alcances
 * @var array<int, string> $periodos
 * @var array<string, mixed> $valores
 * @var string|null $error
 */

use ProconSEO\Projects\Formato;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\EstadoAnalisis;

$valores ??= [];
$v = static fn (string $clave): string => (string) ($valores[$clave] ?? '');
$idProyecto = (int) $proyecto['id'];
?>
<p class="migas"><a href="/proyectos/<?= e($idProyecto) ?>"><?= e($proyecto['nombre']) ?></a></p>
<h1>Análisis programados</h1>
<p class="entradilla">
    La web se vuelve a analizar sola cada cierto tiempo. Si aparece algo grave que antes no estaba
    (por ejemplo, que Google ya no pueda indexarla) o la web no responde, se crea un <a href="/avisos">aviso</a>.
</p>

<?php if ($programaciones === []): ?>
    <p class="vacio">Este proyecto todavía no tiene análisis programados.</p>
<?php else: ?>
    <ul class="programaciones">
        <?php foreach ($programaciones as $pr): ?>
            <?php
            $activa = (bool) $pr['activa'];
            $ultimo = EstadoAnalisis::tryFrom((string) ($pr['ultimo_estado'] ?? ''));
            ?>
            <li class="programacion<?= $activa ? '' : ' programacion--pausada' ?>">
                <p class="programacion__que">
                    <span class="codigo"><?= e($pr['url']) ?></span>
                    · <?= e(Alcance::tryFrom((string) $pr['alcance'])?->etiqueta() ?? $pr['alcance']) ?>
                    · <strong><?= e($periodos[(int) $pr['cada_dias']] ?? 'Cada ' . $pr['cada_dias'] . ' días') ?></strong>
                    <?= $activa ? '' : ' · <strong>En pausa</strong>' ?>
                </p>
                <p class="ayuda">
                    <?php if ($activa): ?>Próximo análisis: <?= e(Formato::fecha($pr['siguiente_en'])) ?>.<?php endif; ?>
                    <?php if ($pr['ultimo_analisis_id'] !== null): ?>
                        Último: <a href="/analisis/<?= e($pr['ultimo_analisis_id']) ?>"><?= e(Formato::fecha($pr['ultimo_creado_en'])) ?></a><?= $ultimo !== null ? ' (' . e(mb_strtolower($ultimo->etiqueta())) . ')' : '' ?>.
                    <?php endif; ?>
                </p>
                <?php if ($pr['ultimo_error'] !== null): ?>
                    <p class="aviso" role="note">La última vez no se pudo lanzar: <?= e($pr['ultimo_error']) ?></p>
                <?php endif; ?>
                <div class="acciones">
                    <form method="post" action="/programados/<?= e($pr['id']) ?>/<?= $activa ? 'pausar' : 'reanudar' ?>">
                        <?= csrf_campo() ?>
                        <button type="submit" class="boton boton--secundario boton--pequeno"><?= $activa ? 'Pausar' : 'Reanudar' ?></button>
                    </form>
                    <form method="post" action="/programados/<?= e($pr['id']) ?>/borrar">
                        <?= csrf_campo() ?>
                        <button type="submit" class="boton boton--secundario boton--pequeno" aria-label="Quitar la programación de <?= e($pr['url']) ?>">Quitar</button>
                    </form>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
    <p class="ayuda">Quitar una programación no borra los análisis que ya se hicieron.</p>
<?php endif; ?>

<h2>Programar un análisis</h2>
<?php if ($error !== null): ?>
    <p class="aviso aviso--error" role="alert" id="error-formulario"><?= e($error) ?></p>
<?php endif; ?>
<form method="post" action="/proyectos/<?= e($idProyecto) ?>/programados" class="formulario" novalidate<?= $error !== null ? ' aria-describedby="error-formulario"' : '' ?>>
    <?= csrf_campo() ?>

    <label for="url">Dirección de la web</label>
    <input id="url" name="url" type="url" inputmode="url" required placeholder="https://www.ejemplo.es/" value="<?= e($v('url')) ?>">

    <label for="cada_dias">Cada cuánto</label>
    <select id="cada_dias" name="cada_dias">
        <?php foreach ($periodos as $dias => $texto): ?>
            <option value="<?= e($dias) ?>"<?= (int) $v('cada_dias') === $dias ? ' selected' : '' ?>><?= e($texto) ?></option>
        <?php endforeach; ?>
    </select>

    <fieldset class="programar__alcance">
        <legend>Alcance</legend>
        <div class="alcances">
            <?php foreach ($alcances as $valor => $texto): ?>
                <label class="alcance" for="alcance-<?= e($valor) ?>">
                    <input type="radio" id="alcance-<?= e($valor) ?>" name="alcance" value="<?= e($valor) ?>"<?= $v('alcance') === $valor ? ' checked' : '' ?>>
                    <span class="alcance__nombre"><span class="alcance__marca" aria-hidden="true"></span><?= e($texto) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    </fieldset>

    <label for="hosts_extra">Otros dominios autorizados <span class="opcional">(opcional)</span></label>
    <input id="hosts_extra" name="hosts_extra" type="text" placeholder="www.ejemplo.es" value="<?= e($v('hosts_extra')) ?>" aria-describedby="ayuda-hosts">
    <p class="ayuda" id="ayuda-hosts">Separados por comas, como al lanzar un análisis a mano.</p>

    <p class="ayuda">El primer análisis se hace en cuanto el worker esté en marcha; los siguientes, con el periodo elegido. Cuentan para los mismos límites por hora que los lanzados a mano.</p>
    <p><button type="submit" class="boton">Programar</button></p>
</form>
