<?php
/**
 * @var list<array<string, mixed>> $proyectos
 * @var array<string, string> $alcances
 * @var array<string, mixed> $valores
 * @var string|null $error
 * @var bool $detenidos
 */
$v = static fn (string $clave): string => (string) ($valores[$clave] ?? '');
?>
<h1>Nuevo análisis</h1>
<p class="entradilla">Indica la web que quieres analizar. Antes de empezar se comprueba que la dirección es segura.</p>

<?php if ($detenidos): ?>
    <p class="aviso aviso--error" role="status">Los análisis están detenidos temporalmente por seguridad. Ahora no se pueden lanzar nuevos.</p>
<?php endif; ?>

<?php if ($error !== null): ?>
    <p class="aviso aviso--error" role="alert" id="error-formulario"><?= e($error) ?></p>
<?php endif; ?>

<?php if ($proyectos === []): ?>
    <p class="aviso">Todavía no hay ningún proyecto. Crea uno primero en <a href="/proyectos">Proyectos</a>.</p>
<?php else: ?>
<form method="post" action="/analisis" class="formulario" novalidate<?= $error !== null ? ' aria-describedby="error-formulario"' : '' ?>>
    <?= csrf_campo() ?>

    <label for="proyecto">Proyecto</label>
    <select id="proyecto" name="proyecto" required>
        <?php foreach ($proyectos as $proyecto): ?>
            <option value="<?= e($proyecto['id']) ?>" <?= (int) $v('proyecto') === (int) $proyecto['id'] ? 'selected' : '' ?>>
                <?= e($proyecto['nombre']) ?><?= $proyecto['es_dato_prueba'] ? ' (demostración)' : '' ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label for="url">Dirección de la web</label>
    <input id="url" name="url" type="url" inputmode="url" autocomplete="url" required
           placeholder="https://www.ejemplo.es/" value="<?= e($v('url')) ?>" aria-describedby="ayuda-url">
    <p class="ayuda" id="ayuda-url">Solo direcciones http:// o https:// públicas. No se permiten direcciones de la red interna.</p>

    <fieldset>
        <legend>Alcance</legend>
        <?php foreach ($alcances as $valor => $texto): ?>
            <div class="opcion">
                <input type="radio" id="alcance-<?= e($valor) ?>" name="alcance" value="<?= e($valor) ?>" <?= $v('alcance') === $valor ? 'checked' : '' ?>>
                <label for="alcance-<?= e($valor) ?>"><?= e($texto) ?></label>
            </div>
        <?php endforeach; ?>
        <p class="ayuda">
            <strong>URL individual</strong>: solo esa página.
            <strong>URL con contexto</strong>: esa página y las pocas relacionadas que necesite cada comprobación (como mucho 20).
            <strong>Sitio completo</strong>: recorre la web por sus enlaces y su sitemap, hasta 200 páginas; puede tardar varios minutos.
        </p>
    </fieldset>

    <label for="hosts_extra">Otros dominios autorizados <span class="opcional">(opcional)</span></label>
    <input id="hosts_extra" name="hosts_extra" type="text" placeholder="www.ejemplo.es" value="<?= e($v('hosts_extra')) ?>" aria-describedby="ayuda-hosts">
    <p class="ayuda" id="ayuda-hosts">Separados por comas. Autorizar «ejemplo.es» no incluye «www.ejemplo.es»: si la web redirige a otro dominio, añádelo aquí.</p>

    <label for="objetivo">Objetivo de visibilidad <span class="opcional">(opcional)</span></label>
    <textarea id="objetivo" name="objetivo" rows="3" placeholder="Por ejemplo: aparecer en búsquedas locales de León"><?= e($v('objetivo')) ?></textarea>

    <p><button type="submit" class="boton">Empezar análisis</button></p>
</form>
<?php endif; ?>
