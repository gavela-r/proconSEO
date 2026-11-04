# Catálogo de criterios v1

Lista de todo lo que proconSEO revisa: **39 criterios** (IA-01..11, SEO-01..16, ACC-01..12), cada uno con sus **subcomprobaciones**. Sigue la [especificación v1.2](../../docs/especificacion.md), sección 6.

- `catalogo.json`: versión del catálogo y significado de cada valor permitido.
- `criterios/<ID>.json`: un fichero por criterio.

Cada análisis guarda la versión del catálogo que usó (RF-61). **Un fichero de una versión ya publicada no se cambia**: los cambios van a una versión nueva (`catalog/v2/`) para no alterar análisis anteriores (RNF-04).

## Campos de un criterio

| Campo | Qué es |
|---|---|
| `id`, `bloque`, `nombre` | Identificador de la Guía (`SEO-03`), bloque (`IA`, `SEO`, `ACC`) y nombre |
| `que_comprueba`, `cuando_aplica` | Descripción y condición de aplicación |
| `explicacion_sencilla` | Qué es y por qué importa, sin jerga, para cualquier persona de Proconsi |
| `modo` | `automatico`, `hibrido` o `humano` (se deduce de sus subcomprobaciones) |
| `esfuerzo` | Esfuerzo orientativo de corrección: `bajo`, `medio` o `alto` |
| `mvp` | `si`, `parcial`, `psi` o `no` (columna MVP de la especificación) |
| `ref_investigacion` | Referencias a la Investigación ejecutiva (C02, O5, T1…) |
| `reutiliza` | Otros criterios cuyos datos aprovecha |
| `fuentes` | Fuentes oficiales; `fecha_verificacion` se rellena al comprobarlas |
| `wcag` | Solo en ACC: criterios WCAG con número, nombre, nivel, `en_wcag_2_1` (falso = nuevo en 2.2) y ficha de la Investigación |
| `umbrales` | Valores normativos cuando los hay (Core Web Vitals, contraste…) |
| `subcomprobaciones` | Lista de subcomprobaciones (abajo) |

## Campos de una subcomprobación

| Campo | Qué es |
|---|---|
| `id` | `CRITERIO.nombre-corto`, estable (`SEO-03.title-presente`) |
| `nombre`, `que_comprueba`, `cuando_aplica` | Descripción |
| `condicion` | Código de activación (`video`, `producto`, `kit_digital`, `sector_transporte`…) o `null` si siempre aplica |
| `modo`, `mvp` | Como en el criterio |
| `motivo_no_mvp` | Si `mvp` es `no`: `navegador`, `ia`, `humano` o `baja_prioridad` |
| `necesita` | Qué datos hacen falta (`pagina`, `robots_txt`, `otras_urls`, `psi`…). Si falta alguno, el resultado es `no_verificable` |
| `alcance_minimo` | Alcance mínimo con el que se puede ejecutar |
| `wcag` | Criterios WCAG que cubre (solo ACC y alguna de IA/SEO relacionada) |
| `kit_digital` | Compromisos Kit Digital en los que aparece (vista RF-07) |
| `gravedad_si_problema` | Solo cuando la especificación fija la gravedad (p. ej. `SEO-01.noindex-desarrollo` → `critico`) |

Toda subcomprobación devuelve un resultado; las no implementadas, con cobertura `no_implementada`.
