<?php
/** @var bool $baseDatosOk */
?>
<h1>proconSEO</h1>
<p class="entradilla">Analiza una web en tres bloques —búsqueda con IA, SEO y accesibilidad— con evidencias y sin notas engañosas.</p>

<div class="tarjetas">
    <a class="tarjeta" href="/proyectos">
        <h2>Proyectos</h2>
        <p>Crea un proyecto y lanza un análisis.</p>
    </a>
    <a class="tarjeta" href="/catalogo">
        <h2>Catálogo</h2>
        <p>Qué comprueba la herramienta y qué está implementado.</p>
    </a>
</div>

<?php if (!$baseDatosOk): ?>
    <p class="aviso aviso--error" role="alert">No hay conexión con la base de datos. Revisa el fichero <code>.env</code> y ejecuta <code>composer migrar</code>.</p>
<?php endif; ?>
