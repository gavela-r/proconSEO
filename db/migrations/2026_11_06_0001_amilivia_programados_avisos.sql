-- Análisis programados y avisos (especificación, fases futuras: «Análisis programados y
-- comparación entre análisis»). Cambio en db/: lo aprueban los tres.

-- Una web que se vuelve a analizar sola cada cierto número de días.
CREATE TABLE programaciones (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    proyecto_id         INT UNSIGNED      NOT NULL,
    url                 VARCHAR(2048)     NOT NULL,
    alcance             VARCHAR(30)       NOT NULL,
    hosts_extra         VARCHAR(500)      NOT NULL DEFAULT '',
    cada_dias           SMALLINT UNSIGNED NOT NULL,
    siguiente_en        DATETIME(3)       NOT NULL,
    activa              TINYINT(1)        NOT NULL DEFAULT 1,
    ultimo_analisis_id  INT UNSIGNED      NULL,
    ultimo_error        TEXT              NULL,
    creado_en           DATETIME(3)       NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    CONSTRAINT fk_programaciones_proyecto FOREIGN KEY (proyecto_id) REFERENCES proyectos (id) ON DELETE CASCADE,
    CONSTRAINT fk_programaciones_analisis FOREIGN KEY (ultimo_analisis_id) REFERENCES analisis (id) ON DELETE SET NULL,
    INDEX idx_programaciones_pendientes (activa, siguiente_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Qué análisis lanzó una programación (NULL si lo lanzó una persona).
ALTER TABLE analisis
    ADD COLUMN programacion_id INT UNSIGNED NULL AFTER proyecto_id,
    ADD CONSTRAINT fk_analisis_programacion FOREIGN KEY (programacion_id) REFERENCES programaciones (id) ON DELETE SET NULL;

-- Avisos dentro de la herramienta cuando un análisis programado encuentra algo grave
-- (no hay servidor de correo). Se marcan como leídos; no se borran.
CREATE TABLE avisos (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    proyecto_id           INT UNSIGNED  NOT NULL,
    analisis_id           INT UNSIGNED  NOT NULL,
    analisis_anterior_id  INT UNSIGNED  NULL,
    tipo                  VARCHAR(30)   NOT NULL,
    titulo                VARCHAR(255)  NOT NULL,
    detalle               TEXT          NULL,
    leido_en              DATETIME(3)   NULL,
    creado_en             DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    CONSTRAINT fk_avisos_proyecto FOREIGN KEY (proyecto_id) REFERENCES proyectos (id) ON DELETE CASCADE,
    CONSTRAINT fk_avisos_analisis FOREIGN KEY (analisis_id) REFERENCES analisis (id) ON DELETE CASCADE,
    CONSTRAINT fk_avisos_anterior FOREIGN KEY (analisis_anterior_id) REFERENCES analisis (id) ON DELETE SET NULL,
    UNIQUE KEY uq_aviso (analisis_id, tipo),
    INDEX idx_avisos_sin_leer (leido_en, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
