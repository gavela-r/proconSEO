# Especificación de requisitos — proconSEO

Herramienta web interna de Proconsi para analizar sitios web en tres bloques: **búsqueda con IA**, **SEO** y **accesibilidad**.

| | |
|---|---|
| Versión | 1.0 (borrador para revisión del equipo) |
| Fecha | 4 de noviembre de 2026 |
| Fuentes | Guía para el análisis web + Anexos (Amilivia) · Investigación ejecutiva (José María) · Informe ejecutivo (Adrián) |
| Regla de precedencia | Si dos fuentes no coinciden, manda la Guía + Anexos. Las decisiones del equipo están en [decisiones.md](decisiones.md). |

Qué aporta cada fuente a este documento:

- **Informe ejecutivo**: objetivos de cada bloque, principios clave y orden de prioridad de actuación.
- **Investigación ejecutiva**: explicación de cada criterio, umbrales de Core Web Vitals, distinción entre cita, mención y recomendación, reglas generales para la herramienta y casos de validación.
- **Guía + Anexos**: catálogo de criterios con ID, estados de resultado, reglas de evidencia, alcances, seguridad del analizador, límites configurables y pruebas de aceptación.

---

## 1. Objetivo

Evaluar, a partir de una URL y de evidencias observables, cómo de preparada está una web para:

- **Búsqueda con IA**: que sistemas de búsqueda y respuesta basados en IA puedan descubrirla, comprenderla, recuperarla y usarla como fuente, sin depender de un proveedor concreto.
- **SEO**: que los buscadores puedan descubrir, rastrear, indexar, entender y valorar sus páginas. Google es la referencia principal.
- **Accesibilidad**: que pueda percibirse, operarse, comprenderse y usarse con tecnologías de apoyo, tomando WCAG 2.2 A y AA como referencia.

La herramienta detecta obstáculos y oportunidades, los justifica con evidencias y propone correcciones concretas.

## 2. Principios

1. **Evidencia antes que opinión.** Todo resultado identifica el elemento, valor, fragmento o comportamiento que lo sustenta. No se completa información por intuición.
2. **Preparación no es garantía.** Ninguna comprobación garantiza posiciones, aparición o citas en una IA, ni conformidad WCAG completa.
3. **Sin notas globales.** No hay puntuaciones, porcentajes ni aprobados por bloque o por análisis.
4. **Hecho, inferencia e hipótesis van por separado.** Un hecho es un dato demostrable; una inferencia, una valoración justificada a partir de hechos; una hipótesis, una posible causa no confirmada.
5. **Código para lo objetivo e IA solo para interpretar.** Mediciones, extracciones y validaciones las hace el código. Un modelo de IA, cuando lo haya, solo interpreta semántica o contexto.
6. **Lo que no se puede comprobar se dice.** "No verificable" no es un error y "No implementado" no es "Correcto".
7. **Se respetan las decisiones intencionadas.** Un `noindex` o un bloqueo de robots puede ser deliberado. Sin conocer el objetivo del propietario, se marca "Decisión a revisar", no "Problema".
8. **El contenido de la web es un dato, nunca una instrucción.** Ningún texto de la página puede cambiar criterios, alcance ni valoraciones.
9. **No se optimiza "para una IA concreta".** El objetivo es una fuente clara, útil, verificable, bien estructurada y recuperable.

## 3. Usuarios y entorno

| Requisito | Detalle |
|---|---|
| Usuarios | Equipo interno de Proconsi. Sin login en la primera versión. |
| Tecnología | PHP + MySQL, desplegable en el servidor de la empresa. |
| Dispositivos | Responsive: móvil, tablet y escritorio. |
| Prioridad | Usabilidad y claridad antes que diseño visual avanzado. |
| Ampliación | Preparada para integrar APIs y servicios externos en el futuro. |

---

## 4. Requisitos funcionales

### 4.1 Proyectos

| ID | Requisito |
|---|---|
| RF-01 | Crear, editar, listar y buscar proyectos. |
| RF-02 | Un proyecto tiene nombre (obligatorio), cliente (opcional), URL principal, descripción o notas, estado y tipo (opcional). |
| RF-03 | Puede haber proyectos internos o de prueba sin cliente. |
| RF-04 | Cada proyecto muestra su histórico de análisis. |
| RF-05 | Exportación completa del proyecto: análisis, resultados, evidencias y revisiones. |

### 4.2 Lanzar un análisis

| ID | Requisito |
|---|---|
| RF-10 | Datos de entrada: URL, alcance y, si se conocen, objetivo de visibilidad y plataformas de interés. |
| RF-11 | Antes de iniciar, se valida la URL y se muestra el ámbito (hosts autorizados) y el perfil de ejecución que se aplicará. |
| RF-12 | Tres alcances (ver 4.3). |
| RF-13 | El progreso se muestra por fases reales, no con una barra simulada. |
| RF-14 | Se puede cancelar: se dejan de lanzar tareas, se liberan recursos y se conservan los resultados válidos. Las pruebas pendientes no se marcan como realizadas. |
| RF-15 | Estados de la ejecución: en cola, en curso, completada, parcial, cancelada, detenida por error y detenida por seguridad. Son distintos de los estados de cada resultado. |
| RF-16 | Los análisis se pueden borrar con confirmación previa. |

Fases del análisis:

1. Validación de URL y ámbito
2. Recuperación: robots.txt, página principal y redirecciones
3. Descubrimiento de URLs relacionadas (solo con contexto o sitio completo)
4. Renderizado (cuando exista navegador; ver 11)
5. Extracción de datos de la página
6. Comprobaciones de IA, SEO y accesibilidad
7. Agrupación de resultados por causa
8. Cierre: cobertura, límites alcanzados y limpieza

### 4.3 Alcances

| Alcance | Qué se consulta | Qué no |
|---|---|---|
| **URL individual** | La URL, sus redirecciones dentro del ámbito, los recursos imprescindibles y `/robots.txt`. Se leen canonical, hreflang y sitemap, pero no se siguen. | Otras páginas. Lo que necesite otras URLs queda "No verificable". |
| **URL con contexto** | Además, las URLs concretas que necesita un criterio (robots, sitemap, canonical, hreflang, enlaces, autoría). Se registra qué criterio justifica cada consulta. | No se añaden enlaces a una cola de rastreo indiscriminadamente. |
| **Sitio completo** | Rastreo de las URLs del ámbito por enlaces y sitemaps, con deduplicación y límites de páginas, profundidad y variantes. | No implica exhaustividad. Subdominios y externos no se incorporan automáticamente. |

El ámbito es una lista explícita de hosts, esquemas y puertos. Indicar un dominio no incluye `www` ni otros subdominios. Si una redirección sale del ámbito, se registra y no se sigue.

### 4.4 Resultados

| ID | Requisito |
|---|---|
| RF-20 | Dashboard inicial con un resumen por bloque: cuántos resultados hay en cada estado y qué cobertura está implementada. Sin notas ni porcentajes. |
| RF-21 | Orden del informe: identificación del análisis y perfil → resumen por bloque y cobertura → bloqueos de acceso o recuperación → incidencias agrupadas por causa → Correctos y No aplicables → No verificables y revisiones pendientes → capacidades pendientes y límites. |
| RF-22 | Detalle por criterio y subcomprobación con todos los campos de la sección 5. |
| RF-23 | Filtros por bloque, estado, criterio, revisión pendiente y URL, y búsqueda por texto. |
| RF-24 | Una misma causa se muestra una vez, con sus efectos en cada bloque, sin repetir recomendaciones. |
| RF-25 | Las recomendaciones dicen qué cambiar y dónde. No se impone una solución cuando hay alternativas válidas. |

### 4.5 Evidencias

| ID | Requisito |
|---|---|
| RF-30 | Tipos de evidencia: fragmento de texto o HTML, cabeceras y respuestas HTTP, mediciones (con valor, unidad, dispositivo y condiciones) y capturas cuando sean necesarias. |
| RF-31 | Cada evidencia tiene un identificador (E-001…) y está vinculada al recurso y al momento de captura. |
| RF-32 | Las evidencias se guardan mínimas y sanitizadas (ver SEG-09). |

### 4.6 Revisión humana

| ID | Requisito |
|---|---|
| RF-40 | Cualquier resultado puede revisarse. Al hacerlo se pide el nombre o identificador del revisor, porque no hay login. |
| RF-41 | Una revisión guarda: resultado revisado, motivo, evidencia original, revisor, fecha, evidencia añadida, decisión y justificación. |
| RF-42 | El hallazgo original **no se edita nunca**. La revisión crea una nueva valoración vinculada. |
| RF-43 | Un comentario sin evidencia no puede convertir un resultado en "Correcto". |
| RF-44 | Mientras una revisión esté pendiente, la subcomprobación sigue "No verificable". Eso no invalida otros hallazgos ya demostrados. |

### 4.7 Exportación

| ID | Requisito |
|---|---|
| RF-50 | Exportar un análisis a PDF, Excel/CSV y JSON. |
| RF-51 | Toda exportación conserva estados, evidencias, revisiones, límites, exclusiones y fallos de acceso. |
| RF-52 | Los datos de prueba aparecen marcados como tales en pantalla y en las exportaciones. |

### 4.8 Catálogo y cobertura

| ID | Requisito |
|---|---|
| RF-60 | Página que lista todas las subcomprobaciones con su cobertura: Implementada, Parcialmente implementada o No implementada. |
| RF-61 | Cada análisis guarda la versión del catálogo y de las reglas que usó. |

---

## 5. Modelo de resultado

Cada resultado sigue la secuencia **Estado → Evidencia → Justificación → Impacto → Recomendación**, con estos campos:

| Campo | Contenido |
|---|---|
| Criterio y subcomprobación | p. ej. `SEO-03` / `SEO-03.title-presente` |
| Estado | Uno de los seis de la tabla siguiente |
| Ejecución | Ejecutada, Parcial o No ejecutada |
| Cobertura | Implementada, Parcialmente implementada o No implementada |
| Tipo de conclusión | Hecho, Inferencia o Hipótesis |
| Evidencia | Referencias E-xxx |
| Recurso o elemento | URL y elemento afectado |
| Consecuencia | Qué impide o dificulta |
| Corrección | Qué cambiar y dónde, cuando proceda |
| Límite o revisión | Qué no demuestra este resultado y si necesita revisión humana (con motivo) |
| WCAG (solo accesibilidad) | Número, nombre y nivel exactos, p. ej. "1.1.1 Contenido no textual · A" |
| Modo (solo accesibilidad) | Automático, Híbrido o Humano |
| Gravedad | Solo si el estado es "Problema detectado" |
| Fuente | Herramienta o fuente externa y su fecha, si el dato viene de fuera |

### Estados

| Estado | Cuándo se usa |
|---|---|
| **Correcto** | La evidencia demuestra que cumple. Nunca por simple ausencia de alertas. |
| **Mejorable** | Cumplimiento parcial o mejora concreta y justificable. |
| **Problema detectado** | Deficiencia verificada con una consecuencia concreta. |
| **Decisión a revisar** | Configuración válida cuya intención hay que confirmar o que puede contradecir el objetivo declarado. |
| **No verificable** | Falta evidencia, es contradictoria o inaccesible, o hay una revisión humana pendiente. Se indica qué falta. |
| **No aplicable** | No se da la condición de aplicación o hay una excepción justificada. |

Reglas:

- Si falla una dependencia (por ejemplo, la página no se pudo descargar), lo que depende de ella queda "No verificable" y "No ejecutada". No se generan problemas por datos ausentes.
- Las inferencias e hipótesis no reciben gravedad.
- La gravedad (verificada) y la prioridad de actuación son cosas distintas.

### Prioridad de actuación

Orden recomendado:

1. Bloqueos y fallos confirmados de acceso, recuperación o indexación.
2. Información incorrecta o contradictoria y carencias importantes de contenido o arquitectura.
3. Mejoras complementarias: experiencia, autoridad y detalle.

**Pendiente de decidir**: los niveles concretos de gravedad y si se añaden esfuerzo (bajo, medio o alto) e impacto descriptivo como campos informativos, nunca como puntuación.

---

## 6. Criterios de evaluación

La columna **Ref.** enlaza con la Investigación ejecutiva, donde está la explicación de cada criterio. **MVP** indica la propuesta para la primera versión (pendiente de confirmar): **Sí** = se implementa; **Parcial** = solo lo comprobable sin navegador ni IA; **No** = aparece como "No implementada".

### 6.1 Búsqueda con IA

| ID | Qué comprueba | Ref. | MVP |
|---|---|---|---|
| IA-01 | Permiso en robots.txt para Googlebot, bingbot, OAI-SearchBot, Claude-SearchBot y PerplexityBot, según el protocolo completo de robots (grupos, comodines, empates, códigos 4xx/429/5xx). Se distingue de GPTBot, ClaudeBot y Google-Extended. | C02 | Sí |
| IA-02 | `noindex`, `nosnippet`, `max-snippet`, `data-nosnippet` y `X-Robots-Tag`, por plataforma y alcance. Rastrear, indexar y mostrar fragmentos son cosas distintas. | C02 | Sí |
| IA-03 | Respuesta utilizable y contenido principal recuperable: HTTP, redirecciones, 403/429, CAPTCHA, y HTML inicial frente a DOM renderizado. | C01 | Parcial |
| IA-04 | Coherencia entre title, H1, introducción, encabezados y contenido. | C05, C07 | No (IA) |
| IA-05 | Autoría, responsable y respaldo de las afirmaciones relevantes. No se concluye falsedad ni falta de experiencia. | C09, C10, C12 | No (IA) |
| IA-06 | Coherencia temporal: fechas, precios, versiones, "este año". Una fecha antigua no es un problema por sí sola. | C11 | No (IA) |
| IA-07 | Datos estructurados (JSON-LD, Microdata, RDFa): sintaxis, tipos y coherencia con lo visible. Su ausencia no es un problema. | C16 | Parcial |
| IA-08 | Canonical, presencia en sitemap y enlazado interno dentro del alcance. | C03 | Sí |
| IA-09 | Valor y especificidad: datos, casos, metodología y ejemplos adecuados al propósito. | C06, C08, C09 | No (IA) |
| IA-10 | Contexto textual de imágenes y vídeos necesarios para entender la página; información esencial disponible como texto. | C04 | Parcial |
| IA-11 | En páginas funcionales, acciones esenciales identificables por nombre, rol y estado (reutiliza ACC-12). | — | Parcial |

Fuera del alcance de la URL (fase futura, con datos externos): citas, menciones y recomendaciones reales en respuestas de IA (C18), coherencia con perfiles externos y locales (C14) y reputación. Se registran por separado: **una cita no es una mención y una mención no es una recomendación comercial**. `llms.txt` se detecta solo de forma informativa, sin valorarlo.

### 6.2 SEO

| ID | Qué comprueba | Ref. | MVP |
|---|---|---|---|
| SEO-01 | Estado técnico, rastreabilidad e indexabilidad; HTTP/HTTPS y certificado básico; errores con HTTP 200 (posible soft 404). | T1, T2, T3, T8, T11 | Sí |
| SEO-02 | URL solicitada frente a final, cadenas y bucles de redirección, canonical y variantes. | T4, T6, T7 | Sí |
| SEO-03 | Title: presencia y descripción específica del propósito. La longitud es solo un dato auxiliar. | O5, O4 | Parcial (presencia) |
| SEO-04 | Meta description: presencia, marcado y coherencia. Sin longitud fija. | O6 | Parcial (presencia) |
| SEO-05 | Encabezados H1–H6 y estructura. Varios H1 o saltos de nivel no son errores automáticos. | O5 | Parcial |
| SEO-06 | Intención, suficiencia y valor de la página frente a lo que promete. Sin mínimos de palabras. | O1, O2, O3, C08, C13 | No (IA) |
| SEO-07 | Enlazado interno: enlaces rastreables, rotos y textos ambiguos. | O9, T5, T15 | Sí |
| SEO-08 | Duplicidad y variantes; solo es problema si hay una contradicción técnica observable. | T6 | Parcial |
| SEO-09 | Contenido en HTML inicial, en DOM automático o solo tras interacción. | T9 | No (navegador) |
| SEO-10 | Equivalencia de contenido y señales en móvil. | T10 | No (navegador) |
| SEO-11 | Core Web Vitals (ver 6.4). | V1–V4, C17 | No (navegador/API) |
| SEO-12 | Imágenes: descubrimiento, formato y contexto. | O7 | Sí |
| SEO-13 | Intersticiales, overlays y anuncios que tapan el contenido. | T12 | No (navegador) |
| SEO-14 | Datos estructurados y elegibilidad para resultados enriquecidos (reutiliza IA-07). | T13 | Parcial |
| SEO-15 | Versiones por idioma: códigos y destinos hreflang y reciprocidad. | T14 | Sí |
| SEO-16 | Paginación, filtros y facetas, muestreando patrones sin generar combinaciones masivas. | T16 | No |

Fuera del alcance de la URL (fase futura, con acceso autorizado o APIs): indexación real, canonical elegida por Google, acciones manuales, búsquedas, impresiones, clics y posiciones (M1–M4), enlaces entrantes y métricas de autoridad (E1–E3), y SEO local con Perfil de Empresa (O10).

**Pendiente de decidir**: la Investigación incluye vídeos (O8) y páginas de producto como criterios SEO propios; la Guía no los tiene. Proponemos tratarlos en una versión posterior como subcomprobaciones de SEO-12 y SEO-14.

### 6.3 Accesibilidad (WCAG 2.2 A y AA)

Los 55 criterios A y AA se agrupan en 12 bloques. Cada incidencia indica el criterio WCAG exacto. 4.1.1 no se evalúa porque se eliminó en WCAG 2.2, y los criterios AAA nunca cuentan como incumplimiento.

| ID | Tema | Criterios WCAG | MVP |
|---|---|---|---|
| ACC-01 | Contenido no textual | 1.1.1 A | Parcial (falta de alt; la adecuación es humana) |
| ACC-02 | Audio, vídeo y multimedia | 1.2.1, 1.2.2, 1.2.3, 1.4.2 A · 1.2.4, 1.2.5 AA | Parcial (detección de medios y pistas) |
| ACC-03 | Estructura, relaciones y orden | 1.3.1, 1.3.2, 1.3.3, 2.4.2 A · 2.4.6 AA | Parcial (title, tablas, labels) |
| ACC-04 | Adaptación y presentación | 1.3.4, 1.4.4, 1.4.5, 1.4.10, 1.4.12, 1.4.13 AA | No (navegador) |
| ACC-05 | Color y contraste | 1.4.1 A · 1.4.3, 1.4.11 AA | No (navegador) |
| ACC-06 | Teclado, foco y navegación | 2.1.1, 2.1.2, 2.1.4, 2.4.1, 2.4.3, 2.4.4 A · 2.4.7, 2.4.11 AA | Parcial (nombres de enlace) |
| ACC-07 | Tiempo, movimiento y parpadeo | 2.2.1, 2.2.2, 2.3.1 A | No |
| ACC-08 | Entrada y objetivos táctiles | 2.5.1, 2.5.2, 2.5.3, 2.5.4 A · 2.5.7, 2.5.8 AA | No (navegador) |
| ACC-09 | Formularios, errores y autenticación | 3.3.1, 3.3.2, 3.3.7 A · 1.3.5, 3.3.3, 3.3.4, 3.3.8 AA | Parcial (labels, autocomplete) |
| ACC-10 | Idioma y predictibilidad | 3.1.1, 3.2.1, 3.2.2 A · 3.1.2 AA | Parcial (lang) |
| ACC-11 | Consistencia entre páginas | 3.2.6 A · 2.4.5, 3.2.3, 3.2.4 AA | No |
| ACC-12 | Nombre, rol, estado y mensajes de estado | 4.1.2 A · 4.1.3 AA | Parcial (nombres, roles, title de iframe) |

Valores normativos que hay que aplicar:

| Comprobación | WCAG | Valor |
|---|---|---|
| Contraste de texto | 1.4.3 | 4,5:1 (3:1 en texto grande: 18 pt, o 14 pt en negrita) |
| Contraste no textual | 1.4.11 | 3:1 frente a colores adyacentes |
| Redimensionado | 1.4.4 | Hasta 200 % sin pérdida |
| Reflow | 1.4.10 | 320 px CSS de ancho (256 px de alto en contenido horizontal) |
| Espaciado | 1.4.12 | Interlineado 1,5 · párrafos 2 · letras 0,12 · palabras 0,16 |
| Movimiento | 2.2.2 | Más de 5 s → mecanismo de pausa |
| Audio automático | 1.4.2 | Más de 3 s → pausa o volumen independiente |
| Parpadeo | 2.3.1 | Como máximo 3 destellos por segundo |
| Tamaño de objetivo | 2.5.8 | 24 × 24 px CSS o una excepción justificada |
| Límite de tiempo | 2.2.1 | Ampliable al menos 10 veces; aviso con al menos 20 s para ampliar |

Las excepciones de cada criterio se aplican siempre. Las fichas de la Investigación ejecutiva (1 a 55) son la explicación de referencia.

### 6.4 Core Web Vitals (SEO-11)

| Métrica | Bueno | Necesita mejora | Deficiente |
|---|---|---|---|
| LCP (aparición del contenido principal) | ≤ 2,5 s | > 2,5 s y ≤ 4 s | > 4 s |
| INP (respuesta a la interacción) | ≤ 200 ms | > 200 ms y ≤ 500 ms | > 500 ms |
| CLS (estabilidad visual) | ≤ 0,1 | > 0,1 y ≤ 0,25 | > 0,25 |

- Se evalúa el percentil 75, separando móvil y ordenador.
- Datos de campo (visitas reales, últimos 28 días) y de laboratorio (simulación) no se mezclan. Si la URL no tiene datos propios y se muestran los del origen, se indica.
- INP solo se evalúa con interacciones documentadas. La ausencia de datos de campo no demuestra lentitud.

---

## 7. Seguridad del analizador

Estos controles protegen **a la herramienta y su infraestructura**; no son una auditoría de seguridad de la web analizada. Se aplican en el código, nunca mediante instrucciones a un modelo de IA.

| ID | Control |
|---|---|
| SEG-01 | **Validación de URL**: solo http y https; se rechazan usuario y contraseña en la URL, construcciones ambiguas y puertos fuera de la lista permitida. Si no cumple, se detiene antes de cualquier petición. |
| SEG-02 | **SSRF, DNS y redirecciones**: antes de conectar, se bloquean localhost, redes privadas, link-local, metadatos cloud y rangos reservados, en IPv4 e IPv6. Se valida la IP efectiva y se conecta a esa misma IP (contra el DNS rebinding). Se revalida en cada redirección y reintento, y para cada subrecurso, fetch, iframe o WebSocket. |
| SEG-03 | **TLS**: nunca se ignoran certificados caducados, no confiables o con nombre incorrecto. El uso de HTTP se registra. |
| SEG-04 | **Renderizado aislado**: navegador en sesión aislada, con red restringida, permisos denegados y sin secretos. JavaScript no se desactiva por defecto. |
| SEG-05 | **Parsers y presupuesto**: se contrasta el tipo declarado con el real. HTML, XML, PDF, SVG e imágenes se procesan con parsers seguros, sin resolver entidades externas. Hay límites de descarga, tamaño descomprimido, tiempo, peticiones, CPU y memoria, y nunca se amplían en silencio. |
| SEG-06 | **Sin efectos externos**: se permiten menús, pestañas, acordeones y foco. Están prohibidos envíos, compras, reservas, publicaciones, logins, cargas y descargas innecesarias. Ante la duda, no se ejecuta. |
| SEG-07 | **Prompt injection**: el texto visible u oculto, atributos, metadatos, imágenes y OCR se tratan como datos. Se registran los intentos dirigidos al analizador. La salida del modelo nunca autoriza acciones. |
| SEG-08 | **Sesiones y secretos**: sesiones desechables, sin compartir cookies, almacenamiento local ni service workers entre análisis. No se exponen claves, variables de entorno ni archivos locales. |
| SEG-09 | **Evidencias, logs y resultados**: el contenido se escapa o sanitiza antes de mostrarlo, guardarlo o exportarlo. Se enmascaran cookies, cabeceras Authorization, tokens, contraseñas, IDs de sesión y datos personales innecesarios. Los payloads nunca se guardan completos. |

Ante un riesgo, la herramienta decide **continuar**, **limitar** (se conserva lo válido y se declaran las pruebas afectadas) o **detener**. Toda limitación se registra con fase, recurso, condición y decisión. Ninguna protección se desactiva para completar una prueba.

Seguridad de la propia aplicación: consultas SQL parametrizadas, protección CSRF en los formularios, escapado de toda salida HTML, archivos subidos validados y guardados fuera de la carpeta pública, y neutralización de fórmulas en las exportaciones a Excel/CSV.

---

## 8. Perfil de ejecución

Antes de la primera ejecución se crea un perfil **versionado** con estos parámetros. Cada uno tiene valor, unidad, justificación y fecha. Si falta un valor obligatorio, la capacidad afectada no se inicia.

| Grupo | Parámetros |
|---|---|
| Rastreo | Máximo de páginas por alcance, profundidad, variantes, sitemaps y redirecciones |
| Tiempo | Tiempo por petición, carga, render y análisis completo; reintentos y espera entre ellos |
| Descarga | Bytes por recurso y por análisis, tamaño descomprimido y número de peticiones |
| Tráfico | Frecuencia por host, concurrencia y análisis simultáneos |
| IA y APIs | Modelo, llamadas, tokens y presupuesto económico (fase futura) |
| Observación | Viewports, user-agent, idioma, zona horaria y estado del consentimiento de cookies |

Ante una respuesta 429, se pausa ese host y se respeta `Retry-After`. Nunca se cambia de identidad para evitar un bloqueo. Si la espera supera el tiempo disponible, el análisis se cierra como parcial.

**Pendiente de decidir**: todos los valores numéricos, que se calibrarán en el servidor de Proconsi.

---

## 9. Requisitos no funcionales

| ID | Requisito |
|---|---|
| RNF-01 | Compatible con PHP + MySQL en el servidor de Proconsi. |
| RNF-02 | Responsive y usable en móvil, tablet y escritorio. |
| RNF-03 | Trazabilidad: cada análisis guarda la versión del catálogo, el perfil, la configuración efectiva, el consumo y la fecha. |
| RNF-04 | Reproducibilidad: misma configuración, mismas reglas. Las reglas se pueden actualizar sin alterar resultados anteriores. |
| RNF-05 | Fuentes: cada regla que dependa de una plataforma o un estándar enlaza a su fuente oficial y fecha de verificación. |
| RNF-06 | Ampliable: los proveedores externos (IA, métricas, Search Console…) se conectan mediante interfaces, sin cambiar el núcleo. |
| RNF-07 | Sin secretos en el repositorio. La configuración va en `.env`. |

---

## 10. Pruebas de aceptación

Para cada subcomprobación obligatoria hay que probar un caso que cumple, uno que no, sus excepciones y uno con la dependencia bloqueada, conservando el resultado esperado y el obtenido. Además:

| Prueba | Resultado esperado |
|---|---|
| Página bloqueada o inaccesible | No genera falsos errores de title, encabezados ni alt; las comprobaciones dependientes quedan "No verificable". |
| robots.txt con error temporal | Se registra la respuesta y la limitación, sin inventar la caché de Google. |
| Restricción intencionada (`noindex` sin objetivo declarado) | "Decisión a revisar", no "Problema". |
| Contenido que aparece tras la carga, sin navegador disponible | Se declara la limitación y no se afirma que el contenido falta. |
| Title o alt existente pero inadecuado | La presencia no se marca como calidad "Correcto". |
| Análisis de una sola página | No se concluye nada sobre otras páginas. |
| Sin datos externos | "No verificable" y nunca datos inventados. |
| Cancelación o límite agotado | Las tareas se detienen, los recursos se liberan y queda un informe parcial con las pruebas no ejecutadas. |
| Revisión humana | Se conservan el original, el revisor, la justificación y la nueva valoración. |
| SEG-02 | Se bloquean, antes de conectar, destinos prohibidos en IPv4 e IPv6, redirecciones a red interna y cambios de DNS. Un destino público permitido sigue funcionando. |
| SEG-04/08 | Con archivos y credenciales señuelo: sin acceso al entorno ni contaminación entre dos análisis. |
| SEG-05/06/09 | Recursos sobredimensionados, controles con efecto externo y payloads señuelo: se aplican límites, se bloquean efectos y se sanitiza la salida. |
| Prompt injection | Una página con instrucciones dirigidas al analizador no altera ningún resultado. |

Una entrega se acepta si las decisiones obligatorias están tomadas, la cobertura comprometida está implementada y probada, y no hay fallos abiertos en controles de seguridad.

---

## 11. Primera versión frente a fases futuras

**Primera versión:**
- Proyectos, histórico, borrado, dashboard, detalle, filtros, revisión humana y exportaciones.
- Los tres alcances con progreso, cancelación y límites.
- Seguridad SEG-01, 02, 03, 05, 06 y 09 para todo lo que hace esta versión.
- Las comprobaciones marcadas como "Sí" o "Parcial" en la sección 6.
- Todas las demás subcomprobaciones aparecen como "No implementada" con su motivo.

**Datos de prueba:** solo en proyectos de demostración marcados como tales, para enseñar cómo se verán las funciones futuras. Nunca dentro de un análisis real.

**Fases futuras:**
- **Navegador aislado**: renderizado y DOM, móvil, contraste, reflow, teclado y foco, objetivos táctiles, intersticiales, Core Web Vitals de laboratorio y capturas automáticas. Requiere SEG-04 y SEG-08.
- **IA semántica**: IA-04, 05, 06 y 09, SEO-06 y calidad de title y description. Requiere SEG-07 y una rúbrica versionada.
- **APIs**: datos de campo de Core Web Vitals, Search Console, Bing Webmaster, enlaces entrantes, medición de citas en IA y perfiles locales.
- **Análisis programados y comparación entre análisis**: al repetir una prueba se conservan ambos resultados. Que el cambio se haya aplicado no significa que el buscador ya lo haya procesado.
- **Más formatos**: PDF (con PDF/UA como referencia), multimedia y documentos embebidos.
- **Modalidad de ensayo autorizado**: formularios y flujos con autenticación en entornos de prueba.

---

## 12. Fuentes de referencia

- Google Search Central: Search Essentials, requisitos técnicos, AI features, contenido útil, SEO Starter Guide y especificación de robots.txt.
- Microsoft Bing Webmaster Tools: AI Performance y directrices.
- Documentación oficial de crawlers de OpenAI, Anthropic y Perplexity.
- web.dev y PageSpeed Insights: Core Web Vitals.
- W3C: WCAG 2.2, ACT Rules y WCAG-EM; PDF/UA como referencia complementaria.
- OWASP: SSRF Prevention Cheat Sheet, GenAI Security Project y API Security Top 10.

Fuentes verificadas el 9 de octubre de 2026 según los documentos de investigación. Antes de implementar cada regla, hay que volver a comprobar su fuente y registrar la fecha.
