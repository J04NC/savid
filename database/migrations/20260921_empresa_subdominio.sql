/* ============================================================
   Empresa: subdominio exclusivo (ej. "clientea" -> clientea.savid.com.co).
   Cuando una empresa tiene subdominio asignado, sus usuarios solo pueden
   autenticarse desde ese subdominio (ver LoginController/AuthService).
   Nullable: la mayoria de empresas seguira usando el dominio generico
   app.savid.com.co sin ningun cambio.

   COMMENT 'show:none' porque se gestiona via un boton dedicado (solo
   superadmin, integra con la API de Cloudflare para crear el registro DNS
   antes de guardar este valor) — no debe quedar editable como campo libre
   del CRUD generico de empresa.
============================================================ */

ALTER TABLE empresa
    ADD COLUMN subdominio VARCHAR(63) NULL DEFAULT NULL COMMENT 'show:none' AFTER sitio_web,
    ADD UNIQUE KEY uq_empresa_subdominio (subdominio);
