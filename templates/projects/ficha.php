<?php
/**
 * Ficha de un proyecto con su histórico de análisis (RF-04).
 *
 * @var array<string, mixed> $proyecto
 * @var list<array<string, mixed>> $historial
 */

use ProconSEO\Projects\Formato;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\EstadoAnalisis;
use ProconSEO\Shared\Enum\EstadoProyecto;
use ProconSEO\Shared\Enum\Fase;
use ProconSEO\Shared\Enum\Sector;
use ProconSEO\Shared\Enum\TipoProyecto;
use ProconSEO\Shared\Vista;

$id = (int) $proyecto['id'];
$tipo = TipoProyecto::tryFrom((string) $proyecto['tipo']);
$sector = Sector::tryFrom((string) $proyecto['sector']);
$estado = EstadoProyecto::tryFrom((string) $proyecto['estado']);
$resumen = array_filter([$tipo?->etiqueta(), $sector?->etiqueta(), $estado?->etiqueta()]);
$enMarcha = [EstadoAnalisis::EnCola, EstadoAnalisis::EnCurso, EstadoAnalisis::Cancelando];
$totalFases = count(Fase::cases());
?>
<?php if ($proyecto['es_dato_prueba']): ?>
    <?= Vista::render('layout/aviso_prueba', [], false) ?>
<?php endif; ?>

<nav class="migas" aria-label="Estás en">
    <ol>
        <li><a href="/proyectos">Proyectos</a></li>
        <li aria-current="page"><?= e($proyecto['nombre']) ?></li>
    </ol>
</nav>

<?php if ($borrado ?? false): ?>
    <p class="aviso aviso--info" role="status"><strong>Análisis borrado.</strong> Se han eliminado sus resultados, evidencias, revisiones y capturas.</p>
<?php endif; ?>

<div class="cabecera-pagina">
    <div>
        <h1><?= e($proyecto['nombre']) ?></h1>
        <?php if ($resumen !== []): ?><p><?= e(implode(' · ', $resumen)) ?></p><?php endif; ?>
    </div>
    <div class="acciones">
        <a class="boton" href="/analisis/nuevo?proyecto=<?= e($id) ?>">Nuevo análisis</a>
        <a class="boton boton--secundario" href="/proyectos/<?= e($id) ?>/programados">Análisis programados</a>
        <a class="boton boton--secundario" href="/proyectos/<?= e($id) ?>/editar">Editar</a>
    </div>
</div>

<section class="panel" aria-labelledby="t-datos">
    <h2 id="t-datos">Datos del proyecto</h2>
    <dl class="datos">
        <dt>Cliente</dt>
        <dd><?= $proyecto['cliente'] !== null ? e($proyecto['cliente']) : '<span class="vacio">Sin cliente (interno)</span>' ?></dd>
        <dt>Dirección de la web</dt>
        <dd><?= $proyecto['url_principal'] !== null ? '<span class="codigo">' . e($proyecto['url_principal']) . '</span>' : '<span class="vacio">Sin indicar</span>' ?></dd>
        <dt>Tipo</dt>
        <dd><?= $tipo !== null ? e($tipo->etiqueta()) : '<span class="vacio">Sin indicar</span>' ?></dd>
        <?php if ($sector !== null): ?>
            <dt>Sector</dt><dd><?= e($sector->etiqueta()) ?></dd>
        <?php endif; ?>
        <dt>Estado</dt>
        <dd><?= e($estado?->etiqueta() ?? $proyecto['estado']) ?></dd>
        <dt>Notas</dt>
        <dd class="notas"><?= $proyecto['descripcion'] !== null ? e($proyecto['descripcion']) : '<span class="vacio">Sin notas</span>' ?></dd>
        <dt>Creado</dt>
        <dd><?= e(Formato::fecha((string) $proyecto['creado_en'])) ?> · última modificación <?= e(Formato::fecha((string) $proyecto['actualizado_en'])) ?></dd>
    </dl>
</section>

<section class="seccion" aria-labelledby="t-historico">
    <h2 id="t-historico">Histórico de análisis
        <?php if (count($historial) > 0): ?>
            <span class="contador"><?= count($historial) === 1 ? '1 análisis' : e(count($historial)) . ' análisis, el más reciente primero' ?></span>
        <?php endif; ?>
    </h2>

    <?php if ($historial === []): ?>
        <p class="vacio">Este proyecto todavía no tiene análisis. <a href="/analisis/nuevo?proyecto=<?= e($id) ?>">Lanza el primero</a>.</p>
    <?php else: ?>
    <div class="tabla-desplazable">
        <table class="tabla-apilable">
            <thead>
            <tr>
                <th scope="col">Fecha</th>
                <th scope="col">Alcance</th>
                <th scope="col">Estado</th>
                <th scope="col">Resultados</th>
                <th scope="col"><span class="solo-lectores">Acciones</span></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($historial as $a): ?>
                <?php
                $estadoA = EstadoAnalisis::tryFrom((string) $a['estado']);
                $fase = Fase::tryFrom((string) $a['fase_actual']);
                $activo = in_array($estadoA, $enMarcha, true);
                $numeroFase = $fase !== null ? array_search($fase, Fase::cases(), true) + 1 : null;
                $recuento = Formato::recuento($a['recuento']);
                ?>
                <tr>
                    <td>
                        <strong><?= e(Formato::fecha((string) $a['creado_en'])) ?></strong>
                        <span class="secundario"><?= e($a['url_final'] ?? $a['url_introducida']) ?></span>
                        <?php if ($a['es_dato_prueba']): ?><span class="chip chip--prueba">Datos de prueba</span><?php endif; ?>
                    </td>
                    <td data-etiqueta="Alcance"><?= e(Alcance::tryFrom((string) $a['alcance'])?->etiqueta() ?? $a['alcance']) ?></td>
                    <td data-etiqueta="Estado">
                        <strong><?= e($estadoA?->etiqueta() ?? $a['estado']) ?></strong>
                        <?php if ($activo && $fase !== null): ?>
                            <span class="secundario">Fase <?= e($numeroFase) ?> de <?= e($totalFases) ?>: <?= e($fase->etiqueta()) ?></span>
                        <?php elseif ($a['motivo_cierre'] !== null): ?>
                            <span class="secundario"><?= e($a['motivo_cierre']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-etiqueta="Resultados">
                        <?php if ($recuento === ''): ?>
                            <span class="vacio"><?= $activo ? 'Aún no hay resultados' : 'Sin resultados guardados' ?></span>
                        <?php else: ?>
                            <?= e($recuento) ?>
                            <?php if ($a['pendientes'] > 0): ?>
                                <span class="secundario"><?= $a['pendientes'] === 1 ? '1 revisión pendiente' : e($a['pendientes']) . ' revisiones pendientes' ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td data-etiqueta="Acciones">
                        <div class="acciones">
                            <a class="boton boton--pequeno<?= $activo ? '' : ' boton--secundario' ?>" href="/analisis/<?= e($a['id']) ?><?= $activo ? '/progreso' : '' ?>"><?= $activo ? 'Ver progreso' : 'Ver resultados' ?></a>
                            <?php if ($activo): ?>
                                <span class="secundario">No se puede borrar mientras está en marcha.</span>
                            <?php else: ?>
                                <a class="boton boton--pequeno boton--secundario" href="/analisis/<?= e($a['id']) ?>/borrar">Borrar<span class="solo-lectores"> el análisis del <?= e(Formato::fecha((string) $a['creado_en'])) ?></span></a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="seccion__ayuda">Los recuentos son número de resultados por estado, después de las revisiones humanas. No son una nota.</p>
    <?php endif; ?>
</section>
