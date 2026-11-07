<?php
/**
 * Vista «por páginas» de un análisis: zonas de la web, tabla de páginas e inventario de lo
 * descargado. Recuentos por estado, sin notas ni porcentajes. Todo se imprime escapado (SEG-09).
 *
 * @var array<string, mixed> $analisis
 * @var \ProconSEO\Results\VistaPaginas $paginas
 * @var \ProconSEO\Results\PanelesSitio|null $paneles
 * @var string $orden
 * @var bool $conKitDigital
 */

use ProconSEO\Results\Etiquetas;
use ProconSEO\Results\VistaPaginas;
use ProconSEO\Shared\Enum\Gravedad;
use ProconSEO\Shared\Enum\ResultadoDescarga;
use ProconSEO\Shared\Vista;

$id = (int) $analisis['id'];
$enlace = static fn (array $filtros): string => '/analisis/' . $id . '?' . http_build_query($filtros) . '#resultados';
$gravedad = static fn (?string $g): string => $g === null ? '' : '<span class="gravedad gravedad--' . e($g) . '">' . e(Gravedad::from($g)->etiqueta()) . '</span>';
$num = static fn (int $n, string $clase = ''): string => $n === 0 ? '<span class="cero">0</span>' : '<strong class="' . e($clase) . '">' . e($n) . '</strong>';
$inv = $paginas->inventario;
// Dentro de la misma web basta la ruta; la dirección completa queda en el title del enlace.
$host = parse_url((string) ($analisis['url_final'] ?? $analisis['url_introducida']), PHP_URL_HOST);
$corta = static function (string $url) use ($host): string {
    $partes = parse_url($url);

    return ($partes['host'] ?? null) === $host ? ($partes['path'] ?? '/') . (isset($partes['query']) ? '?' . $partes['query'] : '') : $url;
};
$conIncidencias = count(array_filter($paginas->paginas, static fn (array $p): bool => $p['problemas'] + $p['decisiones'] + $p['mejorables'] > 0));
?>
<?= Vista::render('results/_cabecera_analisis', ['analisis' => $analisis, 'vista' => 'paginas', 'conKitDigital' => $conKitDigital], false) ?>

<section class="seccion" aria-labelledby="t-cifras">
    <h2 id="t-cifras">De un vistazo</h2>
    <ul class="cifras">
        <li><span class="cifras__numero"><?= e(count($paginas->paginas)) ?></span> páginas o recursos con resultados</li>
        <li><span class="cifras__numero"><?= e($conIncidencias) ?></span> con algo que corregir o decidir</li>
        <li><span class="cifras__numero"><?= e(count($paginas->zonas)) ?></span> zonas de la web</li>
        <li><span class="cifras__numero"><?= e($inv['total']) ?></span> descargas (<?= e(Etiquetas::bytes($inv['bytes'])) ?>)</li>
    </ul>
</section>

<?php if (($paneles ?? null) !== null && $paneles->agentes !== []): ?>
    <section class="seccion" aria-labelledby="t-agentes">
        <h2 id="t-agentes">¿Quién puede leer la web?</h2>
        <p class="seccion__ayuda">Lo que dice el robots.txt a cada buscador y a cada IA. Bloquear el entrenamiento es una decisión del cliente; bloquear a un buscador con IA hace que la web no salga como fuente en sus respuestas.</p>
        <ul class="agentes">
            <?php foreach ($paneles->agentes as $ag): ?>
                <li class="agentes__item agentes__item--<?= $ag['permitido'] === null ? 'otro' : ($ag['permitido'] ? 'si' : 'no') ?>">
                    <span class="agentes__nombre codigo"><?= e($ag['agente']) ?></span>
                    <span class="agentes__tipo"><?= e($ag['tipo']) ?></span>
                    <span class="agentes__estado"><?= $ag['permitido'] === null ? e($ag['texto']) : ($ag['permitido'] ? 'Puede leerla' : 'Bloqueado') ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<?php if (($paneles ?? null) !== null && count($paneles->textos) > 1): ?>
    <section class="seccion" aria-labelledby="t-textos">
        <h2 id="t-textos">Títulos y descripciones
            <span class="contador"><?= e($paneles->titulosRepetidos) ?> títulos y <?= e($paneles->descripcionesRepetidas) ?> descripciones repetidos</span>
        </h2>
        <p class="seccion__ayuda">Lo que Google y las IA muestran de cada página. Si varias páginas tienen el mismo título o la misma descripción, compiten entre ellas y es difícil saber cuál enseñar.</p>
        <details class="plegable"<?= $paneles->titulosRepetidos + $paneles->descripcionesRepetidas > 0 ? ' open' : '' ?>>
            <summary>Ver los de las <?= e(count($paneles->textos)) ?> páginas</summary>
            <div class="tabla-desplazable">
                <table class="tabla-paginas tabla-textos">
                    <thead><tr><th scope="col">Página</th><th scope="col">Título</th><th scope="col">Descripción</th></tr></thead>
                    <tbody>
                        <?php foreach ($paneles->textos as $t): ?>
                            <tr>
                                <th scope="row"><span class="codigo" title="<?= e($t['url']) ?>"><?= e($corta($t['url'])) ?></span></th>
                                <td>
                                    <?= $t['titulo'] === null ? '<span class="cero">Sin dato</span>' : e($t['titulo']) ?>
                                    <?php if ($t['titulo'] !== null): ?><span class="tabla-paginas__nota"><?= e(mb_strlen($t['titulo'])) ?> caracteres</span><?php endif; ?>
                                    <?php if ($t['titulo_repetido'] > 0): ?><span class="chip chip--alerta">Igual en <?= e($t['titulo_repetido']) ?> páginas</span><?php endif; ?>
                                </td>
                                <td>
                                    <?= $t['descripcion'] === null ? '<span class="cero">Sin dato</span>' : e($t['descripcion']) ?>
                                    <?php if ($t['descripcion'] !== null): ?><span class="tabla-paginas__nota"><?= e(mb_strlen($t['descripcion'])) ?> caracteres</span><?php endif; ?>
                                    <?php if ($t['descripcion_repetida'] > 0): ?><span class="chip chip--alerta">Igual en <?= e($t['descripcion_repetida']) ?> páginas</span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
    </section>
<?php endif; ?>

<?php if (count($paginas->zonas) > 1): ?>
    <section class="seccion" aria-labelledby="t-zonas">
        <h2 id="t-zonas">Por zonas de la web</h2>
        <p class="seccion__ayuda">Agrupadas por la primera parte de la dirección (<span class="codigo">/blog/</span>, <span class="codigo">/productos/</span>…). Las páginas de una misma zona suelen compartir plantilla: un fallo en casi todas ellas se arregla una vez.</p>
        <div class="tabla-desplazable">
            <table class="tabla-paginas">
                <thead><tr><th scope="col">Zona</th><th scope="col" class="num">Páginas</th><th scope="col" class="num">Problemas</th><th scope="col" class="num">Decisiones</th><th scope="col" class="num">Mejorables</th><th scope="col">Lo más grave</th></tr></thead>
                <tbody>
                    <?php foreach ($paginas->zonas as $z): ?>
                        <tr>
                            <th scope="row"><a class="codigo" href="<?= e($enlace(['url' => $z['zona'] === '/ (páginas sueltas)' || $z['zona'] === '/' ? '' : $z['zona']])) ?>"><?= e($z['zona']) ?></a></th>
                            <td class="num"><?= e($z['paginas']) ?></td>
                            <td class="num"><?= $num($z['problemas'], 'texto-problema') ?></td>
                            <td class="num"><?= $num($z['decisiones']) ?></td>
                            <td class="num"><?= $num($z['mejorables']) ?></td>
                            <td><?= $gravedad($z['gravedad']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<section class="seccion" aria-labelledby="t-paginas">
    <h2 id="t-paginas">Páginas <span class="contador"><?= e(count($paginas->paginas)) ?></span></h2>
    <form class="ordenar" method="get" action="/analisis/<?= e($id) ?>/paginas#t-paginas">
        <label for="orden">Ordenar</label>
        <select id="orden" name="orden">
            <?php foreach (VistaPaginas::ORDENES as $valor => $texto): ?>
                <option value="<?= e($valor) ?>"<?= $orden === $valor ? ' selected' : '' ?>><?= e($texto) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="boton boton--secundario boton--pequeno" type="submit">Ordenar</button>
    </form>
    <p class="seccion__ayuda">Cuántos resultados de cada tipo tiene cada página. Pulsa una para ver solo sus resultados.</p>
    <div class="tabla-desplazable">
        <table class="tabla-paginas">
            <thead>
                <tr>
                    <th scope="col">Página</th>
                    <th scope="col" class="num">HTTP</th>
                    <th scope="col" class="num">Problemas</th>
                    <th scope="col" class="num">Decisiones</th>
                    <th scope="col" class="num">Mejorables</th>
                    <th scope="col">Lo más grave</th>
                    <th scope="col" class="num"><abbr title="Búsqueda con IA">IA</abbr></th>
                    <th scope="col" class="num">SEO</th>
                    <th scope="col" class="num"><abbr title="Accesibilidad">Acc.</abbr></th>
                    <th scope="col" class="num">Peso</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($paginas->paginas as $p): ?>
                    <tr>
                        <th scope="row">
                            <a class="codigo" href="<?= e($enlace(['url' => $p['url']])) ?>" title="<?= e($p['url']) ?>"><?= e($corta($p['url'])) ?></a>
                            <span class="tabla-paginas__nota"><?= e($p['tipo'] ?? 'recurso') ?><?= $p['redirige'] ? ' · redirige' : '' ?> · <?= e($p['comprobaciones']) ?> comprobaciones<?= $p['no_verificables'] > 0 ? ' · ' . e($p['no_verificables']) . ' sin poder comprobar' : '' ?></span>
                        </th>
                        <td class="num"><?= $p['estado_http'] === null ? '<span class="cero">—</span>' : '<span class="' . ($p['estado_http'] >= 400 ? 'texto-problema' : '') . '">' . e($p['estado_http']) . '</span>' ?></td>
                        <td class="num"><?= $num($p['problemas'], 'texto-problema') ?></td>
                        <td class="num"><?= $num($p['decisiones']) ?></td>
                        <td class="num"><?= $num($p['mejorables']) ?></td>
                        <td><?= $gravedad($p['gravedad']) ?></td>
                        <td class="num"><?= $num($p['por_bloque']['ia']) ?></td>
                        <td class="num"><?= $num($p['por_bloque']['seo']) ?></td>
                        <td class="num"><?= $num($p['por_bloque']['acc']) ?></td>
                        <td class="num"><?= $p['bytes'] === null ? '<span class="cero">—</span>' : e(Etiquetas::bytes($p['bytes'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="ayuda">IA, SEO y Acc.: cuántos problemas, decisiones y mejorables tiene la página en cada bloque.</p>
</section>

<section class="seccion" aria-labelledby="t-inventario">
    <h2 id="t-inventario">Inventario de lo descargado</h2>
    <p class="seccion__ayuda">Todo lo que la herramienta pidió a la web: páginas, robots.txt, sitemaps y los enlaces que comprobó.</p>
    <dl class="datos">
        <dt>Descargas</dt><dd><?= e($inv['total']) ?> (<?= e(Etiquetas::bytes($inv['bytes'])) ?>)</dd>
        <dt>Respuestas</dt>
        <dd>
            <?php if ($inv['por_codigo'] === []): ?>—<?php endif; ?>
            <?php foreach ($inv['por_codigo'] as $familia => $n): ?>
                <span class="chip<?= in_array($familia, ['4xx', '5xx'], true) ? ' chip--alerta' : '' ?>"><?= e($familia) ?>: <?= e($n) ?></span>
            <?php endforeach; ?>
        </dd>
        <dt>Con redirección</dt><dd><?= e($inv['redirecciones']) ?></dd>
        <dt>Resultado</dt>
        <dd>
            <?php foreach ($inv['por_resultado'] as $resultado => $n): ?>
                <span class="chip"><?= e(ResultadoDescarga::tryFrom((string) $resultado)?->etiqueta() ?? $resultado) ?>: <?= e($n) ?></span>
            <?php endforeach; ?>
        </dd>
    </dl>
    <?php if ($inv['rotos'] !== []): ?>
        <h3 class="subtitulo">Lo que falló <span class="contador"><?= e(count($inv['rotos'])) ?></span></h3>
        <p class="seccion__ayuda">Enlaces rotos, errores del servidor o descargas que no terminaron. Un robots.txt o un sitemap que no existe también sale aquí.</p>
        <div class="tabla-desplazable">
            <table class="tabla-paginas">
                <thead><tr><th scope="col">Dirección</th><th scope="col" class="num">HTTP</th><th scope="col">Motivo</th></tr></thead>
                <tbody>
                    <?php foreach ($inv['rotos'] as $roto): ?>
                        <tr>
                            <th scope="row"><span class="codigo"><?= e($roto['url']) ?></span></th>
                            <td class="num"><?= $roto['estado_http'] === null ? '—' : e($roto['estado_http']) ?></td>
                            <td><?= e($roto['motivo'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
