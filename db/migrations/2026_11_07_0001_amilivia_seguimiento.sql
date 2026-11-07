-- Seguimiento de cada hallazgo por proyecto (docs/diseno/analisis-herramientas.md, mejoras 4 y 5).
-- Cambio en db/: lo aprueban los tres.
--
-- Igual que las revisiones, nunca se edita: cada cambio es una fila nueva con quién, cuándo y por
-- qué. El estado actual de un hallazgo es la última fila de su (proyecto, comprobación).
-- No toca el resultado original ni su estado (RF-42): «aceptado» significa que el equipo lo da por
-- bueno conscientemente (con motivo), no que el resultado pase a «correcto».
-- «Comprobado» no se guarda: lo deduce la aplicación cuando un análisis posterior de la misma
-- dirección y alcance ya no lo encuentra.

CREATE TABLE seguimientos (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    proyecto_id      INT UNSIGNED NOT NULL,
    id_comprobacion  VARCHAR(80)  NOT NULL,
    estado           VARCHAR(20)  NOT NULL,   -- pendiente | en_curso | corregido | aceptado
    autor            VARCHAR(120) NOT NULL,   -- no hay login: el nombre que escribe la persona
    motivo           TEXT         NULL,       -- obligatorio para «aceptado»
    analisis_id      INT UNSIGNED NULL,       -- análisis desde el que se marcó
    creado_en        DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    CONSTRAINT fk_seguimientos_proyecto FOREIGN KEY (proyecto_id) REFERENCES proyectos (id) ON DELETE CASCADE,
    CONSTRAINT fk_seguimientos_analisis FOREIGN KEY (analisis_id) REFERENCES analisis (id) ON DELETE SET NULL,
    INDEX idx_seguimientos_hallazgo (proyecto_id, id_comprobacion, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
