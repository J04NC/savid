/* ============================================================
   SIHOS — algunas instalaciones son multi-institución (un hospital
   principal + puestos de salud satélite comparten la misma BD física,
   cada uno con su propio CodiInst en EncaCont/DetaCont/DetaPlan/MaesDocu/
   CodiInst). Sin este dato, las consultas del cruce podrían mezclar datos
   de instituciones distintas si más de una tiene transacciones activas.
   Backfill con los CodiInst ya confirmados manualmente para las dos
   empresas conectadas hasta ahora.
============================================================ */

ALTER TABLE `sihos_empresa_config`
    ADD COLUMN `codi_inst` VARCHAR(20) NULL DEFAULT NULL COMMENT 'show:none' AFTER `usuario`;

UPDATE sihos_empresa_config SET codi_inst = '764000165501' WHERE empresa_id = 17 AND codi_inst IS NULL;
UPDATE sihos_empresa_config SET codi_inst = '766220170901' WHERE empresa_id = 18 AND codi_inst IS NULL;
