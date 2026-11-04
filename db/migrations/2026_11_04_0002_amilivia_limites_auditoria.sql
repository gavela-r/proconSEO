-- SEG-11 (límites contra el abuso e interruptor general) y SEG-15 (registro de auditoría).
-- Cambio en db/: lo aprueban los tres.

-- IP de quien lanza el análisis y dominio analizado, para contar análisis por hora.
ALTER TABLE analisis
    ADD COLUMN ip_origen VARCHAR(45)  NULL AFTER es_dato_prueba,
    ADD COLUMN dominio   VARCHAR(253) NULL AFTER ip_origen,
    ADD INDEX idx_analisis_ip (ip_origen, creado_en),
    ADD INDEX idx_analisis_dominio (dominio, creado_en);

-- Ajustes globales de la herramienta (por ahora, el interruptor general).
CREATE TABLE ajustes_sistema (
    clave          VARCHAR(60)  PRIMARY KEY,
    valor          VARCHAR(500) NOT NULL,
    actualizado_en DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Registro de auditoría: solo inserción. Sin claves ajenas a propósito, para que sobreviva
-- aunque se borre el proyecto o el análisis. La aplicación no tiene ninguna forma de
-- modificarlo ni borrarlo. En el servidor, el usuario de la aplicación debe tener solo
-- SELECT e INSERT sobre esta tabla (SEG-13); la purga tras el plazo la hace sistemas.
CREATE TABLE registro_auditoria (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    accion       VARCHAR(40)   NOT NULL,
    analisis_id  INT UNSIGNED  NULL,
    proyecto_id  INT UNSIGNED  NULL,
    ip_origen    VARCHAR(45)   NULL,
    url          VARCHAR(2048) NULL,
    regla        VARCHAR(20)   NULL,
    detalle      TEXT          NULL,
    creado_en    DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    INDEX idx_auditoria_fecha (creado_en),
    INDEX idx_auditoria_analisis (analisis_id),
    INDEX idx_auditoria_ip (ip_origen, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
