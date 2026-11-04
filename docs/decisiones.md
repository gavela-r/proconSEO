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
3. **Gravedad**: solo se asigna a resultados en estado "Problema detectado", como dice la Guía. No se usa la escala 1–5 del Word.
4. **Identificadores de criterio**: los de la Guía (`IA-01`, `SEO-03`, `ACC-06`...) con subcomprobaciones (`SEO-03.title-presente`).
5. **Todo en español**: estados, códigos, valores guardados en la base de datos y textos de la interfaz.
6. **Datos de prueba**: solo en proyectos de demostración, marcados como tales. En un análisis real, lo que depende de una API que aún no tenemos sale como "No verificable — pendiente de API".

## Pendientes

- Versión de PHP y MySQL del servidor de Proconsi.
- Si el servidor permite procesos en segundo plano (cron o `php bin/worker.php`).
- Límites del análisis: páginas máximas, tiempo, tamaño de descarga, puertos permitidos.
- Qué comprobaciones entran en la primera versión.
- Borrado de análisis: definitivo o recuperable.
- Excel: `.xlsx` o CSV.
