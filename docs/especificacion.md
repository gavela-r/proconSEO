# Especificación de requisitos — proconSEO

Herramienta web interna de Proconsi para analizar sitios web en tres bloques: **búsqueda con IA**, **SEO** y **accesibilidad**.

| | |
|---|---|
| Versión | 1.3 (seguridad de la propia herramienta, SEG-10 a SEG-19) |
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
| Usuarios | Cualquier persona de Proconsi, sin necesidad de ser experta en SEO o accesibilidad. Sin login en la primera versión. |
| Destino del informe | Uso interno en la primera versión. Más adelante, también una versión para entregar al cliente. |
| Tecnología | PHP + MySQL, desplegable en el servidor de la empresa. |
| Dispositivos | Responsive: móvil, tablet y escritorio. |
| Prioridad | Usabilidad y claridad antes que diseño visual avanzado. |
| Ampliación | Preparada para integrar APIs y servicios externos en el futuro. |
| Lenguaje | Cada resultado incluye una explicación sencilla, sin jerga, para quien no sea especialista. |

### 3.1 Para qué la usa Proconsi

Proconsi es una empresa de software de gestión de León (más de 120 profesionales y más de 5.500 clientes) con líneas para hidrocarburos, transporte, salud y alimentación, además de ciberseguridad, bots con IA y Kit Digital. Su web publica que **las webs y tiendas del Kit Digital las desarrolla su propio equipo** y que se comprometen a:

- web responsive con al menos 3 páginas y un gestor de contenidos;
- **accesibilidad WCAG 2.1 nivel AA**;
- SEO básico: palabras clave, SEO on-page en 2 páginas e indexación;
- preparada para un idioma además del castellano;
- tiendas con WooCommerce (básica) o PrestaShop (profesional) y al menos 100 productos.

Por eso la herramienta se centra en estos usos, de más a menos importante:

| Uso | Qué necesita de la herramienta |
|---|---|
| **1. Control de calidad de webs y tiendas del Kit Digital** antes de entregarlas y durante el periodo de mantenimiento | Comprobar lo que Proconsi promete: indexación, SEO on-page, accesibilidad WCAG 2.1 AA, responsive e idioma. Avisar de errores típicos de entrega, como el `noindex` de desarrollo olvidado. |
| **2. Diagnóstico de la web de un cliente o posible cliente** | Un informe claro que cualquier persona de Proconsi entienda y pueda explicar. |
| **3. Webs propias de Proconsi y de sus productos** (proconsi.com, GotelGest, Estafuel…) | Visibilidad en buscadores y en buscadores con IA para software B2B. |
| **4. Chatbots con IA que Proconsi instala en webs** | Comprobar que el chatbot es accesible (Guía, apartado "Interfaces con IA integrada"; ver 6.8). |

No se ha podido confirmar si hay clientes de administraciones públicas ni de transporte interurbano con venta online. Las comprobaciones específicas de esos casos quedan como condicionales (ver 6.8).

---

## 4. Requisitos funcionales

### 4.1 Proyectos

| ID | Requisito |
|---|---|
| RF-01 | Crear, editar, listar y buscar proyectos. |
| RF-02 | Un proyecto tiene nombre (obligatorio), cliente (opcional), URL principal, descripción o notas, estado y tipo (opcional). |
| RF-06 | Tipos de proyecto: **Web Kit Digital**, **Tienda Kit Digital**, **Web de cliente** (con sector opcional: hidrocarburos, transporte, salud, alimentación, otro), **Web propia de Proconsi** y **Otra**. El tipo activa las comprobaciones condicionales que le corresponden (ver 6.7 y 6.8). |
| RF-07 | Para los tipos Kit Digital, el dashboard muestra una vista **"Compromisos Kit Digital"** que agrupa los resultados relacionados con lo que Proconsi promete (ver 6.7). No es una nota: es la misma lista de resultados filtrada. |
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
| Gravedad | Crítico, Alto, Medio o Bajo. Solo si el estado es "Problema detectado" |
| Esfuerzo | Bajo, Medio o Alto. Lo hereda del catálogo del criterio; es orientativo |
| Fuente | Herramienta o fuente externa y su fecha, si el dato viene de fuera |
| Explicación sencilla | Qué pasa y por qué importa, en una o dos frases sin jerga |
| Cómo corregirlo en el CMS | Si se ha detectado WordPress, WooCommerce o PrestaShop, instrucciones concretas para esa plataforma |

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

### Niveles de gravedad

Solo para resultados en estado "Problema detectado". Las inferencias, hipótesis y mejoras no tienen gravedad.

| Nivel | Código | Cuándo se usa | Ejemplo |
|---|---|---|---|
| **Crítico** | `critico` | Impide el acceso, la indexación o una función esencial | `noindex` no intencionado en la página principal; error 500 persistente; formulario de contacto imposible de usar con teclado |
| **Alto** | `alto` | Barrera importante, aunque la página siga siendo usable | Imágenes informativas sin texto alternativo; contraste insuficiente en el texto principal |
| **Medio** | `medio` | Afecta a una parte concreta de la página o del sitio | Enlace interno roto en el pie de página; código hreflang mal escrito |
| **Bajo** | `bajo` | Detalle con un efecto pequeño | Cadena de dos redirecciones evitable |

### Esfuerzo

Estimación orientativa de cuánto cuesta corregir, fijada en el catálogo para cada criterio. Ayuda al cliente a planificar (primero lo crítico y barato). No es una puntuación.

| Nivel | Código | Significado |
|---|---|---|
| **Bajo** | `bajo` | Cambiar un texto, un atributo o una configuración puntual |
| **Medio** | `medio` | Modificar una plantilla o coordinar varias páginas |
| **Alto** | `alto` | Rehacer una sección, el diseño o crear contenido nuevo |

El "impacto" que propone la Investigación ejecutiva no se usa como campo propio: lo cubre "Consecuencia", que además está respaldada por evidencia.

---

## 6. Criterios de evaluación

La columna **Ref.** enlaza con la Investigación ejecutiva, donde está la explicación de cada criterio. **MVP** indica qué entra en la primera versión: **Sí** = se implementa; **Parcial** = solo lo comprobable sin navegador propio ni IA; **PSI** = se obtiene de la API de Google PageSpeed Insights; **No** = aparece como "No implementada".

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
| SEO-01 | Estado técnico, rastreabilidad e indexabilidad; HTTP/HTTPS y certificado básico; errores con HTTP 200 (posible soft 404). Incluye `SEO-01.contenido-mixto` (imágenes, scripts o estilos cargados por HTTP en una página HTTPS) y `SEO-01.formulario-sin-cifrar` (formularios que envían datos a una dirección HTTP). | T1, T2, T3, T8, T11 | Sí |
| SEO-02 | URL solicitada frente a final, cadenas y bucles de redirección, canonical y variantes. | T4, T6, T7 | Sí |
| SEO-03 | Title: presencia y descripción específica del propósito. La longitud es solo un dato auxiliar. | O5, O4 | Parcial (presencia) |
| SEO-04 | Meta description: presencia, marcado y coherencia. Sin longitud fija. | O6 | Parcial (presencia) |
| SEO-05 | Encabezados H1–H6 y estructura. Varios H1 o saltos de nivel no son errores automáticos. | O5 | Parcial |
| SEO-06 | Intención, suficiencia y valor de la página frente a lo que promete. Sin mínimos de palabras. | O1, O2, O3, C08, C13 | No (IA) |
| SEO-07 | Enlazado interno: enlaces rastreables, rotos y textos ambiguos. | O9, T5, T15 | Sí |
| SEO-08 | Duplicidad y variantes; solo es problema si hay una contradicción técnica observable. | T6 | Parcial |
| SEO-09 | Contenido en HTML inicial, en DOM automático o solo tras interacción. | T9 | No (navegador) |
| SEO-10 | Equivalencia de contenido y señales en móvil. | T10 | No (navegador) |
| SEO-11 | Core Web Vitals (ver 6.4). | V1–V4, C17 | PSI |
| SEO-12 | Imágenes y vídeo: descubrimiento, formato y contexto. Subcomprobaciones de vídeo solo si la página lo tiene (ver 6.5). | O7, O8 | Sí (imágenes) · Parcial (vídeo) |
| SEO-13 | Intersticiales, overlays y anuncios que tapan el contenido. | T12 | No (navegador) |
| SEO-14 | Datos estructurados y elegibilidad para resultados enriquecidos (reutiliza IA-07). Incluye el perfil de producto (ver 6.6). | T13 | Parcial |
| SEO-15 | Versiones por idioma: códigos y destinos hreflang y reciprocidad. Solo si la web tiene más de un idioma. | T14 | Sí |
| SEO-16 | Paginación, filtros y facetas, muestreando patrones sin generar combinaciones masivas. Poco relevante para las tiendas pequeñas del Kit Digital. | T16 | No (baja prioridad) |

Fuera del alcance de la URL (fase futura, con acceso autorizado o APIs): indexación real, canonical elegida por Google, acciones manuales, búsquedas, impresiones, clics y posiciones (M1–M4), enlaces entrantes y métricas de autoridad (E1–E3), y SEO local con Perfil de Empresa (O10).

Vídeos (O8) y páginas de producto no son criterios nuevos: se tratan como subcomprobaciones condicionales de criterios existentes (ver 6.5 y 6.6).

### 6.3 Accesibilidad (WCAG 2.2 A y AA)

Los 55 criterios A y AA se agrupan en 12 bloques. Cada incidencia indica el criterio WCAG exacto. 4.1.1 no se evalúa porque se eliminó en WCAG 2.2, y los criterios AAA nunca cuentan como incumplimiento.

**Referencia legal.** Lo que Proconsi promete en el Kit Digital, y lo que exige la norma UNE-EN 301 549 en la que se apoya la Ley 11/2023 de accesibilidad, es **WCAG 2.1 AA**. La herramienta evalúa WCAG 2.2, pero cada resultado indica si el criterio forma parte de WCAG 2.1 o es nuevo de 2.2. Los nuevos son **2.4.11, 2.5.7, 2.5.8, 3.2.6, 3.3.7 y 3.3.8**. Así, el informe sirve para comprobar el compromiso del Kit Digital sin mezclar requisitos que no se prometieron.

**Declaración de accesibilidad.** Se detecta si la web enlaza una página de declaración de accesibilidad. Si no la tiene, el resultado es "Decisión a revisar": es obligatoria en el sector público y recomendable en el resto.

| ID | Tema | Criterios WCAG | MVP |
|---|---|---|---|
| ACC-01 | Contenido no textual | 1.1.1 A | Parcial (falta de alt; la adecuación es humana) |
| ACC-02 | Audio, vídeo y multimedia | 1.2.1, 1.2.2, 1.2.3, 1.4.2 A · 1.2.4, 1.2.5 AA | Parcial (detección de medios y pistas) |
| ACC-03 | Estructura, relaciones y orden | 1.3.1, 1.3.2, 1.3.3, 2.4.2 A · 2.4.6 AA | Parcial (title, tablas, labels) |
| ACC-04 | Adaptación y presentación | 1.3.4, 1.4.4, 1.4.5, 1.4.10, 1.4.12, 1.4.13 AA | Parcial (zoom bloqueado en la meta viewport; el resto necesita navegador) |
| ACC-05 | Color y contraste | 1.4.1 A · 1.4.3, 1.4.11 AA | PSI (contraste de texto 1.4.3) |
| ACC-06 | Teclado, foco y navegación | 2.1.1, 2.1.2, 2.1.4, 2.4.1, 2.4.3, 2.4.4 A · 2.4.7, 2.4.11 AA | Parcial (nombres de enlace) |
| ACC-07 | Tiempo, movimiento y parpadeo | 2.2.1, 2.2.2, 2.3.1 A | Parcial (meta refresh con espera) |
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
- En el MVP, los datos vienen de la API de Google PageSpeed Insights. Cada resultado indica "Fuente: Google PageSpeed Insights", la fecha y si es dato de campo o de laboratorio.

### 6.5 Vídeo (subcomprobaciones de SEO-12)

Solo se activan si la página contiene vídeo. Su ausencia nunca es un problema.

| Subcomprobación | Qué comprueba | MVP |
|---|---|---|
| `SEO-12.video-detectado` | Hay vídeos (`<video>`, iframes de YouTube o Vimeo…) y cuál es el principal | Sí |
| `SEO-12.video-miniatura` | El vídeo tiene una imagen de presentación accesible | Parcial |
| `SEO-12.video-datos` | Si existe marcado `VideoObject`, es válido y coincide con el vídeo visible | Sí (sintaxis) |
| `SEO-12.video-pagina-dedicada` | Si el vídeo es el contenido principal, la página está dedicada a él | No (IA) |

La accesibilidad del vídeo (subtítulos, transcripción y audiodescripción) se evalúa en ACC-02, sin duplicarla aquí.

### 6.6 Perfil de producto (subcomprobaciones de SEO-14 e IA-06)

Solo se activan en páginas de producto: cuando hay marcado `Product` o cuando el usuario lo indica al lanzar el análisis.

| Subcomprobación | Qué comprueba | MVP |
|---|---|---|
| `SEO-14.producto-marcado` | El marcado `Product`/`Offer` es válido y tiene las propiedades necesarias | Sí |
| `SEO-14.producto-precio-coherente` | El precio del marcado coincide con el visible | No |
| `SEO-14.producto-disponibilidad` | La disponibilidad marcada coincide con la visible; un producto agotado no es un error por sí solo | No |
| `IA-06.producto-vigencia` | Precios, ofertas y condiciones no están vencidos ni se contradicen | No (IA) |

### 6.7 Compromisos Kit Digital (tipos Web Kit Digital y Tienda Kit Digital)

No son criterios nuevos: es una vista que agrupa resultados de criterios existentes según lo que Proconsi promete en su web del Kit Digital. Algunas subcomprobaciones son nuevas y van dentro del criterio que corresponde.

| Compromiso | Resultados que lo muestran | MVP |
|---|---|---|
| Indexación correcta | IA-02 y SEO-01, más `SEO-01.noindex-desarrollo`: detecta el `noindex` o el bloqueo en robots.txt que suele quedarse puesto al publicar la web (en WordPress: "Disuadir a los motores de búsqueda") → gravedad **crítico** si no es intencionado | Sí |
| SEO on-page | SEO-03, SEO-04, SEO-05 y SEO-12 | Sí |
| Al menos 3 páginas | `SEO-07.paginas-minimas`: páginas internas enlazadas y accesibles (alcance con contexto o sitio completo) | Sí |
| Responsive | `SEO-10.viewport`: meta viewport presente y válido; más el resultado de PageSpeed en móvil | Sí / PSI |
| Accesibilidad WCAG 2.1 AA | Todos los ACC, marcando los criterios de 2.1 (ver 6.3) | Parcial |
| Segundo idioma (si se contrató) | SEO-15 y ACC-10 (`lang` de cada versión) | Sí |
| Dominio y HTTPS | SEO-01 (certificado válido y redirección de HTTP a HTTPS) | Sí |
| Tienda: catálogo y producto | Perfil de producto (6.6) | Parcial |

Detección de CMS (`SEO-01.cms-detectado`): se identifican WordPress, WooCommerce y PrestaShop a partir de señales públicas, como la meta `generator` o las rutas típicas. Es informativa: sirve para dar instrucciones de corrección concretas para esa plataforma.

### 6.8 Comprobaciones según el sector (tipo Web de cliente)

Se activan solo si el proyecto tiene ese sector o si la página muestra las señales correspondientes.

| Sector | Subcomprobación | Qué comprueba | MVP |
|---|---|---|---|
| Todos con atención local (gasolineras, clínicas, tiendas físicas…) | `IA-08.datos-negocio-coherentes` | Nombre, dirección, teléfono y horarios coinciden entre las páginas analizadas | Sí |
| Todos con atención local | `IA-07.negocio-local` | Marcado `LocalBusiness` (o su tipo específico: `GasStation`, `MedicalClinic`…) válido y coherente con lo visible | Sí |
| Hidrocarburos | `IA-06.precios-carburante` | Si se publican precios, que tengan fecha y no se contradigan | No (IA) |
| Transporte | `ACC-09.formulario-billetes` | Formularios de búsqueda o compra de billetes con etiquetas e instrucciones accesibles | Parcial |
| Transporte | `IA-10.horarios-texto` | Horarios y tarifas disponibles como texto, no solo en imagen o PDF | Parcial |
| Webs con chatbot (de Proconsi o de otro proveedor) | `ACC-12.chatbot-detectado` | Se detecta el widget y se revisa su estado inicial: nombre del botón, rol y foco. La conversación queda para la fase del navegador | Parcial |

La coherencia con fuentes externas (Perfil de Empresa de Google y directorios) queda para la fase de APIs.

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

### 7.1 Seguridad de la propia herramienta

Proconsi es una empresa certificada en ENS e ISO 27001: la herramienta debe poder pasar una revisión de su propio equipo de ciberseguridad. Estos controles protegen la aplicación, el servidor y a terceros frente a un uso indebido.

| ID | Control | Responsable | Versión |
|---|---|---|---|
| SEG-10 | **Acceso solo desde la red interna o la VPN de Proconsi**, configurado en el servidor web (lista de IPs o red permitida). La aplicación no tiene login, así que nunca debe quedar expuesta a internet. Si algún día se publica, antes se añaden login y límites estrictos. | Parte 1 (configuración y documentación de despliegue) | MVP |
| SEG-11 | **Límites contra el abuso**: máximo de análisis por IP de origen y hora, y por dominio analizado y hora; registro de la IP de quien lanza cada análisis; interruptor general para detener todos los análisis en curso. | Parte 1 | MVP |
| SEG-12 | **Cortafuegos de salida** en el servidor: el proceso que analiza no puede conectarse a redes internas aunque falle el código. Es una segunda barrera, independiente de SEG-02. | Parte 1 (documentar la regla; la aplica sistemas) | Al desplegar |
| SEG-13 | **Mínimos privilegios**: el worker se ejecuta con un usuario del sistema sin privilegios, sin acceso a otros ficheros del servidor, y con un usuario de base de datos propio que solo tiene los permisos que necesita. | Parte 1 | Al desplegar |
| SEG-14 | **Ficheros descargados**: nunca se guardan ejecutables ni ficheros completos de la web analizada; se comprueba el tipo real, no la extensión. Las capturas subidas por revisores solo pueden ser PNG o JPG, se recodifican y se guardan fuera de `public/`. | Parte 1 (descargas) y Parte 3 (subidas) | MVP |
| SEG-15 | **Registro de auditoría**: quién lanzó cada análisis, cuándo, desde qué IP, qué se bloqueó y por qué. No se puede borrar desde la aplicación y se conserva durante un plazo definido. | Parte 1 | MVP |
| SEG-16 | **Dependencias y código**: Dependabot y escaneo de secretos activados en GitHub; `composer audit` y Psalm con análisis de flujo de datos (detecta inyección SQL y XSS) en cada PR. | Los tres (lo configura la Parte 1) | MVP |
| SEG-17 | **Claves de APIs**: solo en `.env`, nunca en el navegador ni en el repositorio. La clave de PageSpeed Insights se restringe en Google a esa API y a la IP del servidor. | Parte 1 | MVP |
| SEG-18 | **Despliegue**: HTTPS obligatorio, cookies `Secure`, cabecera HSTS y checklist de despliegue en `docs/despliegue.md`. | Parte 1 | Al desplegar |
| SEG-19 | **Validación final**: pentest del equipo de ciberseguridad de Proconsi antes de producción, tomando como guía OWASP ASVS nivel 1. | Los tres | Antes de producción |

Además, en todo el código: consultas SQL parametrizadas, protección CSRF en los formularios, escapado de toda salida HTML, cabeceras de seguridad (CSP, `nosniff`, `frame-ancestors`) y neutralización de fórmulas en las exportaciones a Excel/CSV.

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

### Valores iniciales (perfil v1)

Se ajustarán al probar en el servidor de Proconsi. Si un análisis llega a un límite, se cierra como "parcial" y se indica qué límite se alcanzó.

| Límite | Valor | Justificación |
|---|---|---|
| Páginas en "sitio completo" | 200 | Suficiente para webs de pymes; ampliable |
| URLs extra en "URL con contexto" | 20 | Solo las que necesita un criterio |
| Profundidad de rastreo | 5 clics desde la URL inicial | Habitual en webs pequeñas |
| Redirecciones seguidas | 10 | Googlebot sigue como máximo 10 |
| Tamaño máximo del HTML | 5 MB | Google lee hasta 15 MB; 5 MB cubre casi cualquier página y protege la memoria de PHP |
| Tamaño máximo de robots.txt | 500 KB | Límite que aplica Google |
| Tamaño máximo de un sitemap | 10 MB | El protocolo admite 50 MB; se trunca y se declara |
| Descarga total por análisis | 200 MB | Protege el servidor |
| Tiempo de conexión | 5 s | Evita esperar a servidores que no responden |
| Tiempo por petición | 15 s | Evita esperar a servidores colgados |
| Tiempo total del análisis | 2 min (URL individual y con contexto) · 30 min (sitio completo) | Al llegar al tope se cierra como "parcial" |
| Reintentos | 2, esperando 2 s y luego 4 s | Para fallos puntuales de red |
| Ritmo | 3 peticiones por segundo al mismo sitio (perfil v3; antes 1) | Para no saturar la web analizada. Con 1 por segundo, un sitio completo de 200 páginas pasaba de 30 minutos; las herramientas profesionales usan de 3 a 5 |
| Análisis a la vez | 2 | Protege el servidor de Proconsi |
| Puertos permitidos | 80 y 443 | Los de las webs normales |
| Idioma | `es-ES` | Clientes de Proconsi |
| User-agent | Propio e identificable (p. ej. `proconSEO/1.0`) | Transparencia ante la web analizada |
| Llamadas a PageSpeed Insights | 2 por URL (móvil y ordenador) | Las dos versiones que mide Google |

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
| `noindex` de desarrollo olvidado | Una web Kit Digital con `noindex` (o con robots.txt bloqueando todo) y sin objetivo declarado se marca como problema **crítico** en la vista "Compromisos Kit Digital". Con el objetivo "no indexar" declarado, es "Correcto". |
| Criterio nuevo de WCAG 2.2 | Un fallo en 2.5.8 aparece marcado como "nuevo en 2.2" y no se presenta como incumplimiento del compromiso WCAG 2.1 AA. |

Una entrega se acepta si las decisiones obligatorias están tomadas, la cobertura comprometida está implementada y probada, y no hay fallos abiertos en controles de seguridad.

---

## 11. Primera versión frente a fases futuras

**Primera versión:**
- Proyectos, histórico, borrado, dashboard, detalle, filtros, revisión humana y exportaciones.
- Los tres alcances con progreso, cancelación y límites.
- Seguridad SEG-01, 02, 03, 05, 06 y 09 para todo lo que hace esta versión.
- Las comprobaciones marcadas como "Sí" o "Parcial" en la sección 6: todo lo que se puede comprobar leyendo el HTML y las respuestas HTTP.
- **API de Google PageSpeed Insights**: Core Web Vitals (de campo y de laboratorio, en móvil y en ordenador) y contraste de texto, sin necesidad de un navegador propio. La clave de la API va en `.env`. Solo se le envía la URL pública analizada.
- **Enfoque Proconsi**: tipos de proyecto, vista "Compromisos Kit Digital", detección de CMS con instrucciones para WordPress, WooCommerce y PrestaShop, y las comprobaciones por sector marcadas en 6.7 y 6.8.
- Todas las demás subcomprobaciones aparecen como "No implementada" con su motivo.

Qué aporta frente a las herramientas profesionales (Screaming Frog, Semrush, Ahrefs, Lighthouse, axe, WAVE):

- **Sin notas engañosas**: no da un "95 sobre 100" que el cliente confunda con "todo está bien".
- **Honestidad**: muestra qué se ha comprobado, qué no y por qué.
- **Los tres bloques juntos**, con cada causa agrupada una sola vez.
- **Revisión humana con historial**, para que el consultor pueda defender el informe ante el cliente.
- **Preparación específica para buscadores con IA**, por ejemplo robots.txt para cada crawler de IA, algo que pocas herramientas revisan.

**Datos de prueba:** solo en proyectos de demostración marcados como tales, para enseñar cómo se verán las funciones futuras. Nunca dentro de un análisis real.

**Fases futuras:**
- **Navegador aislado**: renderizado y DOM, móvil, contraste, reflow, teclado y foco, objetivos táctiles, intersticiales, Core Web Vitals de laboratorio y capturas automáticas. Requiere SEG-04 y SEG-08.
- **IA semántica**: IA-04, 05, 06 y 09, SEO-06 y calidad de title y description. Requiere SEG-07 y una rúbrica versionada.
- **Más APIs**: Search Console, Bing Webmaster, enlaces entrantes, medición de citas en IA y perfiles locales.
- **Posible cuarto bloque "Ciberseguridad básica"**, solo pasivo y pendiente de decisión del equipo, porque la Guía deja la seguridad de la web analizada fuera del alcance. Incluiría enlaces a sitios maliciosos o de phishing (API de Google Web Risk o Safe Browsing), cabeceras de seguridad de la web y versiones de CMS o plugins desactualizadas visibles públicamente. Nunca se harían pruebas activas de vulnerabilidades sin autorización expresa del cliente, porque tiene riesgo legal. Serviría también como puente con el servicio de ciberseguridad de Proconsi.
- **Análisis programados y comparación entre análisis**: al repetir una prueba se conservan ambos resultados. Que el cambio se haya aplicado no significa que el buscador ya lo haya procesado.
- **Más formatos**: PDF (con PDF/UA como referencia), multimedia y documentos embebidos.
- **Informe para el cliente**: versión del PDF sin notas internas, con explicaciones sencillas y la imagen de Proconsi.
- **Modalidad de ensayo autorizado**: formularios y flujos con autenticación en entornos de prueba.

---

## 12. Fuentes de referencia

- Google Search Central: Search Essentials, requisitos técnicos, AI features, contenido útil, SEO Starter Guide y especificación de robots.txt.
- Microsoft Bing Webmaster Tools: AI Performance y directrices.
- Documentación oficial de crawlers de OpenAI, Anthropic y Perplexity.
- web.dev y PageSpeed Insights: Core Web Vitals.
- W3C: WCAG 2.2, ACT Rules y WCAG-EM; PDF/UA como referencia complementaria.
- OWASP: SSRF Prevention Cheat Sheet, GenAI Security Project y API Security Top 10.
- Proconsi: [Kit Digital – Sitio web](https://www.proconsi.com/kit-digital/web/), [Kit Digital – Comercio electrónico](https://www.proconsi.com/kit-digital/comercio-electronico/), [Bots conversacionales con IA](https://proconsi.com/bots-conversacionales-con-ia/) (consultadas el 4 de noviembre de 2026).
- Ley 11/2023 de accesibilidad y UNE-EN 301 549.

Fuentes verificadas el 9 de octubre de 2026 según los documentos de investigación. Antes de implementar cada regla, hay que volver a comprobar su fuente y registrar la fecha.
