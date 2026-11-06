# Costes y rendimiento

Cuánto cuesta proconSEO, qué servidor necesita y cuánto tarda. Lo marcado como **medido** se ha medido de verdad; lo marcado como **estimado** es un cálculo a partir de esas medidas, y conviene confirmarlo en el servidor de Proconsi.

## Coste

| Pieza | Licencia | Coste |
|---|---|---|
| PHP 8.3 | PHP License (libre) | 0 € |
| MySQL 8.0 Community | GPL (libre) | 0 € |
| IIS y URL Rewrite | Incluidos en Windows | 0 € |
| Composer y librerías (PHPUnit y Psalm, solo para desarrollo) | MIT y BSD (libres) | 0 € |
| API de Google PageSpeed Insights | Gratuita, con cuota diaria | 0 € |
| Windows | El servidor u ordenador de Proconsi que ya existe | 0 € más (ya está pagado) |
| **Total en licencias y servicios** | | **0 €** |

- La cuota gratuita de PageSpeed es de unas 25.000 consultas al día. Cada análisis usa 2 (móvil y ordenador), así que da para miles de análisis diarios. Si se agotara, la velocidad saldría como «no verificable» y el resto del análisis seguiría igual.
- No hay ninguna API de pago, ninguna suscripción ni ningún coste por análisis o por persona.
- El único coste real es el del servidor, que ya tiene Proconsi, y el tiempo de mantenimiento.

**Referencia (orientativa, precios públicos; comprobarlos antes de presentarlos):** las herramientas comerciales parecidas cuestan, por persona:

- Screaming Frog SEO Spider: unos 200 € al año, solo SEO;
- Semrush o Ahrefs: más de 1.300 € al año;
- de accesibilidad: aparte.

Ninguna junta en una sola herramienta los tres bloques: búsqueda con IA, SEO y accesibilidad.

## Servidor necesario

| Recurso | Mínimo | Por qué |
|---|---|---|
| Procesador | 2 núcleos | 2 análisis a la vez (perfil v3) y la web |
| Memoria | 4 GB | Windows + MySQL + 2 análisis. Cada análisis guarda como mucho 64 MB de páginas en memoria (estimado: menos de 256 MB por análisis) |
| Disco | 20 GB | Programas y base de datos. Un análisis no guarda páginas completas, solo resultados y fragmentos de evidencia |
| Red | Salida a internet por 80 y 443 | Para descargar las webs. Ninguna entrada desde internet |
| Navegador, Node, programas de pago | No hacen falta | |

## Tiempos

| Qué | Tiempo | Tipo |
|---|---|---|
| Motor de comprobaciones, por página (más de 170 comprobaciones) | 0,01 s | Medido en local |
| Google PageSpeed, móvil y ordenador a la vez | unos 21 s (antes, de uno en uno: unos 50 s) | Medido con proconsi.com |
| **URL individual** completa | menos de 1 minuto | Medido (lo que más tarda es PageSpeed) |
| **Sitio completo**, por página, versión inicial | unos 30 s | Medido con proconsi.com |
| Sitio completo, por página, sin repetir descargas | unos 12,5 s | Medido con proconsi.com |
| Sitio completo, por página, con 3 peticiones por segundo | unos 7–10 s | Medido con proconsi.com |
| Sitio completo con descargas en paralelo | 2,15 veces más rápido que el anterior, con los mismos resultados | Medido en local con una web de prueba de 11 páginas |
| **Sitio completo de 200 páginas** | unos 10–15 minutos | Estimado |

Cómo se ha conseguido:

1. Las mediciones de PageSpeed para móvil y ordenador se piden a la vez.
2. Lo ya descargado no se vuelve a pedir en cada página, con un tope de 64 MB de memoria.
3. Se hacen 3 peticiones por segundo a la web analizada, en vez de 1. Sigue siendo un ritmo respetuoso: las herramientas profesionales usan de 3 a 5.
4. Se descarga a la vez lo que cada página va a necesitar, sin saltarse ninguna comprobación de seguridad.

**Se comprobó que los resultados no cambian:** se analizó la misma web con la versión anterior y con la rápida, y las 1.181 filas de resultados salieron idénticas.

El análisis va en segundo plano: no hace falta quedarse mirando la pantalla. Los análisis programados se repiten solos y avisan si aparece algo grave.

## Límites que protegen el servidor y las webs analizadas

| Límite | Valor | Para qué |
|---|---|---|
| Páginas por sitio completo | 200 | Suficiente para webs de pymes |
| Tiempo máximo | 2 min (URL) · 30 min (sitio completo) | Al llegar al tope se cierra como «parcial», con lo obtenido |
| Ritmo | 3 peticiones por segundo al mismo sitio | No saturar la web analizada |
| Análisis a la vez | 2 | Proteger el servidor |
| Análisis por dominio y hora | 10 | Evitar abusos |
| Descarga total por análisis | 200 MB | Proteger el servidor |

Todos se pueden ajustar en el perfil de ejecución (`config/perfiles/`) sin tocar el código.
