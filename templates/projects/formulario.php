<?php
/**
 * Crear o editar un proyecto (RF-01, RF-02, RF-06).
 *
 * @var string $titulo
 * @var string $accion
 * @var int|null $id
 * @var array<string, string> $valores
 * @var array<string, string> $errores
 * @var bool $esPrueba
 * @var array<string, string> $tipos
 * @var array<string, string> $sectores
 * @var array<string, string> $estados
 */
$v = static fn (string $campo): string => $valores[$campo] ?? '';

/** Atributos de accesibilidad de un campo con ayuda y, si lo hay, mensaje de error. */
$describir = static function (string $campo, ?string $ayuda = null) use ($errores): string {
    $ids = array_filter([$ayuda, isset($errores[$campo]) ? "error-{$campo}" : null]);
    $html = $ids === [] ? '' : ' aria-describedby="' . e(implode(' ', $ids)) . '"';

    return $html . (isset($errores[$campo]) ? ' aria-invalid="true"' : '');
};
$error = static fn (string $campo): string => isset($errores[$campo])
    ? '<span class="error-campo" id="error-' . e($campo) . '">' . e($errores[$campo]) . '</span>'
    : '';

$descripcionTipos = [
    'web_kit_digital' => 'Web hecha por Proconsi dentro del Kit Digital. Muestra la vista «Compromisos Kit Digital».',
    'tienda_kit_digital' => 'Tienda online (WooCommerce o PrestaShop) del Kit Digital. Muestra la vista «Compromisos Kit Digital».',
    'web_cliente' => 'Diagnóstico de la web de un cliente o posible cliente. Puedes indicar su sector.',
    'web_proconsi' => 'proconsi.com o la web de uno de sus productos.',
    'otra' => 'Cualquier otro caso, como un proyecto interno o de prueba.',
];
?>
<nav class="migas" aria-label="Estás en">
    <ol>
        <li><a href="/proyectos">Proyectos</a></li>
        <?php if ($id !== null): ?>
            <li><a href="/proyectos/<?= e($id) ?>"><?= e($v('nombre')) ?></a></li>
        <?php endif; ?>
        <li aria-current="page"><?= e($titulo) ?></li>
    </ol>
</nav>

<?php if ($esPrueba): ?>
    <?= \ProconSEO\Shared\Vista::render('layout/aviso_prueba', [], false) ?>
<?php endif; ?>

<h1><?= e($titulo) ?></h1>

<?php if ($errores !== []): ?>
    <div class="aviso aviso--error" role="alert" id="resumen-errores">
        <p><strong>No se ha guardado.</strong> Revisa <?= count($errores) === 1 ? 'el campo marcado' : 'los ' . e(count($errores)) . ' campos marcados' ?>:</p>
        <ul>
            <?php foreach ($errores as $campo => $mensaje): ?>
                <li><a href="#<?= e($campo) ?>"><?= e($mensaje) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<p>Los campos con <span class="obligatorio" aria-hidden="true">*</span> son obligatorios.</p>

<form class="formulario" action="<?= e($accion) ?>" method="post" novalidate>
    <?= csrf_campo() ?>
    <?php if ($id !== null): ?>
        <input type="hidden" name="_metodo" value="PUT">
    <?php endif; ?>

    <label for="nombre">Nombre del proyecto <span class="obligatorio" aria-hidden="true">*</span><span class="solo-lectores">(obligatorio)</span></label>
    <input id="nombre" name="nombre" type="text" required maxlength="200" value="<?= e($v('nombre')) ?>"<?= $describir('nombre', 'ayuda-nombre') ?>>
    <span class="ayuda" id="ayuda-nombre">Por ejemplo: «Web de Gasolineras Pérez».</span>
    <?= $error('nombre') ?>

    <label for="cliente">Cliente</label>
    <input id="cliente" name="cliente" type="text" maxlength="200" value="<?= e($v('cliente')) ?>"<?= $describir('cliente', 'ayuda-cliente') ?>>
    <span class="ayuda" id="ayuda-cliente">Déjalo vacío si es un proyecto interno o de prueba.</span>
    <?= $error('cliente') ?>

    <label for="url_principal">Dirección de la web</label>
    <input id="url_principal" name="url_principal" type="url" inputmode="url" maxlength="2048" placeholder="https://www.ejemplo.es/" value="<?= e($v('url_principal')) ?>"<?= $describir('url_principal', 'ayuda-url') ?>>
    <span class="ayuda" id="ayuda-url">Se propone como dirección al lanzar un análisis.</span>
    <?= $error('url_principal') ?>

    <fieldset<?= isset($errores['tipo']) ? ' aria-describedby="error-tipo"' : '' ?>>
        <legend>Tipo de proyecto</legend>
        <span class="ayuda">El tipo activa comprobaciones específicas.</span>
        <?php foreach ($tipos as $valor => $texto): ?>
            <label class="opcion">
                <input type="radio" name="tipo" value="<?= e($valor) ?>"<?= $v('tipo') === $valor ? ' checked' : '' ?><?= $valor === array_key_first($tipos) ? ' id="tipo"' : '' ?>>
                <span><strong><?= e($texto) ?></strong><?= e($descripcionTipos[$valor] ?? '') ?></span>
            </label>
        <?php endforeach; ?>
        <?= $error('tipo') ?>

        <label for="sector">Sector <span class="ayuda">Solo para «Web de cliente». Activa las comprobaciones de ese sector.</span></label>
        <select id="sector" name="sector"<?= $describir('sector') ?>>
            <option value="">Sin indicar</option>
            <?php foreach ($sectores as $valor => $texto): ?>
                <option value="<?= e($valor) ?>"<?= $v('sector') === $valor ? ' selected' : '' ?>><?= e($texto) ?></option>
            <?php endforeach; ?>
        </select>
        <?= $error('sector') ?>
    </fieldset>

    <label for="estado">Estado</label>
    <select id="estado" name="estado"<?= $describir('estado') ?>>
        <?php foreach ($estados as $valor => $texto): ?>
            <option value="<?= e($valor) ?>"<?= $v('estado') === $valor ? ' selected' : '' ?>><?= e($texto) ?></option>
        <?php endforeach; ?>
    </select>
    <?= $error('estado') ?>

    <label for="descripcion">Notas</label>
    <textarea id="descripcion" name="descripcion" rows="4" maxlength="5000"<?= $describir('descripcion', 'ayuda-notas') ?>><?= e($v('descripcion')) ?></textarea>
    <span class="ayuda" id="ayuda-notas">Contexto útil: qué se contrató, a quién avisar, objetivos del cliente…</span>
    <?= $error('descripcion') ?>

    <div class="formulario__botones">
        <button class="boton" type="submit">Guardar proyecto</button>
        <a class="boton boton--secundario" href="<?= $id !== null ? '/proyectos/' . e($id) : '/proyectos' ?>">Cancelar</a>
    </div>
</form>
