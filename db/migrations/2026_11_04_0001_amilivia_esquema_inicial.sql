-- Esquema inicial de proconSEO (Parte 0).
-- Los valores de estado son los de src/Shared/Enum. Se guardan como VARCHAR para poder ampliarlos sin migrar.
-- Fechas en UTC.

CREATE TABLE proyectos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre          VARCHAR(200)  NOT NULL,
    cliente         VARCHAR(200)  NULL,
    url_principal   VARCHAR(2048) NULL,
    descripcion     TEXT          NULL,
    estado          VARCHAR(30)   NOT NULL DEFAULT 'activo',
    tipo            VARCHAR(30)   NULL,
    sector          VARCHAR(30)   NULL,
    es_dato_prueba  TINYINT(1)    NOT NULL DEFAULT 0,
    creado_en       DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    actualizado_en  DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    INDEX idx_proyectos_estado (estado),
    INDEX idx_proyectos_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Perfil de ejecución versionado (Anexo A.2). Inmutable: un cambio crea una versión nueva.
CREATE TABLE perfiles_ejecucion (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    version     VARCHAR(20) NOT NULL UNIQUE,
    parametros  JSON        NOT NULL,
    creado_en   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE analisis (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    proyecto_id           INT UNSIGNED  NOT NULL,
    url_introducida       VARCHAR(2048) NOT NULL,
    url_normalizada       VARCHAR(2048) NULL,
    url_final             VARCHAR(2048) NULL,
    alcance               VARCHAR(30)   NOT NULL,
    objetivo              TEXT          NULL,
    plataformas           JSON          NULL,
    hosts_autorizados     JSON          NULL,
    perfil_id             INT UNSIGNED  NOT NULL,
    version_catalogo      VARCHAR(20)   NULL,
    estado                VARCHAR(30)   NOT NULL DEFAULT 'en_cola',
    fase_actual           VARCHAR(30)   NULL,
    cancelacion_pedida    TINYINT(1)    NOT NULL DEFAULT 0,
    motivo_cierre         TEXT          NULL,
    configuracion_efectiva JSON         NULL,
    consumo               JSON          NULL,
    es_dato_prueba        TINYINT(1)    NOT NULL DEFAULT 0,
    creado_en             DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    iniciado_en           DATETIME(3)   NULL,
    terminado_en          DATETIME(3)   NULL,
    CONSTRAINT fk_analisis_proyecto FOREIGN KEY (proyecto_id) REFERENCES proyectos (id) ON DELETE CASCADE,
    CONSTRAINT fk_analisis_perfil   FOREIGN KEY (perfil_id)   REFERENCES perfiles_ejecucion (id),
    INDEX idx_analisis_proyecto (proyecto_id, creado_en),
    INDEX idx_analisis_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE fases_analisis (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    analisis_id   INT UNSIGNED NOT NULL,
    fase          VARCHAR(30)  NOT NULL,
    estado        VARCHAR(30)  NOT NULL DEFAULT 'pendiente',
    hechos        INT UNSIGNED NOT NULL DEFAULT 0,
    total         INT UNSIGNED NOT NULL DEFAULT 0,
    mensaje       VARCHAR(500) NULL,
    iniciada_en   DATETIME(3)  NULL,
    terminada_en  DATETIME(3)  NULL,
    CONSTRAINT fk_fases_analisis FOREIGN KEY (analisis_id) REFERENCES analisis (id) ON DELETE CASCADE,
    UNIQUE KEY uq_fase (analisis_id, fase)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Todo recurso consultado, incluidos los bloqueados o truncados (Anexo A.2).
CREATE TABLE recursos (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    analisis_id            INT UNSIGNED  NOT NULL,
    url                    VARCHAR(2048) NOT NULL,
    url_final              VARCHAR(2048) NULL,
    redirecciones          JSON          NULL,
    estado_http            SMALLINT      NULL,
    tipo_contenido         VARCHAR(255)  NULL,
    bytes                  INT UNSIGNED  NOT NULL DEFAULT 0,
    truncado               TINYINT(1)    NOT NULL DEFAULT 0,
    resultado              VARCHAR(30)   NOT NULL,
    motivo                 VARCHAR(500)  NULL,
    justificado_por        VARCHAR(100)  NULL,
    descargado_en          DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    CONSTRAINT fk_recursos_analisis FOREIGN KEY (analisis_id) REFERENCES analisis (id) ON DELETE CASCADE,
    INDEX idx_recursos_analisis (analisis_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE grupos_causa (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    analisis_id  INT UNSIGNED NOT NULL,
    codigo       VARCHAR(50)  NOT NULL,
    descripcion  TEXT         NOT NULL,
    CONSTRAINT fk_grupos_analisis FOREIGN KEY (analisis_id) REFERENCES analisis (id) ON DELETE CASCADE,
    UNIQUE KEY uq_grupo (analisis_id, codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Resultados originales. INMUTABLES: la aplicación nunca hace UPDATE sobre esta tabla;
-- cualquier cambio de valoración se registra en revisiones.
CREATE TABLE resultados (
    id                        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    analisis_id               INT UNSIGNED  NOT NULL,
    id_comprobacion           VARCHAR(100)  NOT NULL,
    id_criterio               VARCHAR(20)   NOT NULL,
    bloque                    VARCHAR(10)   NOT NULL,
    estado                    VARCHAR(30)   NOT NULL,
    ejecucion                 VARCHAR(30)   NOT NULL,
    cobertura                 VARCHAR(30)   NOT NULL,
    tipo_conclusion           VARCHAR(20)   NOT NULL,
    modo                      VARCHAR(20)   NULL,
    recurso_id                INT UNSIGNED  NULL,
    url_recurso               VARCHAR(2048) NULL,
    elemento                  TEXT          NULL,
    valor_observado           TEXT          NULL,
    consecuencia              TEXT          NULL,
    correccion                TEXT          NULL,
    correccion_cms            TEXT          NULL,
    explicacion_sencilla      TEXT          NOT NULL,
    limitacion                TEXT          NULL,
    requiere_revision_humana  TINYINT(1)    NOT NULL DEFAULT 0,
    motivo_revision           TEXT          NULL,
    wcag_criterio             VARCHAR(10)   NULL,
    wcag_nombre               VARCHAR(200)  NULL,
    wcag_nivel                VARCHAR(3)    NULL,
    wcag_version              VARCHAR(5)    NULL,
    gravedad                  VARCHAR(10)   NULL,
    esfuerzo                  VARCHAR(10)   NULL,
    grupo_causa_id            INT UNSIGNED  NULL,
    bloquea_dependientes      TINYINT(1)    NOT NULL DEFAULT 0,
    fuente                    VARCHAR(255)  NULL,
    version_regla             VARCHAR(20)   NOT NULL,
    es_dato_prueba            TINYINT(1)    NOT NULL DEFAULT 0,
    creado_en                 DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    CONSTRAINT fk_resultados_analisis FOREIGN KEY (analisis_id)    REFERENCES analisis (id)     ON DELETE CASCADE,
    CONSTRAINT fk_resultados_recurso  FOREIGN KEY (recurso_id)     REFERENCES recursos (id)     ON DELETE SET NULL,
    CONSTRAINT fk_resultados_grupo    FOREIGN KEY (grupo_causa_id) REFERENCES grupos_causa (id) ON DELETE SET NULL,
    CONSTRAINT ck_gravedad_solo_problema CHECK (gravedad IS NULL OR estado = 'problema'),
    INDEX idx_resultados_filtros (analisis_id, bloque, estado),
    INDEX idx_resultados_criterio (analisis_id, id_criterio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Revisiones humanas: SOLO INSERCIÓN. El estado vigente de un resultado es el nuevo_estado
-- de su última revisión con decision = 'cambiar_estado', o el original si no hay ninguna.
CREATE TABLE revisiones (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    resultado_id         INT UNSIGNED NOT NULL,
    revisor              VARCHAR(120) NOT NULL,
    motivo               TEXT         NOT NULL,
    decision             VARCHAR(30)  NOT NULL,
    nuevo_estado         VARCHAR(30)  NULL,
    justificacion        TEXT         NOT NULL,
    sustituye_a          INT UNSIGNED NULL,
    creado_en            DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    CONSTRAINT fk_revisiones_resultado FOREIGN KEY (resultado_id) REFERENCES resultados (id) ON DELETE CASCADE,
    CONSTRAINT fk_revisiones_anterior  FOREIGN KEY (sustituye_a)  REFERENCES revisiones (id) ON DELETE SET NULL,
    INDEX idx_revisiones_resultado (resultado_id, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Evidencias ya sanitizadas (SEG-09). Las que añade un revisor llevan revision_id.
CREATE TABLE evidencias (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    analisis_id    INT UNSIGNED  NOT NULL,
    codigo         VARCHAR(20)   NOT NULL,
    tipo           VARCHAR(30)   NOT NULL,
    contenido      MEDIUMTEXT    NULL,
    ruta_fichero   VARCHAR(500)  NULL,
    tipo_mime      VARCHAR(100)  NULL,
    recurso_id     INT UNSIGNED  NULL,
    url_recurso    VARCHAR(2048) NULL,
    truncado       TINYINT(1)    NOT NULL DEFAULT 0,
    revision_id    INT UNSIGNED  NULL,
    capturado_en   DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    CONSTRAINT fk_evidencias_analisis FOREIGN KEY (analisis_id) REFERENCES analisis (id)   ON DELETE CASCADE,
    CONSTRAINT fk_evidencias_recurso  FOREIGN KEY (recurso_id)  REFERENCES recursos (id)   ON DELETE SET NULL,
    CONSTRAINT fk_evidencias_revision FOREIGN KEY (revision_id) REFERENCES revisiones (id) ON DELETE CASCADE,
    UNIQUE KEY uq_evidencia (analisis_id, codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE resultado_evidencia (
    resultado_id  INT UNSIGNED NOT NULL,
    evidencia_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (resultado_id, evidencia_id),
    CONSTRAINT fk_re_resultado FOREIGN KEY (resultado_id) REFERENCES resultados (id) ON DELETE CASCADE,
    CONSTRAINT fk_re_evidencia FOREIGN KEY (evidencia_id) REFERENCES evidencias (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Registro de limitaciones y detenciones por seguridad (Guía 4).
CREATE TABLE eventos_seguridad (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    analisis_id  INT UNSIGNED  NOT NULL,
    fase         VARCHAR(30)   NULL,
    url_recurso  VARCHAR(2048) NULL,
    regla        VARCHAR(20)   NOT NULL,
    condicion    TEXT          NOT NULL,
    decision     VARCHAR(20)   NOT NULL,
    creado_en    DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    CONSTRAINT fk_eventos_analisis FOREIGN KEY (analisis_id) REFERENCES analisis (id) ON DELETE CASCADE,
    INDEX idx_eventos_analisis (analisis_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
