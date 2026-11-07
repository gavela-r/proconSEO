<?php
/**
 * Página de inicio: lo que ha pasado últimamente y por dónde seguir.
 *
 * @var bool $baseDatosOk
 * @var list<array<string, mixed>> $recientes
 * @var list<array<string, mixed>> $avisos
 * @var list<array<string, mixed>> $proyectos
 * @var int $totalProyectos
 */

use ProconSEO\Projects\Formato;
use ProconSEO\Results\Iconos;
use ProconSEO\Shared\Enum\Alcance;
use ProconSEO\Shared\Enum\EstadoAnalisis;
use ProconSEO\Shared\Enum\Fase;
use ProconSEO\Shared\Enum\Sector;
use ProconSEO\Shared\Enum\TipoProyecto;

$ahora = new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid'));
$hora = (int) $ahora->format('G');
$saludo = $hora < 14 ? 'Buenos días' : ($hora < 21 ? 'Buenas tardes' : 'Buenas noches');
$dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
$meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$fecha = ucfirst($dias[(int) $ahora->format('w')]) . ', ' . $ahora->format('j') . ' de ' . $meses[(int) $ahora->format('n') - 1] . ' de ' . $ahora->format('Y');

$enMarcha = array_filter($recientes, static fn (array $a): bool => in_array($a['estado'], [EstadoAnalisis::EnCola->value, EstadoAnalisis::EnCurso->value, EstadoAnalisis::Cancelando->value], true));
$resumen = [];
if ($enMarcha !== []) {
    $resumen[] = count($enMarcha) === 1 ? 'Hay un análisis en marcha.' : 'Hay ' . count($enMarcha) . ' análisis en marcha.';
}
if ($avisos !== []) {
    $resumen[] = count($avisos) === 1 ? 'Tienes un aviso sin leer de un análisis programado.' : 'Tienes ' . count($avisos) . ' avisos sin leer de análisis programados.';
}
if ($resumen === []) {
    $resumen[] = 'Analiza una web en tres bloques —búsqueda con IA, SEO y accesibilidad— con evidencias y sin notas engañosas.';
}

$fases = Fase::cases();
$ordenEstados = ['problema' => 'problemas', 'mejorable' => 'mejorables', 'decision_revisar' => 'decisiones a revisar', 'correcto' => 'correctos'];
?>
<div class="titular">
    <div>
        <p class="meta"><?= e($fecha) ?></p>
        <h1><?= e($saludo) ?></h1>
        <p class="entradilla"><?= e(implode(' ', $resumen)) ?></p>
    </div>
    <a class="boton" href="/analisis/nuevo">Nuevo análisis</a>
</div>

<?php if (!$baseDatosOk): ?>
    <p class="aviso aviso--error" role="alert">No hay conexión con la base de datos. Revisa el fichero <code>.env</code> y ejecuta <code>composer migrar</code>.</p>
<?php else: ?>

<h2 class="numerada"><span class="numerada__n">01</span> Análisis recientes</h2>
<?php if ($recientes === []): ?>
    <p>Todavía no hay ningún análisis. <a href="/analisis/nuevo">Lanza el primero</a>.</p>
<?php else: ?>
<div class="tabla-desplazable">
    <table class="inicio__recientes">
        <thead>
            <tr>
                <th scope="col">Fecha</th>
                <th scope="col">Proyecto y dirección</th>
                <th scope="col">Alcance</th>
                <th scope="col">Ejecución</th>
                <th scope="col">Resultados</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($recientes as $a): ?>
            <?php
            $estado = EstadoAnalisis::tryFrom((string) $a['estado']);
            $marcha = $estado !== null && in_array($estado, [EstadoAnalisis::EnCola, EstadoAnalisis::EnCurso, EstadoAnalisis::Cancelando], true);
            $enlace = $marcha ? '/analisis/' . $a['id'] . '/progreso' : '/analisis/' . $a['id'];
            $fase = Fase::tryFrom((string) ($a['fase_actual'] ?? ''));
            $nFase = $fase !== null ? (int) array_search($fase, $fases, true) + 1 : null;
            ?>
            <tr>
                <td class="inicio__fecha"><?= e(Formato::fecha($a['creado_en'])) ?></td>
                <td>
                    <a class="inicio__proyecto" href="<?= e($enlace) ?>"><?= e($a['proyecto_nombre']) ?></a>
                    <?php if ($a['es_dato_prueba']): ?><span class="chip chip--prueba">Demostración</span><?php endif; ?>
                    <div class="url"><?= e($a['url_final'] ?: $a['url_introducida']) ?></div>
                </td>
                <td><?= e(Alcance::tryFrom((string) $a['alcance'])?->etiqueta() ?? '') ?></td>
                <td>
                    <span class="inicio__ejecucion<?= $marcha ? ' inicio__ejecucion--marcha' : '' ?>">
                        <?= e($estado?->etiqueta() ?? (string) $a['estado']) ?><?= $marcha && $nFase !== null ? ' · fase ' . $nFase . ' de ' . count($fases) : '' ?>
                    </span>
                </td>
                <td>
                    <?php if ($a['recuento'] === []): ?>
                        <span class="meta">—</span>
                    <?php else: ?>
                        <ul class="inicio__recuento">
                            <?php foreach ($ordenEstados as $clave => $nombre): ?>
                                <?php $n = (int) ($a['recuento'][$clave] ?? 0); ?>
                                <li class="estado estado--<?= e($clave) ?><?= $n === 0 ? ' inicio__cero' : '' ?>"><?= Iconos::estado($clave) ?><?= $n ?><span class="visualmente-oculto"> <?= e($nombre) ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="inicio__mitades">
    <section aria-labelledby="t-avisos">
        <h2 class="numerada" id="t-avisos"><span class="numerada__n">02</span> Avisos</h2>
        <?php if ($avisos === []): ?>
            <p class="meta">No hay avisos sin leer. Aparecen aquí cuando un análisis programado encuentra algo grave.</p>
        <?php else: ?>
            <ul class="inicio__lista">
                <?php foreach ($avisos as $av): ?>
                    <li>
                        <a href="/analisis/<?= e($av['analisis_id']) ?>#t-cambios"><?= e($av['titulo']) ?></a>
                        <span class="meta"><?= e($av['proyecto_nombre']) ?> · <?= e(Formato::fecha($av['creado_en'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="inicio__mas"><a href="/avisos">Ver todos los avisos</a></p>
        <?php endif; ?>
    </section>

    <section aria-labelledby="t-proyectos">
        <h2 class="numerada" id="t-proyectos"><span class="numerada__n">03</span> Proyectos activos</h2>
        <?php if ($proyectos === []): ?>
            <p><a href="/proyectos/nuevo">Crea el primer proyecto</a> para empezar a analizar webs.</p>
        <?php else: ?>
            <ul class="inicio__lista inicio__lista--proyectos">
                <?php foreach ($proyectos as $p): ?>
                    <?php
                    $tipo = TipoProyecto::tryFrom((string) ($p['tipo'] ?? ''))?->etiqueta();
                    $sector = Sector::tryFrom((string) ($p['sector'] ?? ''))?->etiqueta();
                    ?>
                    <li>
                        <a href="/proyectos/<?= e($p['id']) ?>"><?= e($p['nombre']) ?></a>
                        <span class="meta"><?= e(implode(' · ', array_filter([$tipo, $sector]))) ?></span>
                        <span class="meta"><?= (int) $p['analisis'] === 1 ? '1 análisis' : e((int) $p['analisis'] . ' análisis') ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="inicio__mas"><a href="/proyectos"><?= $totalProyectos > count($proyectos) ? e('Ver los ' . $totalProyectos . ' proyectos') : 'Ver los proyectos' ?></a></p>
        <?php endif; ?>
    </section>
</div>
<?php endif; ?>
