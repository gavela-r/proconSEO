# Decisiones del equipo

## Tomadas

1. **Estados de resultado**: se usan los de la Guía (.md). Cada resultado tiene uno de estos seis:
   - `correcto` — Correcto
   - `mejorable` — Mejorable
   - `problema` — Problema detectado
   - `decision_revisar` — Decisión a revisar
   - `no_verificable` — No verificable
   - `no_aplicable` — No aplicable
2. **Sin notas ni puntuaciones globales**: ni por bloque ni por análisis. El resumen solo muestra cuántos resultados hay en cada estado.
3. **Gravedad**: solo en resultados "Problema detectado", con cuatro niveles: `critico`, `alto`, `medio`, `bajo`. No se usa la escala 1–5 del Word. Definición en la [especificación, sección 5](especificacion.md#5-modelo-de-resultado).
4. **Esfuerzo**: dato orientativo fijo de cada criterio en el catálogo (`bajo`, `medio`, `alto`). No se usa el campo "impacto": lo cubre "Consecuencia".
5. **Identificadores de criterio**: los de la Guía (`IA-01`, `SEO-03`, `ACC-06`...) con subcomprobaciones (`SEO-03.title-presente`).
6. **Todo en español**: estados, códigos, valores guardados en la base de datos y textos de la interfaz.
7. **Datos de prueba**: solo en proyectos de demostración, marcados como tales. En un análisis real, lo que depende de una API que aún no tenemos sale como "No verificable — pendiente de API".
8. **Primera versión (MVP)**: comprobaciones sobre HTML y HTTP más la API de Google PageSpeed Insights. Detalle en la [especificación, sección 11](especificacion.md#11-primera-versión-frente-a-fases-futuras).
9. **Límites iniciales**: los de la [especificación, sección 8](especificacion.md#8-perfil-de-ejecución). Se ajustarán al probar en el servidor real.
10. **Vídeos**: subcomprobaciones de SEO-12, activas solo si la página tiene vídeo.
11. **Páginas de producto**: perfil que solo se activa en páginas de producto (SEO-14 e IA-06). En el MVP, solo validar el marcado de producto.
12. **Enfoque Proconsi**: la herramienta se centra en controlar la calidad de las webs y tiendas del Kit Digital que hace Proconsi (WCAG 2.1 AA, SEO básico, indexación, responsive), en diagnosticar webs de clientes por sector, en las webs propias de Proconsi y en sus chatbots. Detalle en la [especificación, sección 3.1](especificacion.md#31-para-qué-la-usa-proconsi).
13. **Usuarios**: cualquier persona de Proconsi. Cada resultado lleva una explicación sencilla. El informe es interno en el MVP; la versión para cliente queda para más adelante.
14. **Seguridad de la propia herramienta** (SEG-10 a SEG-19): acceso solo desde la red interna o la VPN, límites contra el abuso, cortafuegos de salida, mínimos privilegios, registro de auditoría, control de dependencias y pentest antes de producción. Detalle en la [especificación, sección 7.1](especificacion.md#71-seguridad-de-la-propia-herramienta).
15. **Seguridad de las webs analizadas**: en el MVP solo contenido mixto y formularios sin cifrar, dentro de SEO-01. Un posible bloque de "Ciberseguridad básica" (solo pasivo) queda como fase futura pendiente de decisión.
16. **Borrado de análisis**: definitivo. Se pide confirmación antes de borrar, se ofrece exportar primero y no se puede borrar un análisis en curso. El borrado queda en el registro de auditoría (SEG-15), que no se borra con el análisis. Una papelera recuperable queda para más adelante si se echa en falta.
17. **Exportación a Excel**: en CSV (UTF-8 con BOM, separado por punto y coma para que Excel en español lo abra bien), con las fórmulas neutralizadas. No se genera `.xlsx`.

## Pendientes

- Versión de PHP y MySQL del servidor de Proconsi.
- Si el servidor permite procesos en segundo plano (cron o `php bin/worker.php`).
- Quién crea la clave de la API de PageSpeed Insights (cuenta de Google de Proconsi).
- Red o VPN desde la que se podrá acceder a la herramienta (SEG-10) y si sistemas puede aplicar el cortafuegos de salida (SEG-12).
- Plazo de conservación del registro de auditoría (SEG-15).
