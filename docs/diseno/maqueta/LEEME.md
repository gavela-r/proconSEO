# Maqueta del diseño de proconSEO

Propuesta visual para toda la herramienta, antes de aplicarla al código. Son 4 pantallas enlazadas entre sí. Los datos son de ejemplo, inventados pero coherentes entre pantallas.

| Pantalla | Fichero |
|---|---|
| Inicio | `index.html` |
| Nuevo análisis | `nuevo.html` |
| Análisis en curso | `progreso.html` |
| Resultados | `resultados.html` |

## Cómo verla

Abre `index.html` con doble clic. Si prefieres verla servida, como en la herramienta:

```
php -S localhost:8092 -t docs/diseno/maqueta
```

y entra en http://localhost:8092.

## Decisiones de diseño

- **Estilo «informe de laboratorio»**: fondo blanco, tinta casi negra y líneas finas, sin sombras ni degradados ni modo oscuro. El rojo de Proconsi solo se usa como acento: logo, pestaña activa y números de sección.
- **Tipografías**: Atkinson Hyperlegible Next para el texto y Atkinson Hyperlegible Mono para los datos (URLs, códigos, horas y cifras). Las diseñó el Braille Institute para personas con baja visión. Licencia OFL, en `fuentes/`.
- **Nada se carga de internet**: tipografías, iconos y JavaScript son ficheros propios. Cumple la CSP `default-src 'self'` y SEG-10.
- **Estados siempre con icono y texto**, nunca solo con color (WCAG 1.4.1).
- **Elementos propios**: la línea de medición de las fases del análisis y la marca de color en el margen de cada causa.
- **Ayuda en contexto** («?» y términos subrayados con puntos), según WCAG 1.4.13: se abre con el ratón, el teclado o al tocar, y se cierra con Esc. Solo aparece donde explica algo que no es evidente (términos técnicos, cómo se ordenan las prioridades, el ámbito), no en los nombres de los estados.
- **Kit Digital es un tipo de proyecto más.** La pestaña «Compromisos Kit Digital» solo aparece en los proyectos de ese tipo, igual que en la herramienta. El ejemplo de resultados es una web de cliente normal.

Las mejoras de usabilidad salen del análisis de herramientas profesionales: [`../analisis-herramientas.md`](../analisis-herramientas.md). Son prioridades, ayuda en contexto, seguimiento, aceptar con motivo, responsable, nivel WCAG, aviso de plantilla y evidencia lista para copiar.

## Ficheros

- `estilos.css`: base visual común (colores, tipografía, botones, tablas, formularios y ayuda en contexto). Es la que se llevará a la herramienta.
- `paginas.css`: piezas propias de cada pantalla.
- `interaccion.js`: ayuda en contexto y botones de copiar, accesibles con teclado.
- `iconos.svg`: iconos propios. En las páginas van copiados dentro, para que se vean también al abrirlas con doble clic.
