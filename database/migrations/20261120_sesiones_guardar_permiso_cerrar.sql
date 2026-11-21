/* ============================================================
   Auditoría de seguridad: SesionesController::cerrar() solo exigía
   'ver' (el mismo permiso de solo consulta del reporte) para forzar el
   cierre de la sesión de otro usuario. Se agrega el chequeo de
   'guardar' en el código; esta migración replica, para 'guardar', los
   mismos grants que hoy existen para 'ver' sobre el ítem 13
   (sesiones) — así ningún rol pierde la capacidad que ya tenía, y
   cualquier rol futuro con solo 'ver' no podrá cerrar sesiones ajenas.
============================================================ */

INSERT INTO rol_permiso (rol_id, empresa_id, sede_id, item_accion_id, estado_id)
SELECT rp_ver.rol_id, rp_ver.empresa_id, rp_ver.sede_id, ia_guardar.id, rp_ver.estado_id
FROM rol_permiso rp_ver
INNER JOIN item_accion ia_ver ON ia_ver.id = rp_ver.item_accion_id
INNER JOIN accion a_ver ON a_ver.id = ia_ver.accion_id
INNER JOIN item_accion ia_guardar ON ia_guardar.item_id = ia_ver.item_id
INNER JOIN accion a_guardar ON a_guardar.id = ia_guardar.accion_id AND a_guardar.codigo = 'guardar'
WHERE ia_ver.item_id = 13
  AND a_ver.codigo = 'ver'
  AND rp_ver.estado_id = 5
  AND NOT EXISTS (
      SELECT 1 FROM rol_permiso rp_existente
      WHERE rp_existente.rol_id = rp_ver.rol_id
        AND rp_existente.item_accion_id = ia_guardar.id
        AND rp_existente.estado_id = 5
        AND (rp_existente.empresa_id <=> rp_ver.empresa_id)
        AND (rp_existente.sede_id <=> rp_ver.sede_id)
  );
