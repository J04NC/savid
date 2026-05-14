-- OBSOLETO (2026): No ejecutar el ALTER que antes vivía en este archivo.
-- El modelo real usa `tercero.zona_id` → `zona.tipo` (urbana / rural) para el toggle
-- comuna+barrio vs corregimiento+vereda en el CRUD (`app/views/crud/table.php`, `public/js/crud.js`).
-- Las columnas `comuna_id`, `corregimiento_id`, `zona_id`, etc. ya pertenecen al esquema estándar.

SELECT 1;
