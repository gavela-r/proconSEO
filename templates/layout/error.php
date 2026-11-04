<?php
/** @var \ProconSEO\Shared\Error\ErrorDominio $error */
?>
<h1><?= $error->estadoHttp === 404 ? 'Página no encontrada' : 'No se ha podido completar la acción' ?></h1>
<p class="aviso aviso--error" role="alert"><?= e($error->getMessage()) ?></p>
<p><a href="/">Volver al inicio</a></p>
