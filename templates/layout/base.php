<?php
/**
 * Plantilla base. Variables: $titulo (string), $contenido (HTML ya generado por otra plantilla).
 * @var string $titulo
 * @var string $contenido
 */
$titulo = $titulo ?? 'proconSEO';

// Sección activa del menú, según la ruta pedida (solo para marcarla: no se muestra).
$ruta = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$secciones = [
    '/' => 'Inicio',
    '/proyectos' => 'Proyectos',
    '/analisis/nuevo' => 'Nuevo análisis',
    '/catalogo' => 'Catálogo',
    '/glosario' => 'Glosario',
    '/avisos' => 'Avisos',
];
$activa = '/';
foreach (array_keys($secciones) as $s) {
    if ($s !== '/' && ($ruta === $s || str_starts_with($ruta, $s . '/'))) {
        $activa = $s;
    }
}
// Un análisis o un resultado pertenece a Proyectos.
if ($activa === '/' && preg_match('#^/(analisis|resultados|evidencias)/#', $ruta) === 1) {
    $activa = '/proyectos';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo) ?> · proconSEO</title>
    <link rel="preload" href="/assets/fuentes/texto-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/assets/css/base.css">
    <?php foreach (($estilos ?? []) as $css): /* CSS propio de cada parte: 'estilos' => ['/assets/css/run.css'] */ ?>
        <link rel="stylesheet" href="<?= e($css) ?>">
    <?php endforeach; ?>
    <script src="/assets/js/comun.js" defer></script>
</head>
<body>
<a class="saltar" href="#contenido">Saltar al contenido</a>
<header class="cabecera">
    <div class="contenedor cabecera__fila">
        <a class="marca" href="/" aria-label="proconSEO, inicio">
            <img src="/assets/img/logo-proconsi.png" alt="Proconsi" width="87" height="30">
            <span class="marca__separador" aria-hidden="true"></span>
            <span class="marca__nombre">proconSEO</span>
        </a>
        <nav aria-label="Principal">
            <ul class="menu">
                <?php foreach ($secciones as $href => $texto): ?>
                    <li><a href="<?= e($href) ?>"<?= $href === $activa ? ' aria-current="page"' : '' ?>><?= e($texto) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </nav>
    </div>
</header>
<main id="contenido" class="contenedor" tabindex="-1">
    <?= $contenido ?>
</main>
<footer class="pie">
    <div class="contenedor">
        <span>proconSEO · herramienta interna de Proconsi · solo red interna</span>
        <span>Búsqueda con IA, SEO y accesibilidad</span>
    </div>
</footer>
</body>
</html>
