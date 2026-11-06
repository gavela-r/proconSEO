# proconSEO

Herramienta web interna de Proconsi para analizar sitios web en tres bloques:

- preparación para búsquedas y respuestas con IA;
- SEO;
- accesibilidad (WCAG 2.2 A/AA).

Tecnología: PHP + MySQL. Sin login en la primera versión.

## Documentación de referencia

| Documento | Autor | Uso en el proyecto |
|---|---|---|
| Guía para el análisis web + Anexos (.md) | Amilivia | **Especificación técnica**: estados, evidencias, seguridad del analizador. Manda en caso de duda. |
| Investigación ejecutiva (Word) | José María | Contenido de cada criterio (explicaciones, recomendaciones, fichas WCAG). |
| Informe ejecutivo (PDF) | Adrián | Visión general y presentación. |

Decisiones ya tomadas: ver [docs/decisiones.md](docs/decisiones.md).

## Reparto del trabajo

| Parte | Responsable | Carpetas |
|---|---|---|
| 0. Base común (la prepara Amilivia y la revisan los tres; va primero) | **Amilivia** | `src/Shared/`, `db/`, `templates/layout/`, `public/` |
| 1. Lanzar el análisis de forma segura | **Amilivia** (@Amilivia) | `src/Fetch/`, `src/Run/`, `templates/run/`, `bin/` |
| 2. Comprobaciones IA, SEO y accesibilidad | **José María** (@josemariamerino20-cmd) | `src/Engine/`, `catalog/`, `templates/catalog/` |
| 3. Proyectos, resultados, revisión y exportación | **Adrián** (@gavela-r) | `src/Projects/`, `src/Results/`, `src/Review/`, `src/Export/`, `templates/projects/`, `templates/results/` |

Cada persona trabaja **solo en sus carpetas**. Las tareas detalladas están en las *issues* del repositorio.

Cómo trabajar con Git: ver [CONTRIBUTING.md](CONTRIBUTING.md).

Para empezar con Claude Code, cada persona tiene su prompt en [docs/prompts-inicio.md](docs/prompts-inicio.md).

## Arrancar en local

Requisitos: PHP 8.2 o superior (con curl, dom, intl, json, mbstring, openssl, pdo_mysql y zlib; gd es opcional), Composer y MySQL 8.

1. `composer install`
2. Crea la base de datos `proconseo` y un usuario con permisos sobre ella.
3. Copia `.env.example` como `.env` y rellena los datos de conexión.
4. `composer migrar` (o `composer demo` para cargar además los datos de demostración).
5. `composer comprobar`: dice si falta algo y cómo arreglarlo.
6. `composer servidor` y abre http://localhost:8090
7. En otra terminal, `composer worker`. Sin el worker, los análisis se quedan en cola y los programados no se lanzan.
8. Tests: `composer test`

Para instalarlo en el servidor de Proconsi (Windows, red local): [docs/despliegue.md](docs/despliegue.md). Costes, servidor necesario y tiempos: [docs/costes-y-rendimiento.md](docs/costes-y-rendimiento.md).

**Windows y HTTPS.** PHP en Windows no trae la lista de autoridades de certificación, así que rechaza todos los HTTPS (es lo seguro). Descarga `cacert.pem` de https://curl.se/ca/cacert.pem, comprueba su huella SHA-256 con https://curl.se/ca/cacert.pem.sha256 y apunta a él en `php.ini`:

```ini
curl.cainfo = "C:\ruta\a\cacert.pem"
openssl.cafile = "C:\ruta\a\cacert.pem"
```

Nunca desactives la verificación de certificados para que funcione.
