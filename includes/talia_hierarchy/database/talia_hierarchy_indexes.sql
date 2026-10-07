-- TalIAHierarchyResolver v1.0 - índices recomendados
-- EJECUTAR primero en armando-dev/staging y validar con SHOW INDEX / EXPLAIN.
-- No elimina ni modifica datos.

-- Antes de crear, verificar que no existan índices equivalentes.
-- ALTER TABLE instalaciones ADD INDEX idx_inst_fecha_lider_coach (fecha, lider, coach);
-- ALTER TABLE instalaciones ADD INDEX idx_inst_fecha_folio_cuenta (fecha, folio_empleado, cuenta);
-- ALTER TABLE hc ADD INDEX idx_hc_periodo_distrito (anio, semana, distrito);
-- ALTER TABLE hc ADD INDEX idx_hc_posiciones (id_posicion, posicion_lr);
-- ALTER TABLE hc ADD INDEX idx_hc_talento_periodo (numero_talento_gs, anio, semana);
-- ALTER TABLE historial_identidad_colaborador ADD INDEX idx_hic_talento_ant (numero_talento_anterior);
-- ALTER TABLE historial_identidad_colaborador ADD INDEX idx_hic_talento_nvo (numero_talento_nuevo);
-- ALTER TABLE historial_identidad_colaborador ADD INDEX idx_hic_pos_ant (id_posicion_anterior);
-- ALTER TABLE historial_identidad_colaborador ADD INDEX idx_hic_pos_nva (id_posicion_nueva);

-- Motivo de dejarlos comentados: no debemos crear índices duplicados sin inspeccionar producción.
