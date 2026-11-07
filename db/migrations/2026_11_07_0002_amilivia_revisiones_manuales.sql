-- Revisiones manuales de los criterios WCAG en un análisis (lo que la herramienta no comprueba sola).
-- Cambio en db/: lo aprueban los tres.
--
-- Igual que las revisiones y los seguimientos, nunca se edita: cada revisión es una fila nueva con
-- quién, cuándo, qué páginas miró y qué vio. La vigente es la última de su (análisis, criterio).
-- Solo usa los estados de resultado de siempre: correcto, problema o no_aplicable. No cambia
-- ningún resultado automático (RF-42): se muestra al lado, en la vista de criterios WCAG.

CREATE TABLE revisiones_manuales (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    analisis_id      INT UNSIGNED NOT NULL,
    criterio_wcag    VARCHAR(10)  NOT NULL,   -- p. ej. 2.1.1
    resultado        VARCHAR(20)  NOT NULL,   -- correcto | problema | no_aplicable
    revisor          VARCHAR(120) NOT NULL,   -- no hay login: el nombre que escribe la persona
    paginas          TEXT         NULL,       -- qué páginas o recorridos se revisaron
    notas            TEXT         NOT NULL,   -- qué se hizo y qué se vio
    creado_en        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    CONSTRAINT fk_revisiones_manuales_analisis FOREIGN KEY (analisis_id) REFERENCES analisis (id) ON DELETE CASCADE,
    CONSTRAINT chk_revisiones_manuales_resultado CHECK (resultado IN ('correcto', 'problema', 'no_aplicable')),
    INDEX idx_revisiones_manuales_criterio (analisis_id, criterio_wcag, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
