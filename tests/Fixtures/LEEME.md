# Recursos de ejemplo

Páginas, robots.txt y sitemaps para probar las comprobaciones de la Parte 2 sin hacer red.

- `respuestas/<nombre>.json`: un recurso tal como lo entregaría la Parte 1 (`RecursoDescargado`):
  URL solicitada y final, resultado de la descarga, código HTTP, redirecciones, cabeceras, cuerpo
  (ruta a `paginas/`, `robots/` o `sitemaps/`) y TLS. Además:
  - `contexto`: tipo de proyecto, sector y objetivo declarados al lanzar el análisis.
  - `esperado`: estado esperado de las subcomprobaciones cuyo resultado es claro. No es exhaustivo.
  - `gravedad_esperada` y `senales_esperadas`, cuando proceden.
- Solo se usan dominios `.example` (RFC 2606), que nunca resuelven.

En los tests:

```php
$contexto  = Fixtures::contexto('noindex-desarrollo');
$proveedor = new ProveedorFalso(['https://www.panaderia.example/robots.txt' => 'robots-bloquea-todo']);
```

`tests/Engine/Soporte/FixturesTest.php` comprueba que todos los ejemplos son coherentes.
