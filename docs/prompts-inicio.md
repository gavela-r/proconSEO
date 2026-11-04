# Prompts para empezar con Claude Code

Cada persona clona el repositorio, abre Claude Code en la carpeta del proyecto y pega su prompt. Claude Code lee automáticamente el `CLAUDE.md` con las reglas comunes.

**Orden:** Amilivia empieza por la Parte 0 (base común). Mientras tanto, José María y Adrián pueden empezar con lo que no depende de ella, como indica su prompt. Cuando la Parte 0 esté fusionada en `main`, todos hacen `git pull` y siguen.

---

## Amilivia — Parte 0 y después Parte 1

```text
Soy Amilivia y en proconSEO me encargo de la Parte 0 (base común) y después de la Parte 1 (lanzar el análisis de forma segura).

Antes de empezar, lee CLAUDE.md, docs/especificacion.md, docs/decisiones.md y las issues #2 y #3 (gh issue view 2 y gh issue view 3).

PRIMERO, la Parte 0, en la rama amilivia/base-comun y en un solo PR que revisarán Adrián y José María:
1. composer.json con autoload PSR-4 (namespace ProconSEO\), PHPUnit y un script de tests.
2. public/index.php como punto de entrada y un router mínimo que cargue un fichero de rutas por parte: routes/run.php, routes/catalogo.php, routes/proyectos.php, routes/resultados.php, routes/revision.php y routes/exportar.php, vacíos salvo un ejemplo.
3. src/Shared/: configuración leída de .env, conexión PDO a MySQL (utf8mb4, excepciones, sin emulación de prepares), protección CSRF, función de escapado y formato común de errores JSON {"error":{"codigo":"...","mensaje":"..."}}.
4. src/Shared/Enum/ con enums PHP en español para: estado del resultado, ejecución, cobertura, tipo de conclusión, gravedad, esfuerzo, estado del análisis, fase, alcance, bloque (IA/SEO/ACC), tipo de proyecto y sector. Usa los valores de la especificación.
5. src/Shared/Contract/ con las interfaces y clases de datos que conectan las partes:
   - ProveedorRecursos::obtener(url, motivo, idComprobacion) → RecursoDescargado (la implementa la Parte 1)
   - MotorAnalisis::ejecutar(ContextoAnalisis, ProveedorRecursos, InformadorProgreso, TokenCancelacion) → SalidaMotor (la implementa la Parte 2)
   - Catalogo (la implementa la Parte 2)
   - InformadorProgreso y TokenCancelacion (las implementa la Parte 1)
   - BorradorResultado y BorradorEvidencia, con los campos de la sección 5 de la especificación
6. db/migrations/ con el esquema inicial en español: proyectos, perfiles_ejecucion, analisis, fases_analisis, recursos, grupos_causa, resultados (inmutable), evidencias, resultado_evidencia, revisiones (solo inserción) y eventos_seguridad. Más bin/migrar.php para aplicar las migraciones.
7. db/seeds/demo.sql con un proyecto de demostración y resultados de ejemplo de todos los estados, marcados con es_dato_prueba = 1, para que Adrián pueda trabajar sin esperar.
8. templates/layout/ con la plantilla base responsive y public/assets/css/base.css, sencillos y claros.
Explícame cada contrato antes de crearlo, porque los otros dos dependen de él.

DESPUÉS, la Parte 1 (issue #3), en ramas cortas amilivia/... y con PR pequeños que revisa José María. En este orden:
1. Validación de URL (SEG-01) y bloqueo de destinos prohibidos con la IP resuelta y fijada en cURL, incluidas IPv6 y redirecciones (SEG-02), con muchos tests.
2. Cliente HTTP seguro: TLS siempre verificado y límites de la sección 8 (tamaño, tiempo, redirecciones, reintentos, ritmo y 429).
3. Cola, worker (bin/worker.php), fases con progreso real, cancelación que conserva los resultados y pantallas de nuevo análisis y progreso. Usa un motor falso hasta que la Parte 2 esté lista.
4. Guardado de resultados sanitizando cookies, tokens y datos sensibles (SEG-09).
5. Alcances "URL con contexto" y "sitio completo", usando los extractores de enlaces y sitemaps de la Parte 2.
6. Cliente de la API de PageSpeed Insights, con la clave en .env.
No hagas peticiones de red fuera de src/Fetch/.
```

---

## José María — Parte 2: comprobaciones

```text
Soy José María y en proconSEO me encargo de la Parte 2: el catálogo de criterios y las comprobaciones de IA, SEO y accesibilidad. Mis carpetas son src/Engine/, catalog/ y templates/catalog/.

Antes de empezar, lee CLAUDE.md, docs/especificacion.md (sobre todo las secciones 5 y 6), docs/decisiones.md y mi issue #4 con sus comentarios (gh issue view 4 --comments).

Mi parte es una librería que recibe una página ya descargada y devuelve resultados. No hace peticiones de red ni escribe en la base de datos: si necesita otra URL (robots.txt, sitemap…), la pide a la interfaz ProveedorRecursos indicando qué comprobación la necesita, y en los tests uso una implementación falsa.

MIENTRAS AMILIVIA TERMINA LA PARTE 0 (que crea las interfaces en src/Shared/Contract/), empieza por lo que no depende de ella:
1. El catálogo en catalog/v1/: un JSON por criterio de la especificación (IA-01..11, SEO-01..16, ACC-01..12) con sus subcomprobaciones (ID estable como SEO-03.title-presente), qué comprueba, cuándo aplica, modo (automático, híbrido o humano), esfuerzo, referencia WCAG con nivel y si es de WCAG 2.1 o nuevo en 2.2, fuente oficial, si entra en el MVP y la referencia a mi Investigación (C01, O5, T1, Ficha 1…). Añade un test que compruebe que todos los IDs son únicos y que no falta ningún criterio.
2. Páginas HTML de ejemplo en tests/Fixtures/ para cada caso: cumple, no cumple, bloqueada y casos especiales (noindex de desarrollo, WordPress, tienda WooCommerce o PrestaShop, gasolinera con datos locales, web con chatbot).

CUANDO LA PARTE 0 ESTÉ EN MAIN (git pull), en ramas cortas josemaria/... y con PR pequeños que revisa Adrián:
3. Extracción común y segura (SEG-05): parser HTML con DOMDocument sin cargar entidades externas, y extracción de title, meta, canonical, robots, encabezados, enlaces, imágenes, formularios, lang, iframes y datos estructurados JSON-LD. Incluye el parser de robots.txt (con el protocolo completo de IA-01) y el de sitemaps XML. Haz este PR pronto, porque la Parte 1 lo necesita para el sitio completo.
4. Las comprobaciones marcadas "Sí" o "Parcial" en las secciones 6.1 a 6.3, empezando por las de recuperación (si falla la descarga, lo que depende de ella es no_verificable, nunca problema).
5. Las de las secciones 6.5 a 6.8: vídeo, producto, compromisos Kit Digital (incluido SEO-01.noindex-desarrollo como crítico), detección de CMS y comprobaciones por sector.
6. Conversión de la respuesta de PageSpeed Insights en resultados de SEO-11 y ACC-05.
7. Agrupar resultados con la misma causa y detectar texto de la página dirigido al analizador (SEG-07) como señal, sin que cambie ningún resultado.
8. Cada resultado lleva su explicación sencilla y, si hay CMS detectado, cómo corregirlo en WordPress, WooCommerce o PrestaShop.
9. La página "Catálogo y cobertura" (templates/catalog/).
Toda comprobación del catálogo devuelve un resultado; las no implementadas, con cobertura no_implementada.
```

---

## Adrián — Parte 3: proyectos, resultados, revisión y exportación

```text
Soy Adrián y en proconSEO me encargo de la Parte 3: proyectos, histórico, resultados, revisión humana y exportaciones. Mis carpetas son src/Projects/, src/Results/, src/Review/, src/Export/, templates/projects/ y templates/results/.

Antes de empezar, lee CLAUDE.md, docs/especificacion.md (sobre todo las secciones 3.1, 4, 5 y 6.7), docs/decisiones.md y mi issue #5 con sus comentarios (gh issue view 5 --comments).

Mi parte lee lo que guardan las otras dos en la base de datos. No ejecuta análisis ni hace peticiones de red. Puedo trabajar desde el principio con los datos de demostración (db/seeds/demo.sql) que crea la Parte 0.

MIENTRAS AMILIVIA TERMINA LA PARTE 0, empieza por lo que no depende de ella: diseña en HTML y CSS estático, dentro de templates/projects/ y templates/results/, las pantallas de lista de proyectos, ficha de proyecto con su histórico, dashboard del análisis, detalle de un resultado y formulario de revisión. Que sean responsive (móvil, tablet y escritorio) y claras para alguien que no sabe de SEO. El dashboard muestra cuántos resultados hay en cada estado por bloque, sin notas ni porcentajes, y la vista "Compromisos Kit Digital". Enséñamelas antes de programarlas.

CUANDO LA PARTE 0 ESTÉ EN MAIN (git pull), en ramas cortas adrian/... y con PR pequeños que revisa Amilivia:
1. Proyectos: crear, editar, listar y buscar, con nombre, cliente opcional, URL principal, notas, estado y tipo de proyecto (Web Kit Digital, Tienda Kit Digital, Web de cliente con sector, Web propia de Proconsi u Otra). Ficha con el histórico de análisis.
2. Dashboard y detalle en el orden de la especificación (4.4): primero bloqueos, después incidencias por causa, luego correctos y no aplicables, y al final no verificables y límites. En el detalle van la explicación sencilla, la evidencia, cómo corregirlo, la referencia WCAG con la marca "2.1 / nuevo en 2.2" y la fuente si el dato es externo.
3. Filtros (bloque, estado, criterio, revisión pendiente, URL) y búsqueda por texto.
4. Revisión humana: nombre del revisor obligatorio, motivo, decisión, nuevo estado y justificación. El resultado original no se modifica nunca y solo se insertan revisiones nuevas. Pasar a "correcto" exige añadir evidencia. Las capturas se suben de forma segura: solo PNG o JPG, comprobando el tipo real, recodificadas y guardadas fuera de public/.
5. Borrado de análisis con confirmación (no se puede borrar uno en curso).
6. Exportaciones a PDF, CSV/Excel y JSON, y exportación completa del proyecto. Conservan estados, evidencias, revisiones y límites; neutralizan fórmulas en CSV y Excel; y el PDF no carga recursos remotos.
7. Aviso visible de "DATOS DE PRUEBA" en todo lo marcado como es_dato_prueba.
Muestra siempre el contenido de la web analizada como texto escapado, nunca como HTML (SEG-09).
```
