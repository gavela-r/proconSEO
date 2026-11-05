-- Norma de la que sale un resultado cuando no es un criterio WCAG (p. ej. la declaración de
-- accesibilidad, Real Decreto 1112/2018). Cambio en db/: lo aprueban los tres.
ALTER TABLE resultados
    ADD COLUMN normativa VARCHAR(100) NULL AFTER wcag_version;
