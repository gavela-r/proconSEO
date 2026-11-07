# Navegador aislado (prototipo)

Es la «fase futura» de la sección 11 de la especificación. Mide lo que no se ve en el HTML: hace falta pintar la página.

Es un **prototipo**: funciona por línea de comandos y todavía no está dentro del análisis ni de la base de datos. Cuando el equipo lo apruebe, se integra (ver el final).

## Qué mide

| Comprobación | Criterio | Cómo |
|---|---|---|
| `ACC-05.contraste-texto` | WCAG 1.4.3 | Color de cada texto visible y de las capas de fondo que tiene debajo. Se calcula la relación de contraste, con un mínimo de 4,5:1, o 3:1 en texto grande. |
| `ACC-05.contraste-sobre-imagen` | WCAG 1.4.3 | El texto sobre una imagen, un degradado o con sombra no se mide: sale como `no_verificable` para revisarlo a mano. |
| `ACC-06.foco-visible` | WCAG 2.4.7 | Se pulsa Tab de verdad y se compara el aspecto de cada elemento con y sin foco. |
| `ACC-06.foco-oculto` | WCAG 2.4.11 | Se detecta si el foco pasa por elementos invisibles o fuera de la pantalla. |
| `ACC-06.trampa-teclado` | WCAG 2.1.2 | Se detecta si el foco se queda atrapado. |
| `ACC-04.reflow-320` | WCAG 1.4.10 | Con 320 px de ancho (un zoom del 400 %), se mira si hay desplazamiento horizontal y qué lo provoca. Las tablas, imágenes y código salen como `decision_revisar`. |
| `SEO-09.contenido-tras-js` | SEO-09 / IA | Se comparan las palabras del HTML inicial con las de la página pintada. Si el contenido solo aparece con JavaScript, los rastreadores de IA no lo ven. |

Los estados son los de siempre, sin notas ni porcentajes. Si la página no se puede pintar, todo sale `no_verificable` con el motivo.

## Cómo probarlo

```
php bin/navegador.php https://www.ejemplo.es/
php bin/navegador.php https://www.ejemplo.es/ --peticiones   # qué se descargó y qué se cortó
php bin/navegador.php https://www.ejemplo.es/ --json         # mediciones en bruto
```

Necesita Chrome o Edge. Se busca en las rutas habituales de Windows; si está en otro sitio, se indica con `NAVEGADOR_RUTA=` en `.env`. Una página tarda entre 3 y 8 segundos.

## Seguridad (SEG-02, SEG-04, SEG-06, SEG-07, SEG-08)

La idea clave es que **el navegador no tiene red propia**. Todo lo que pide la página lo intercepta PHP y, si se permite, lo descarga `ClienteHttpSeguro`. Es el mismo cliente del resto del análisis, con la validación de la URL y de la IP real en cada subrecurso. Así, la regla de que solo la Parte 1 hace peticiones de red se sigue cumpliendo.

- **Sin DNS ni salida propia.** El navegador arranca con `--host-resolver-rules=MAP * ~NOTFOUND` (no resuelve ningún nombre) y con un proxy que apunta a un puerto cerrado. Si una petición se escapara de la interceptación, por ejemplo la de un service worker o un WebRTC, falla en vez de salir. El cortafuegos de salida del servidor (SEG-12) sigue siendo la segunda barrera.
- **Qué se permite** (`FiltroPeticiones`):
  - la página analizada y sus redirecciones;
  - CSS, JS y fuentes de cualquier host público;
  - XHR y fetch solo a los hosts del análisis.
- **Qué no se permite nunca**:
  - envíos (POST, PUT…);
  - navegar a otra página;
  - imágenes y vídeos, que no hacen falta para estas mediciones;
  - WebSocket, beacons y analítica de terceros.
- **Sesión desechable.** Cada página tiene un perfil nuevo en una carpeta temporal y un contexto aislado. Al terminar se cierra el navegador (con `taskkill /T` si no responde) y se borra el perfil. No se comparten cookies, almacenamiento ni service workers.
- **Sin efectos.** Solo se pulsa Tab. Las descargas se deniegan, las ventanas nuevas se bloquean y los diálogos se cierran sin aceptar.
- **Mediciones a salvo de la web.** El script de medición (`medir.js`) se ejecuta en un mundo aislado: comparte el DOM, pero los scripts de la página no pueden ver ni cambiar sus funciones. El texto de la página se trata como un dato.
- **La sandbox de Chrome sigue activa.** Nunca se usa `--no-sandbox`, `--disable-web-security` ni `--ignore-certificate-errors`.
- **Sin secretos.** El navegador arranca con un entorno mínimo, sin las variables del `.env`.
- **Límites por página.** Como máximo 150 peticiones, 20 MB y 45 s. Al llegar a uno se para y se dice.

**Riesgo conocido, para el pentest (SEG-19).** El canal de control del navegador escucha en `127.0.0.1`, en un puerto aleatorio, mientras dura la página, unos segundos. Un proceso local del servidor podría conectarse a él en ese tiempo. Se mitiga porque el servidor es de uso exclusivo y el worker va con un usuario sin privilegios (SEG-13). La alternativa, el canal por tubería (`--remote-debugging-pipe`), queda para la integración si ciberseguridad lo pide.

## Limitaciones del prototipo

- El contraste no ve los fondos pintados con pseudoelementos (`::before`) ni con imágenes. En esos casos sale como «sobre imagen».
- El contraste solo mide los primeros 8.000 px de alto de la página.
- El foco recorre como mucho 120 elementos, y no entra en los iframes.
- Una página que se redirige con JavaScript a otra no se sigue.
- No mide Core Web Vitals de laboratorio ni hace capturas. Los Core Web Vitals ya llegan de PageSpeed Insights.

## Integración (pendiente)

1. **Parte 1 (Amilivia).** El orquestador llama a `NavegadorAislado` para la página principal, o para cada página con un límite. El navegador corre con el mismo cliente y el mismo ámbito del análisis, y la decisión de robots.txt ya tomada.
2. **Parte 2 (José María).** Las lecturas de `LecturaProvisional` pasan a comprobaciones del catálogo (`ACC-04`, `ACC-05`, `ACC-06` y `SEO-09`). En la especificación pasan de «No (navegador)» a «Sí». Después se borra `LecturaProvisional`.
3. **Parte 3 (Adrián).** Los resultados salen como los demás. Las evidencias ya vienen con colores, selectores y ejemplos de texto.
4. **Servidor.** Hay que tener Chrome o Edge; Windows Server 2022 y las versiones posteriores traen Edge de serie. Al integrarlo, se añade a `bin/comprobar.php` la comprobación de que el navegador existe y arranca.
