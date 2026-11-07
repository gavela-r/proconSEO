<?php
/**
 * @var list<array<string, mixed>> $proyectos
 * @var array<string, string> $alcances
 * @var array<string, mixed> $valores
 * @var string|null $error
 * @var bool $detenidos
 */
$v = static fn (string $clave): string => (string) ($valores[$clave] ?? '');

// Qué abarca cada alcance (límites del perfil de ejecución vigente).
$explicacion = [
    'url_individual' => ['Solo esta página. Lo más rápido.', '1 página · ≈ 1 min'],
    'url_contexto' => ['Esta página y las pocas que necesitan algunas comprobaciones.', '≤ 20 URLs · ≈ 2 min'],
    'sitio_completo' => ['Recorre la web por sus enlaces y su sitemap.', '≤ 200 páginas · varios minutos'],
];
?>
<div class="titular">
    <div>
        <h1>Nuevo análisis</h1>
        <p class="entradilla">Indica la web y hasta dónde mirar. Antes de hacer ninguna petición, se comprueba que la dirección es pública y segura.</p>
    </div>
</div>

<?php if ($detenidos): ?>
    <p class="aviso aviso--error" role="status">Los análisis están detenidos temporalmente por seguridad. Ahora no se pueden lanzar nuevos.</p>
<?php endif; ?>

<?php if ($error !== null): ?>
    <p class="aviso aviso--error" role="alert" id="error-formulario"><?= e($error) ?></p>
<?php endif; ?>

<?php if ($proyectos === []): ?>
    <p class="aviso">Todavía no hay ningún proyecto. Crea uno primero en <a href="/proyectos">Proyectos</a>.</p>
<?php else: ?>
<div class="nuevo">
    <form method="post" action="/analisis" novalidate<?= $error !== null ? ' aria-describedby="error-formulario"' : '' ?>>
        <?= csrf_campo() ?>

        <div class="paso">
            <span class="paso__numero" aria-hidden="true">01</span>
            <div>
                <label for="url">Dirección de la web</label>
                <input id="url" name="url" type="url" inputmode="url" autocomplete="url" required spellcheck="false"
                       placeholder="https://www.ejemplo.es/" value="<?= e($v('url')) ?>" aria-describedby="ayuda-url">
                <p class="ayuda" id="ayuda-url">Solo direcciones http:// o https:// públicas. Las de la red interna se rechazan.</p>

                <label for="proyecto">Proyecto</label>
                <select id="proyecto" name="proyecto" required>
                    <?php foreach ($proyectos as $proyecto): ?>
                        <option value="<?= e($proyecto['id']) ?>" <?= (int) $v('proyecto') === (int) $proyecto['id'] ? 'selected' : '' ?>>
                            <?= e($proyecto['nombre']) ?><?= $proyecto['es_dato_prueba'] ? ' (demostración)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="paso">
            <span class="paso__numero" aria-hidden="true">02</span>
            <fieldset>
                <legend>Alcance</legend>
                <div class="alcances">
                    <?php foreach ($alcances as $valor => $texto): ?>
                        <label class="alcance" for="alcance-<?= e($valor) ?>">
                            <input type="radio" id="alcance-<?= e($valor) ?>" name="alcance" value="<?= e($valor) ?>" <?= $v('alcance') === $valor ? 'checked' : '' ?>>
                            <span class="alcance__nombre"><span class="alcance__marca" aria-hidden="true"></span><?= e($texto) ?></span>
                            <?php if (isset($explicacion[$valor])): ?>
                                <span class="alcance__texto"><?= e($explicacion[$valor][0]) ?></span>
                                <span class="alcance__dato"><?= e($explicacion[$valor][1]) ?></span>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
        </div>

        <div class="paso">
            <span class="paso__numero" aria-hidden="true">03</span>
            <div>
                <label for="hosts_extra">Otros dominios autorizados <span class="opcional">(opcional)</span></label>
                <input id="hosts_extra" name="hosts_extra" type="text" class="mono" spellcheck="false" placeholder="www.ejemplo.es" value="<?= e($v('hosts_extra')) ?>" aria-describedby="ayuda-hosts">
                <p class="ayuda" id="ayuda-hosts">Separados por comas. Autorizar «ejemplo.es» no incluye «www.ejemplo.es»: si la web redirige a otro dominio, añádelo aquí.</p>

                <label for="objetivo">Objetivo de visibilidad <span class="opcional">(opcional)</span></label>
                <textarea id="objetivo" name="objetivo" rows="3" placeholder="Por ejemplo: aparecer en búsquedas locales de León" aria-describedby="ayuda-objetivo"><?= e($v('objetivo')) ?></textarea>
                <p class="ayuda" id="ayuda-objetivo">Sirve, por ejemplo, para distinguir un <code>noindex</code> intencionado de uno olvidado.</p>
            </div>
        </div>

        <div class="envio">
            <button type="submit" class="boton">Comprobar y lanzar</button>
            <p class="meta">Se pone en cola y empieza en cuanto haya un hueco. Se puede cancelar en cualquier momento.</p>
        </div>
    </form>

    <aside aria-label="Antes de empezar">
        <section class="ficha" aria-labelledby="f-seguridad">
            <h2 id="f-seguridad">Antes de empezar</h2>
            <ul>
                <li>Se valida la dirección y la IP real antes de conectar.</li>
                <li>Solo se piden páginas de los dominios autorizados.</li>
                <li>Se respeta robots.txt y un ritmo de pocas peticiones por segundo.</li>
                <li>Los límites del perfil no se amplían en silencio: si se alcanza uno, el informe lo dice.</li>
            </ul>
        </section>
        <section class="ficha" aria-labelledby="f-que">
            <h2 id="f-que">Qué se revisa</h2>
            <dl>
                <dt>Búsqueda con IA</dt><dd>Si los buscadores con IA pueden encontrarla y citarla.</dd>
                <dt>SEO</dt><dd>Si Google y Bing pueden rastrearla e indexarla.</dd>
                <dt>Accesibilidad</dt><dd>WCAG 2.2 A y AA.</dd>
            </dl>
            <p class="meta">El detalle de cada comprobación está en el <a href="/catalogo">catálogo</a>.</p>
        </section>
    </aside>
</div>
<?php endif; ?>
