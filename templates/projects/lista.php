<?php
/**
 * Lista y búsqueda de proyectos (RF-01).
 *
 * @var list<array<string, mixed>> $proyectos
 * @var int $limite
 * @var array{q: string, tipo: string, estado: string} $filtros
 * @var array<string, string> $tipos
 * @var array<string, string> $estados
 */

use ProconSEO\Projects\Formato;
use ProconSEO\Shared\Enum\EstadoAnalisis;
use ProconSEO\Shared\Enum\EstadoProyecto;
use ProconSEO\Shared\Enum\Sector;
use ProconSEO\Shared\Enum\TipoProyecto;

$hayFiltros = $filtros['q'] !== '' || $filtros['tipo'] !== '' || $filtros['estado'] !== '';
$total = count($proyectos);
?>
<div class="cabecera-pagina">
    <div>
        <h1>Proyectos</h1>
        <p>Cada proyecto agrupa los análisis de una web.</p>
    </div>
    <div class="acciones">
        <a class="boton" href="/proyectos/nuevo">Nuevo proyecto</a>
    </div>
</div>

<form class="filtros" role="search" action="/proyectos" method="get" aria-label="Buscar proyectos">
    <div class="filtros__texto">
        <label for="q">Buscar</label>
        <input id="q" name="q" type="search" maxlength="200" placeholder="Nombre, cliente o dirección web" value="<?= e($filtros['q']) ?>">
    </div>
    <div>
        <label for="tipo">Tipo</label>
        <select id="tipo" name="tipo">
            <option value="">Todos</option>
            <?php foreach ($tipos as $valor => $texto): ?>
                <option value="<?= e($valor) ?>"<?= $filtros['tipo'] === $valor ? ' selected' : '' ?>><?= e($texto) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="estado">Estado</label>
        <select id="estado" name="estado">
            <option value="">Todos menos archivados</option>
            <?php foreach ($estados as $valor => $texto): ?>
                <option value="<?= e($valor) ?>"<?= $filtros['estado'] === $valor ? ' selected' : '' ?>><?= e($texto) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filtros__botones">
        <button class="boton" type="submit">Buscar</button>
        <?php if ($hayFiltros): ?>
            <a class="boton boton--secundario" href="/proyectos">Quitar filtros</a>
        <?php endif; ?>
    </div>
</form>

<p role="status">
    <?= $total === 1 ? '1 proyecto' : e($total) . ' proyectos' ?><?= $hayFiltros ? ' con estos filtros' : '' ?>
    <?php if ($total >= $limite): ?>
        · se muestran los <?= e($limite) ?> primeros; afina la búsqueda para ver el resto.
    <?php endif; ?>
</p>

<?php if ($proyectos === []): ?>
    <p class="vacio">
        <?= $hayFiltros ? 'Ningún proyecto coincide con la búsqueda.' : 'Todavía no hay proyectos.' ?>
        <a href="/proyectos/nuevo">Crea uno nuevo</a>.
    </p>
<?php else: ?>
<div class="tabla-desplazable">
    <table class="tabla-apilable">
        <thead>
        <tr>
            <th scope="col">Proyecto</th>
            <th scope="col">Tipo</th>
            <th scope="col">Estado</th>
            <th scope="col">Último análisis</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($proyectos as $p): ?>
            <?php
            $tipo = TipoProyecto::tryFrom((string) $p['tipo']);
            $sector = Sector::tryFrom((string) $p['sector']);
            $estadoUltimo = EstadoAnalisis::tryFrom((string) $p['ultimo_estado']);
            ?>
            <tr>
                <td>
                    <a href="/proyectos/<?= e($p['id']) ?>"><strong><?= e($p['nombre']) ?></strong></a>
                    <?php if ($p['es_dato_prueba']): ?><span class="chip chip--prueba">Datos de prueba</span><?php endif; ?>
                    <span class="secundario">
                        <?= $p['cliente'] !== null ? e($p['cliente']) : 'Sin cliente (interno)' ?>
                        <?php if ($p['url_principal'] !== null): ?> · <?= e($p['url_principal']) ?><?php endif; ?>
                    </span>
                </td>
                <td data-etiqueta="Tipo">
                    <?= $tipo !== null ? e($tipo->etiqueta()) : '<span class="vacio">Sin indicar</span>' ?>
                    <?php if ($sector !== null): ?><span class="secundario">Sector: <?= e($sector->etiqueta()) ?></span><?php endif; ?>
                </td>
                <td data-etiqueta="Estado"><?= e(EstadoProyecto::tryFrom((string) $p['estado'])?->etiqueta() ?? $p['estado']) ?></td>
                <td data-etiqueta="Último análisis">
                    <?php if ($p['ultimo_id'] === null): ?>
                        <span class="vacio">Todavía sin análisis</span>
                    <?php else: ?>
                        <?= e(Formato::fecha((string) $p['ultimo_creado_en'])) ?>
                        <span class="secundario"><?= e($estadoUltimo?->etiqueta() ?? $p['ultimo_estado']) ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
