# Informe PDF con la identidad de Proconsi

Referencia de diseño para el informe PDF (Parte 3, Adrián). Aplica el estilo de las presentaciones de Proconsi a un **documento A4 vertical**: es un informe, no una presentación.

- `ejemplo-informe.html`: la plantilla de ejemplo, con 5 páginas A4 y datos inventados.
- `ejemplo-informe.pdf`: cómo queda impresa.
- `logo-proconsi.png`: el logo, sacado de una presentación de Proconsi.

## Normas (las de las presentaciones de Proconsi)

| Elemento | Valor |
|---|---|
| Estilo | Suizo: fondo blanco, retícula, mucho espacio en blanco y jerarquía tipográfica clara |
| Página | A4 vertical, márgenes de 20 mm a los lados, 22 mm arriba y 32 mm abajo (deja sitio al logo) |
| Colores | Negro `#000000` para el texto; rojo `#e30613` para títulos, cifras y énfasis, con moderación; gris `#6c6c6c` para el texto secundario y las líneas |
| Tonos de apoyo (de las presentaciones) | Tarjetas `#f4f4f4`, líneas `#d6d6d6`, franja del mensaje `#fdecec` |
| Tipografía | Poppins: títulos en Bold 24 pt (36 pt en la portada), entradilla en Regular 11,5 pt (gris), subtítulos en SemiBold 13 pt, cifras en Bold 20 pt (rojo), etiquetas en Bold 11,5 pt y texto en Regular 10 pt |
| Cabecera de sección | Marca roja pequeña (14 × 1,3 mm), título en negro con la palabra clave en rojo y entradilla en gris |
| Tarjetas | Fondo `#f4f4f4`, esquinas redondeadas y relleno de 6 mm. Cifra roja, etiqueta negra, raya roja corta y texto gris |
| Mensaje principal | Franja `#fdecec` con un trazo rojo vertical, en negro con el énfasis en rojo |
| Logo | Proconsi, **abajo a la izquierda en todas las páginas**, 36 mm de ancho. Ningún otro logo |
| Datos de prueba | «DATOS DE PRUEBA» arriba a la derecha en cada página, si el proyecto es de demostración (RF-52) |

Siguen valiendo las reglas del informe: sin notas ni porcentajes, lo que no se pudo comprobar se dice, el término técnico va delante y hay un glosario al final.

## Para generarlo desde la herramienta

- **Fuente**: Poppins tiene licencia libre (SIL OFL). En la herramienta se guardan los `.ttf` en `public/assets/fuentes/` y se cargan con `@font-face`, sin Google Fonts: el servidor está en la red local y el PDF no debe depender de internet.
- **Paginación**: en el ejemplo cada página tiene altura fija. En la herramienta, las listas largas (fichas y anexo) deben poder pasar a la página siguiente con `break-inside: avoid` en cada ficha, y el logo va en el pie de cada página.
- **Motor**: este ejemplo usa *grid* y *flex*. Hay dos opciones:
  - **Microsoft Edge sin ventana** (`msedge --headless --print-to-pdf`). Ya viene en Windows, así que no se instala nada, y saca el resultado exacto de este ejemplo. Solo imprime HTML **generado por la propia herramienta**, con el contenido de la web analizada ya escapado: nunca abre la web del cliente. Hay que lanzarlo con un perfil temporal (`--user-data-dir`) y **poner entre comillas** las rutas con espacios.
  - **Dompdf** (PHP puro). Hay que rehacer la maqueta con tablas, porque Dompdf no entiende *grid*.
  
  Lo decide Adrián. Con el servidor en Windows, Edge es lo más sencillo para que quede igual que este ejemplo.
