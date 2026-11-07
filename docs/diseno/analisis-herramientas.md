# Qué hacen las herramientas profesionales y qué debería tener proconSEO

Análisis de cómo presentan y gestionan los resultados las herramientas de auditoría más usadas, para decidir qué incorporar al rediseño de proconSEO. Fecha: 7 de noviembre de 2026.

Se han revisado:

- **SEO**: Sitebulb, Screaming Frog, Ahrefs Site Audit, Semrush Site Audit y Google Search Console.
- **Accesibilidad**: axe DevTools (Deque), WAVE (WebAIM), Siteimprove, Silktide y Lighthouse.
- **Normas de usabilidad accesible**: WCAG 2.2.

---

## 1. Lo que hacen todas (patrones comunes)

| Patrón | Quién lo hace | Cómo |
|---|---|---|
| **Prioridad en niveles** | Todas | Sitebulb: Crítico, Alto, Medio y Bajo. Screaming Frog: Alta, Media y Baja. Ahrefs: errores, avisos y notas. axe: crítico, grave, moderado y menor. |
| **Separar «fallo seguro» de «posible fallo»** | Screaming Frog, WAVE, axe, Siteimprove | Screaming Frog distingue *issues*, *warnings* y *opportunities*. WAVE, errores (rojo) y alertas (amarillo). axe tiene *Needs review* y Siteimprove, *Potential issues*, que una persona debe confirmar. |
| **Explicación de cada hallazgo** | Sitebulb, Semrush, WAVE | Cada hallazgo tiene una ficha: qué significa, por qué importa y cómo arreglarlo. Semrush tiene «Why and how to fix it» y WAVE, un panel *Reference*. |
| **Comparar con el análisis anterior** | Ahrefs, Semrush | Nuevos, arreglados y empeorados entre dos rastreos, a elegir. |
| **Comprobar que un arreglo funciona** | Google Search Console | Con «Validar corrección», el estado pasa por «No iniciada», «Iniciada», «Superada» y «No superada». Solo se vuelven a comprobar las URLs afectadas. |
| **Ocultar o aceptar un hallazgo** | Semrush | Se oculta un problema o una página y se puede restaurar después desde la pestaña «Ocultos». |
| **Filtrar por responsable** | Siteimprove | Cada problema tiene responsable: contenido, desarrollo, diseño UX o diseño visual. Cada persona ve solo lo suyo. |
| **Filtrar por nivel WCAG** | Siteimprove, axe | A, AA, AAA y buenas prácticas. |
| **Señalar el elemento en la página** | WAVE, Silktide | Iconos sobre la página real o un recuadro rojo en el elemento, con su HTML al lado. |
| **Lista de comprobaciones manuales** | Lighthouse, axe | Lighthouse muestra «Elementos adicionales que comprobar manualmente». axe Pro tiene pruebas guiadas paso a paso de teclado, foco y contraste. |
| **Agrupar lo repetido** | Rocket Validator, investigación del W3C | Un fallo de plantilla se cuenta una vez, con el número de páginas afectadas: «arréglalo una vez». |
| **Enviar a tareas** | Semrush | Crea una tarjeta de Trello con el enlace al hallazgo. |

## 2. Lo que proconSEO ya hace mejor (hay que conservarlo y enseñarlo)

- **Sin nota ni porcentaje.** Ahrefs y Semrush dan un «Health Score» de 0 a 100, y Lighthouse, una puntuación. WAVE advierte de que no tener errores no significa ser accesible. Nosotros no damos una cifra engañosa.
- **«No verificable» con su motivo**, en vez de dar por bueno lo que no se ha comprobado.
- **Agrupación por causa entre bloques**: un mismo fallo afecta a IA, SEO y accesibilidad y se muestra una vez. Ninguna de las herramientas revisadas junta los tres bloques.
- **Revisión humana con historial**, donde el hallazgo original nunca se edita.
- **«Decisión a revisar»**: para lo que puede ser intencionado, como un `noindex` o bloquear GPTBot. Las demás herramientas lo marcan como error o lo ignoran.
- **Vista «Compromisos Kit Digital»** y comparación con cualquier análisis del historial.

## 3. Recomendaciones

### Imprescindibles (alto impacto, coste bajo o medio)

1. **Textos emergentes accesibles («toggletips»).** Es tu idea del texto al pasar el ratón, hecha bien. Junto a cada estado, término técnico o icono va un pequeño botón «?»:
   - **Se abre** al pasar el ratón, al llegar con el teclado o al tocar en el móvil.
   - **Se cierra** con Esc.
   - **No desaparece** si mueves el ratón encima.

   Es lo que exige WCAG 1.4.13. No se usa el atributo `title` del navegador, que no se ve con teclado ni en el móvil. El glosario actual lo usa, así que hay que cambiarlo. El texto visible (icono + palabra) se queda siempre: lo emergente es una ayuda extra, nunca la única forma de saber qué es.
2. **Ficha «Por qué importa y cómo arreglarlo»** en cada resultado, plegable. Tiene tres partes:
   - qué es, en dos líneas;
   - a quién afecta (personas, Google, asistentes de IA);
   - cómo se arregla, con un ejemplo, y la fuente oficial.

   Es el patrón de Sitebulb y Semrush. El catálogo ya tiene casi todos estos datos.
3. **«Por dónde empezar».** Arriba de los resultados, los 5 primeros que conviene abordar, ordenados por gravedad, número de páginas afectadas y esfuerzo. Debajo, un apartado de «Arreglos rápidos»: impacto alto y esfuerzo bajo.
4. **Seguimiento de cada causa.** El estado de trabajo pasa por «Pendiente», «En curso», «Corregido» y «Comprobado». «Comprobado» lo pone el siguiente análisis si confirma que ya no aparece. Es el flujo de Search Console, adaptado y sin tocar el hallazgo original. Así se ve el progreso de un cliente entre análisis.
5. **Aceptar un hallazgo con motivo.** Equivale al «ocultar» de Semrush, pero mejor: exige quién y por qué («el cliente quiere bloquear GPTBot»). Se puede deshacer, y en los análisis siguientes no vuelve a molestar. Es una revisión más, así que reutiliza lo que ya existe.
6. **Responsable.** Cada comprobación lleva quién suele arreglarla: contenido, desarrollo o diseño. Filtrar por responsable sirve para pasarle a cada persona solo lo suyo.
7. **Filtro por nivel WCAG** (A o AA) y etiqueta «Nuevo en 2.2». Lo pide la sección 10 de la especificación.
8. **Repetido en plantilla.** Si un fallo aparece en muchas páginas con el mismo patrón, se dice claramente: «Se repite en 40 páginas: probablemente es la plantilla. Arreglándolo una vez se corrige en todas».
9. **Evidencia que se pueda usar.** Fragmento de HTML con la línea resaltada, selector CSS y botón «Copiar», para pasarlo al programador tal cual.

### Muy recomendables

10. **Comprobaciones manuales guiadas** para lo que sale «No verificable»: pasos cortos del tipo «Pulsa Tab: ¿ves en todo momento dónde está el foco?». El resultado se guarda como una revisión. Es lo que hacen Lighthouse y las pruebas guiadas de axe.
11. **Copiar como tarea.** Un botón genera el texto de la tarea (título, qué, dónde y cómo) para pegarlo en Jira, Trello o un correo. Sin conectarse a servicios externos, por la seguridad de la herramienta.
12. **Filtros en la dirección (URL).** Un enlace con los filtros puestos se puede compartir: «mira solo los problemas de accesibilidad de desarrollo».
13. **Ordenar las tablas por columna** y mantener la cabecera visible al bajar.
14. **Búsqueda global** en la cabecera: proyectos, URLs y criterios.

### Cuando esté el navegador aislado

15. **Captura con el elemento recuadrado**, como Silktide o WAVE, para ver dónde está el fallo sin abrir el código.
16. **Simular cómo la ve una persona**: a 320 px, con zoom del 200 % o con el orden del foco numerado.

### Lo que NO conviene copiar

- **Una puntuación global** (Health Score). Contradice la especificación y engaña.
- **Prometer «100 % accesible»** porque no haya errores automáticos.
- **Gráficos de anillo y paneles llenos de cifras**: pocos datos, mucho adorno.
- **Integraciones que envían datos a terceros** (Trello, Slack…) desde la herramienta: chocan con SEG-10 y SEG-17. Basta con copiar el texto.

## 4. Usabilidad y accesibilidad de la propia herramienta

Siendo una herramienta que juzga la accesibilidad de otros, tiene que cumplir WCAG 2.2 AA ella misma. Para la exposición es un argumento fuerte.

- Todo se puede usar con teclado, con foco siempre visible y un enlace «Saltar al contenido».
- Los estados nunca se indican solo con color (1.4.1): siempre icono y texto.
- El contraste del texto es de al menos 4,5:1, y los controles miden como mínimo 24 × 24 px (2.5.8). Los botones principales, 44 px.
- El progreso del análisis se anuncia al lector de pantalla con `aria-live` (4.1.3).
- Funciona con zoom del 200 % y a 320 px de ancho, sin desplazamiento horizontal (1.4.10).
- Textos emergentes según 1.4.13: se pueden cerrar, mantener y recorrer con el ratón.
- Títulos de los resultados en lenguaje de acción («La página principal pide no aparecer en buscadores»), con el término técnico al lado y enlazado al glosario.
- Mensajes vacíos útiles: «Aún no hay análisis: lanza el primero».
- Confirmación antes de cualquier acción irreversible, ofreciendo exportar antes de borrar (ya está).

## 5. Fuentes

- Sitebulb: [Hints](https://support.sitebulb.com/en/articles/9854034-hints), [Prioritized hints](https://sitebulb.com/features/prioritized-hints/)
- Screaming Frog: [Issues](https://www.screamingfrog.co.uk/seo-spider/issues/), [Quick start guide](https://www.screamingfrog.co.uk/quick-start-guide/)
- Ahrefs: [Comparar rastreos](https://help.ahrefs.com/en/articles/5192078-how-to-compare-crawl-changes-in-site-audit), [Site Audit overview](https://ahrefs.com/academy/how-to-use-ahrefs/site-audit/sa-overview)
- Semrush: [Issues report](https://nl.semrush.com/kb/541-site-audit-issues-report), [Trello](https://de.semrush.com/kb/845-trello-and-semrush), [Compare crawls](https://tr.semrush.com/kb/545-site-audit-compare-crawls-progress)
- Google Search Console: [Validación de correcciones](https://support.google.com/webmasters/answer/9216203)
- axe DevTools: [Deque docs](https://docs.deque.com/devtools-for-web/4/en/wa-js-instructions)
- WAVE: [Ayuda de WebAIM](https://wave.webaim.org/help)
- Siteimprove: [Filtros de problemas](https://help.siteimprove.com/support/solutions/articles/80000448547-accessibility-working-with-issue-filters)
- Silktide: [Inspector](https://help.silktide.com/en/articles/8011882-using-the-inspector)
- Lighthouse: [Explicación de DebugBear](https://www.debugbear.com/blog/lighthouse-accessibility), [Novedades de Lighthouse 11](https://developer.chrome.com/blog/lighthouse-11-0)
- Agrupar por plantilla: [Rocket Validator](https://docs.rocketvalidator.com/summary-reports/), [W3C WAI](https://www.w3.org/WAI/RD/2011/metrics/paper3)
- WCAG 2.2: [1.4.13 Contenido al pasar el ratón o recibir el foco](https://www.w3.org/WAI/WCAG22/Understanding/content-on-hover-or-focus)
