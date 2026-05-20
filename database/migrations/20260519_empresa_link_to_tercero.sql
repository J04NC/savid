/* ============================================================
   Migración: empresa <-> tercero / terceroidentificacion
   Objetivo:
     - Vincular la tabla `empresa` a `tercero` y `terceroidentificacion`
       (NIT como identificación principal, tipo persona jurídica).
     - Back-fill: por cada empresa existente sin vínculo, crear un tercero
       jurídico + identificación NIT principal y enlazar las FKs.
     - Eliminar la columna redundante `empresa.nit` (queda en
       terceroidentificacion.numero como única fuente de verdad).

   IMPORTANTE:
     - Ejecutar TODO el archivo en una sola transacción si se desea
       atomicidad (la mayoría de DDL en MySQL hace commit implícito;
       considere respaldo previo).
     - Probado con `tipodocumento_id = 9` (NIT) y `tipopersona_id = 2`
       (Persona jurídica). Ajustar si su catálogo difiere.
============================================================ */

/* 1) DDL: agregar columnas FK */
ALTER TABLE empresa
  ADD COLUMN tercero_id INT UNSIGNED NULL AFTER id,
  ADD COLUMN terceroidentificacion_id BIGINT UNSIGNED NULL AFTER tercero_id;

ALTER TABLE empresa
  ADD CONSTRAINT fk_empresa_tercero
    FOREIGN KEY (tercero_id) REFERENCES tercero(id),
  ADD CONSTRAINT fk_empresa_terceroidentificacion
    FOREIGN KEY (terceroidentificacion_id) REFERENCES terceroidentificacion(id);

/* 2) Back-fill: por cada empresa sin tercero_id, crear tercero (jurídico) +
      terceroidentificacion (NIT principal) y enlazar. */
DROP PROCEDURE IF EXISTS sp_migrate_empresa_to_tercero;

DELIMITER //
CREATE PROCEDURE sp_migrate_empresa_to_tercero()
BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE v_eid INT;
  DECLARE v_razon VARCHAR(150);
  DECLARE v_nit VARCHAR(50);
  DECLARE v_email VARCHAR(150);
  DECLARE v_telefono VARCHAR(50);
  DECLARE v_direccion VARCHAR(150);
  DECLARE v_new_tercero_id INT;
  DECLARE v_new_ti_id BIGINT;

  DECLARE cur CURSOR FOR
    SELECT id, razon_social, nit, email, telefono, direccion
      FROM empresa
      WHERE tercero_id IS NULL;

  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

  OPEN cur;
  read_loop: LOOP
    FETCH cur INTO v_eid, v_razon, v_nit, v_email, v_telefono, v_direccion;
    IF done = 1 THEN
      LEAVE read_loop;
    END IF;

    /* Crear tercero jurídico con los datos de la empresa */
    INSERT INTO tercero (
      tipopersona_id, razon_social, email, telefono, direccion, estado_id
    ) VALUES (
      2,
      v_razon,
      NULLIF(v_email, ''),
      NULLIF(v_telefono, ''),
      NULLIF(v_direccion, ''),
      1
    );
    SET v_new_tercero_id = LAST_INSERT_ID();

    /* Crear identificación NIT principal asociada al tercero recién creado */
    INSERT INTO terceroidentificacion (
      tercero_id, tipodocumento_id, numero, principal, estado_id
    ) VALUES (
      v_new_tercero_id,
      9,
      v_nit,
      1,
      1
    );
    SET v_new_ti_id = LAST_INSERT_ID();

    /* Enlazar la empresa */
    UPDATE empresa
       SET tercero_id = v_new_tercero_id,
           terceroidentificacion_id = v_new_ti_id
     WHERE id = v_eid;
  END LOOP;
  CLOSE cur;
END //
DELIMITER ;

CALL sp_migrate_empresa_to_tercero();
DROP PROCEDURE sp_migrate_empresa_to_tercero;

/* 3) Verificación opcional: ninguna empresa debería quedar sin tercero */
/* SELECT id, razon_social, tercero_id, terceroidentificacion_id FROM empresa; */

/* 4) Reforzar NOT NULL ahora que todas las empresas están vinculadas */
ALTER TABLE empresa
  MODIFY COLUMN tercero_id INT UNSIGNED NOT NULL,
  MODIFY COLUMN terceroidentificacion_id BIGINT UNSIGNED NOT NULL;

/* 5) Eliminar columna redundante `nit` (fuente de verdad: terceroidentificacion.numero) */
ALTER TABLE empresa
  DROP COLUMN nit;
