-- Datos de demostración (es_dato_prueba = 1). NO son resultados de una web real.
-- Sirven para que la Parte 3 pueda trabajar sin esperar a las otras dos.
-- Se pueden cargar varias veces: primero borra la demostración anterior.

DELETE FROM proyectos WHERE es_dato_prueba = 1;

INSERT INTO proyectos (nombre, cliente, url_principal, descripcion, estado, tipo, sector, es_dato_prueba)
VALUES ('[DEMO] Web Kit Digital de una gasolinera', 'Cliente de ejemplo', 'https://gasolinera-ejemplo.test/',
        'Proyecto de demostración con resultados inventados.', 'activo', 'web_kit_digital', 'hidrocarburos', 1);
SET @proyecto = LAST_INSERT_ID();

INSERT INTO proyectos (nombre, cliente, url_principal, descripcion, estado, tipo, es_dato_prueba)
VALUES ('[DEMO] Proyecto interno sin cliente', NULL, 'https://interno-ejemplo.test/', 'Proyecto vacío de demostración.', 'en_pausa', 'otra', 1);

SET @perfil = (SELECT id FROM perfiles_ejecucion WHERE version = 'v1');

INSERT INTO analisis (proyecto_id, url_introducida, url_normalizada, url_final, alcance, objetivo, hosts_autorizados, perfil_id,
                      version_catalogo, estado, fase_actual, motivo_cierre, es_dato_prueba, iniciado_en, terminado_en)
VALUES (@proyecto, 'https://gasolinera-ejemplo.test', 'https://gasolinera-ejemplo.test/', 'https://gasolinera-ejemplo.test/',
        'url_individual', 'Aparecer en búsquedas locales', JSON_ARRAY('gasolinera-ejemplo.test'), @perfil,
        'v1', 'completado', 'cierre', NULL, 1, NOW(3) - INTERVAL 2 MINUTE, NOW(3) - INTERVAL 1 MINUTE);
SET @analisis = LAST_INSERT_ID();

INSERT INTO analisis (proyecto_id, url_introducida, url_normalizada, alcance, hosts_autorizados, perfil_id, version_catalogo,
                      estado, fase_actual, motivo_cierre, es_dato_prueba, iniciado_en, terminado_en)
VALUES (@proyecto, 'https://gasolinera-ejemplo.test', 'https://gasolinera-ejemplo.test/', 'sitio_completo', JSON_ARRAY('gasolinera-ejemplo.test'),
        @perfil, 'v1', 'cancelado', 'comprobaciones', 'Cancelado por el usuario. Se conservan los resultados obtenidos.', 1,
        NOW(3) - INTERVAL 1 DAY, NOW(3) - INTERVAL 1 DAY + INTERVAL 3 MINUTE);

INSERT INTO fases_analisis (analisis_id, fase, estado, hechos, total, iniciada_en, terminada_en) VALUES
    (@analisis, 'validacion',     'terminada', 1, 1, NOW(3) - INTERVAL 120 SECOND, NOW(3) - INTERVAL 119 SECOND),
    (@analisis, 'recuperacion',   'terminada', 2, 2, NOW(3) - INTERVAL 119 SECOND, NOW(3) - INTERVAL 110 SECOND),
    (@analisis, 'descubrimiento', 'omitida',   0, 0, NULL, NULL),
    (@analisis, 'renderizado',    'omitida',   0, 0, NULL, NULL),
    (@analisis, 'extraccion',     'terminada', 1, 1, NOW(3) - INTERVAL 110 SECOND, NOW(3) - INTERVAL 105 SECOND),
    (@analisis, 'comprobaciones', 'terminada', 8, 8, NOW(3) - INTERVAL 105 SECOND, NOW(3) - INTERVAL 65 SECOND),
    (@analisis, 'consolidacion',  'terminada', 1, 1, NOW(3) - INTERVAL 65 SECOND, NOW(3) - INTERVAL 62 SECOND),
    (@analisis, 'cierre',         'terminada', 1, 1, NOW(3) - INTERVAL 62 SECOND, NOW(3) - INTERVAL 60 SECOND);

INSERT INTO recursos (analisis_id, url, url_final, estado_http, tipo_contenido, bytes, resultado) VALUES
    (@analisis, 'https://gasolinera-ejemplo.test/', 'https://gasolinera-ejemplo.test/', 200, 'text/html; charset=utf-8', 48211, 'correcto');
SET @recurso = LAST_INSERT_ID();
INSERT INTO recursos (analisis_id, url, url_final, estado_http, tipo_contenido, bytes, resultado, justificado_por) VALUES
    (@analisis, 'https://gasolinera-ejemplo.test/robots.txt', 'https://gasolinera-ejemplo.test/robots.txt', 200, 'text/plain', 64, 'correcto', 'IA-01.robots-crawlers');

INSERT INTO grupos_causa (analisis_id, codigo, descripcion)
VALUES (@analisis, 'C-001', 'La web se publicó con la opción de WordPress que pide a los buscadores no indexarla.');
SET @grupo = LAST_INSERT_ID();

INSERT INTO evidencias (analisis_id, codigo, tipo, contenido, recurso_id, url_recurso) VALUES
    (@analisis, 'E-001', 'fragmento_html', '<meta name="robots" content="noindex, nofollow">', @recurso, 'https://gasolinera-ejemplo.test/'),
    (@analisis, 'E-002', 'fragmento_html', '<img src="/img/precios.jpg">', @recurso, 'https://gasolinera-ejemplo.test/'),
    (@analisis, 'E-003', 'fragmento_html', '<title>Inicio</title>', @recurso, 'https://gasolinera-ejemplo.test/'),
    (@analisis, 'E-004', 'texto', 'User-agent: *\nAllow: /', NULL, 'https://gasolinera-ejemplo.test/robots.txt'),
    (@analisis, 'E-005', 'fragmento_html', '<html lang="es">', @recurso, 'https://gasolinera-ejemplo.test/');

-- Un resultado de cada estado, con datos inventados.
INSERT INTO resultados (analisis_id, id_comprobacion, id_criterio, bloque, estado, ejecucion, cobertura, tipo_conclusion,
    recurso_id, url_recurso, elemento, valor_observado, consecuencia, correccion, correccion_cms, explicacion_sencilla, limitacion,
    gravedad, esfuerzo, grupo_causa_id, bloquea_dependientes, version_regla, es_dato_prueba) VALUES
(@analisis, 'SEO-01.noindex-desarrollo', 'SEO-01', 'seo', 'problema', 'ejecutada', 'implementada', 'hecho',
    @recurso, 'https://gasolinera-ejemplo.test/', 'meta robots', 'noindex, nofollow',
    'Google y Bing no mostrarán la web en sus resultados.', 'Quitar la directiva noindex de la página.',
    'En WordPress: Ajustes > Lectura > desmarcar "Disuadir a los motores de búsqueda de indexar este sitio".',
    'La web le dice a los buscadores que no la muestren. Suele ser un ajuste de desarrollo que se olvida quitar al publicar.',
    'No se ha declarado un objetivo de "no indexar"; si fuera intencionado, este resultado no aplica.',
    'critico', 'bajo', @grupo, 1, 'v1', 1),
(@analisis, 'IA-02.directivas-indexacion', 'IA-02', 'ia', 'problema', 'ejecutada', 'implementada', 'hecho',
    @recurso, 'https://gasolinera-ejemplo.test/', 'meta robots', 'noindex',
    'Los buscadores con IA que se apoyan en Google o Bing tampoco podrán usar la página como fuente.', NULL, NULL,
    'Por la misma causa, la página tampoco puede aparecer como fuente en respuestas de buscadores con IA.', NULL,
    'critico', 'bajo', @grupo, 0, 'v1', 1),
(@analisis, 'IA-01.robots-crawlers', 'IA-01', 'ia', 'correcto', 'ejecutada', 'implementada', 'hecho',
    NULL, 'https://gasolinera-ejemplo.test/robots.txt', 'robots.txt', 'User-agent: * / Allow: /', NULL, NULL, NULL,
    'robots.txt permite el acceso a Googlebot, bingbot, OAI-SearchBot, Claude-SearchBot y PerplexityBot.',
    'El permiso en robots.txt no garantiza que el servidor o un firewall no bloqueen el acceso.', NULL, NULL, NULL, 0, 'v1', 1),
(@analisis, 'SEO-03.title-calidad', 'SEO-03', 'seo', 'mejorable', 'ejecutada', 'implementada', 'inferencia',
    @recurso, 'https://gasolinera-ejemplo.test/', 'title', 'Inicio',
    'El título no describe la actividad ni la ubicación del negocio.', 'Usar un título como "Gasolinera Ejemplo en León · Precios y horarios".',
    'En WordPress: editar la página de inicio y cambiar el título SEO.',
    'El título de la página es genérico: no dice qué es el negocio ni dónde está.', 'Google puede mostrar otro título distinto.',
    NULL, 'bajo', NULL, 0, 'v1', 1),
(@analisis, 'IA-01.bloqueo-intencionado', 'IA-01', 'ia', 'decision_revisar', 'ejecutada', 'implementada', 'hecho',
    NULL, 'https://gasolinera-ejemplo.test/robots.txt', 'robots.txt', 'User-agent: GPTBot / Disallow: /', NULL, NULL, NULL,
    'Se bloquea el rastreador de entrenamiento de OpenAI. Puede ser intencionado: confirmar con el cliente.',
    'GPTBot es distinto de OAI-SearchBot: bloquearlo no impide aparecer en la búsqueda de ChatGPT.', NULL, NULL, NULL, 0, 'v1', 1),
(@analisis, 'IA-05.autoria-respaldo', 'IA-05', 'ia', 'no_verificable', 'no_ejecutada', 'no_implementada', 'hecho',
    NULL, NULL, NULL, NULL, NULL, NULL, NULL,
    'Esta comprobación todavía no está disponible en la herramienta.', 'Necesita análisis semántico con IA (fase futura).',
    NULL, NULL, NULL, 0, 'v1', 1),
(@analisis, 'SEO-15.hreflang', 'SEO-15', 'seo', 'no_aplicable', 'ejecutada', 'implementada', 'hecho',
    @recurso, 'https://gasolinera-ejemplo.test/', NULL, NULL, NULL, NULL, NULL,
    'La web solo tiene versión en castellano, así que no necesita indicar otros idiomas.', NULL, NULL, NULL, NULL, 0, 'v1', 1);

INSERT INTO resultados (analisis_id, id_comprobacion, id_criterio, bloque, estado, ejecucion, cobertura, tipo_conclusion, modo,
    recurso_id, url_recurso, elemento, valor_observado, consecuencia, correccion, explicacion_sencilla, limitacion,
    requiere_revision_humana, motivo_revision, wcag_criterio, wcag_nombre, wcag_nivel, wcag_version, gravedad, esfuerzo, version_regla, es_dato_prueba) VALUES
(@analisis, 'ACC-01.img-alt-presente', 'ACC-01', 'acc', 'problema', 'ejecutada', 'implementada', 'hecho', 'automatico',
    @recurso, 'https://gasolinera-ejemplo.test/', 'img[src="/img/precios.jpg"]', 'sin atributo alt',
    'Un lector de pantalla no puede leer los precios de los carburantes.', 'Añadir un texto alternativo con los precios o publicarlos como texto.',
    'La imagen con los precios no tiene texto alternativo: una persona ciega no puede saber los precios.', NULL,
    0, NULL, '1.1.1', 'Contenido no textual', 'A', '2.1', 'alto', 'bajo', 'v1', 1),
(@analisis, 'ACC-10.lang-documento', 'ACC-10', 'acc', 'correcto', 'ejecutada', 'implementada', 'hecho', 'automatico',
    @recurso, 'https://gasolinera-ejemplo.test/', 'html[lang]', 'es', NULL, NULL,
    'La página indica que está en español, así que los lectores de pantalla la pronuncian bien.', NULL,
    0, NULL, '3.1.1', 'Idioma de la página', 'A', '2.1', NULL, NULL, 'v1', 1),
(@analisis, 'ACC-01.img-alt-adecuado', 'ACC-01', 'acc', 'no_verificable', 'ejecutada', 'implementada', 'inferencia', 'humano',
    @recurso, 'https://gasolinera-ejemplo.test/', 'img[alt="logo"]', 'logo', NULL, NULL,
    'El logotipo tiene texto alternativo, pero hay que comprobar si describe bien la imagen.', 'Que exista un texto alternativo no demuestra que sea adecuado.',
    1, 'Valorar si "logo" identifica a la empresa o debería ser su nombre.', '1.1.1', 'Contenido no textual', 'A', '2.1', NULL, 'bajo', 'v1', 1);

INSERT INTO resultado_evidencia (resultado_id, evidencia_id)
SELECT r.id, e.id FROM resultados r JOIN evidencias e ON e.analisis_id = r.analisis_id
WHERE r.analisis_id = @analisis AND (
       (r.id_comprobacion IN ('SEO-01.noindex-desarrollo', 'IA-02.directivas-indexacion') AND e.codigo = 'E-001')
    OR (r.id_comprobacion = 'ACC-01.img-alt-presente' AND e.codigo = 'E-002')
    OR (r.id_comprobacion = 'SEO-03.title-calidad' AND e.codigo = 'E-003')
    OR (r.id_comprobacion IN ('IA-01.robots-crawlers', 'IA-01.bloqueo-intencionado') AND e.codigo = 'E-004')
    OR (r.id_comprobacion = 'ACC-10.lang-documento' AND e.codigo = 'E-005'));

-- Una revisión de ejemplo: el resultado original no cambia, la revisión añade la nueva valoración.
INSERT INTO revisiones (resultado_id, revisor, motivo, decision, nuevo_estado, justificacion)
SELECT id, 'Revisor de ejemplo', 'Confirmar con el cliente el bloqueo de GPTBot', 'cambiar_estado', 'correcto',
       'El cliente confirma que bloquea GPTBot a propósito y no quiere que se use su contenido para entrenar modelos.'
FROM resultados WHERE analisis_id = @analisis AND id_comprobacion = 'IA-01.bloqueo-intencionado';
SET @revision = LAST_INSERT_ID();

-- Pasar a "correcto" exige evidencia añadida por el revisor.
INSERT INTO evidencias (analisis_id, codigo, tipo, contenido, revision_id)
VALUES (@analisis, 'E-006', 'texto', 'Correo de ejemplo del cliente: "Bloqueamos GPTBot de forma intencionada."', @revision);

INSERT INTO eventos_seguridad (analisis_id, fase, url_recurso, regla, condicion, decision)
VALUES (@analisis, 'recuperacion', 'https://gasolinera-ejemplo.test/', 'SEG-03', 'Ejemplo: la web también responde por HTTP sin cifrar; se usa la versión HTTPS.', 'continuar');
