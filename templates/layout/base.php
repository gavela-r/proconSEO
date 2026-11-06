<?php
/**
 * Plantilla base. Variables: $titulo (string), $contenido (HTML ya generado por otra plantilla).
 * @var string $titulo
 * @var string $contenido
 */
$titulo = $titulo ?? 'proconSEO';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo) ?> · proconSEO</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <?php foreach (($estilos ?? []) as $css): /* CSS propio de cada parte: 'estilos' => ['/assets/css/run.css'] */ ?>
        <link rel="stylesheet" href="<?= e($css) ?>">
    <?php endforeach; ?>
</head>
<body>
<a class="saltar" href="#contenido">Saltar al contenido</a>
<header class="cabecera">
    <div class="contenedor cabecera__fila">
        <a class="marca" href="/">proconSEO</a>
        <nav aria-label="Principal">
            <ul class="menu">
                <li><a href="/proyectos">Proyectos</a></li>
                <li><a href="/catalogo">Catálogo</a></li>
                <li><a href="/avisos">Avisos</a></li>
            </ul>
        </nav>
    </div>
</header>
<main id="contenido" class="contenedor" tabindex="-1">
    <?= $contenido ?>
</main>
<footer class="pie">
    <div class="contenedor">Herramienta interna de Proconsi · Búsqueda con IA, SEO y accesibilidad</div>
</footer>
</body>
</html>
