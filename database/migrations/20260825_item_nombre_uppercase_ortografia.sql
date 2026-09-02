/* ============================================================
   Normaliza `item.nombre` e `item.nombre_es` a MAYÚSCULAS en todo el
   menú, y corrige tildes/ortografía en español (CONFIGURACION ->
   CONFIGURACIÓN, AUDITORIA -> AUDITORÍA, ITEM -> ÍTEM, etc.).

   El módulo ACADEMIC (modulo_id=5) usa nombres en inglés a propósito
   (CEFR Levels, Skills, Student Enrolment...) — se les aplica SOLO
   mayúscula, sin tocar la ortografía inglesa (incluye "Enrolment",
   variante británica válida, no un error). Los nombre_es de ese mismo
   módulo ya estaban bien acentuados; también solo se les sube el caso.

   "LOGIN" (item 49, ADMINISTRACION) se deja tal cual: préstamo técnico
   de uso corriente en español, no es un error de ortografía.

   No incluye los ítems huérfanos de NOMINA (nomina/reporteGeneral,
   nomina/reporteDetallado, nomina/index) más allá de lo que ya
   cumplían — el usuario pidió expresamente no tocarlos; de cualquier
   forma ya estaban en mayúscula y sin errores, así que no requieren
   UPDATE.

   Solo se actualizan las filas cuyo valor cambia; el resto de `item`
   (EMPRESA, USUARIO, TERCERO, ROL, PLANES...) ya estaba correcto y no
   aparece aquí.
============================================================ */

-- ADMINISTRACION (modulo_id=1)
UPDATE item SET nombre = 'ÍTEM' WHERE id = 4;
UPDATE item SET nombre = 'REPORTES ADMINISTRACIÓN' WHERE id IN (12, 14);
UPDATE item SET nombre = 'AUDITORÍA' WHERE id = 15;
UPDATE item SET nombre = 'CONFIGURACIÓN CORREO' WHERE id = 50;

-- NOMINA (modulo_id=2)
UPDATE item SET nombre = 'CONFIGURACIÓN' WHERE id = 6;
UPDATE item SET nombre = 'TERCEROS NÓMINA' WHERE id = 80;

-- GESTIÓN DOCUMENTAL (modulo_id=4)
UPDATE item SET nombre = 'CONFIGURACIÓN SGD' WHERE id = 17;
UPDATE item SET nombre = 'IMPORTAR DATOS' WHERE id = 18;
UPDATE item SET nombre = 'PROCESOS' WHERE id = 19;
UPDATE item SET nombre = 'TIPOS DOCUMENTALES' WHERE id = 20;
UPDATE item SET nombre = 'DEPENDENCIAS' WHERE id = 21;
UPDATE item SET nombre = 'SERIES' WHERE id = 22;
UPDATE item SET nombre = 'SUBSERIES' WHERE id = 23;
UPDATE item SET nombre = 'DOCUMENTOS (LISTADO MAESTRO)' WHERE id = 24;
UPDATE item SET nombre = 'TIPOS DE PROCESO' WHERE id = 25;
UPDATE item SET nombre = 'CUADRO DE CLASIFICACIÓN (CCD)' WHERE id = 26;
UPDATE item SET nombre = 'DISEÑADOR FORMULARIOS' WHERE id = 27;
UPDATE item SET nombre = 'SECCIONES DOCUMENTALES' WHERE id = 28;
UPDATE item SET nombre = 'ELABORACIÓN MAESTRO' WHERE id = 29;
UPDATE item SET nombre = 'REGISTROS OPERATIVOS' WHERE id = 30;

-- ACADEMIC (modulo_id=5) — nombre en inglés: solo mayúscula, ortografía intacta.
UPDATE item SET nombre = 'CEFR LEVELS', nombre_es = 'NIVELES CEFR' WHERE id = 32;
UPDATE item SET nombre = 'SKILLS', nombre_es = 'DESTREZAS' WHERE id = 33;
UPDATE item SET nombre = 'CAN-DO DESCRIPTORS', nombre_es = 'DESCRIPTORES (CAN-DO)' WHERE id = 34;
UPDATE item SET nombre = 'UNITS', nombre_es = 'UNIDADES' WHERE id = 35;
UPDATE item SET nombre = 'LESSONS', nombre_es = 'LECCIONES' WHERE id = 36;
UPDATE item SET nombre = 'EXERCISE TYPES', nombre_es = 'TIPOS DE EJERCICIO' WHERE id = 37;
UPDATE item SET nombre = 'EXERCISES', nombre_es = 'EJERCICIOS' WHERE id = 38;
UPDATE item SET nombre = 'GRADE PENDING ATTEMPTS', nombre_es = 'CALIFICAR PENDIENTES' WHERE id = 39;
UPDATE item SET nombre = 'STUDENT PROGRESS', nombre_es = 'PROGRESO DE ESTUDIANTES' WHERE id = 40;
UPDATE item SET nombre = 'STUDY', nombre_es = 'ESTUDIAR' WHERE id = 41;
UPDATE item SET nombre = 'TAKE EXERCISE', nombre_es = 'PRACTICAR EJERCICIO' WHERE id = 42;
UPDATE item SET nombre = 'MY PROGRESS', nombre_es = 'MI PROGRESO' WHERE id = 43;
-- "Enrolment" (no "Enrollment"): variante británica válida, se conserva tal cual.
UPDATE item SET nombre = 'STUDENT ENROLMENT', nombre_es = 'MATRÍCULA DE ESTUDIANTES' WHERE id = 44;
UPDATE item SET nombre = 'MODULES', nombre_es = 'MÓDULOS' WHERE id = 51;

-- SIHOS (modulo_id=6)
UPDATE item SET nombre = 'CONEXIÓN SIHOS' WHERE id = 56;
UPDATE item SET nombre = 'PRESUPUESTO' WHERE id = 57;
UPDATE item SET nombre = 'CRUCE RECONOCIMIENTOS VS CONTABILIDAD' WHERE id = 58;
UPDATE item SET nombre = 'NÓMINA' WHERE id = 73;
UPDATE item SET nombre = 'APORTES EN LÍNEA (PILA)' WHERE id = 74;

-- Financiero (modulo_id=7)
UPDATE item SET nombre = 'EMPRESA CONFIG', nombre_es = 'GENERAL' WHERE id = 61;
UPDATE item SET nombre = 'CATÁLOGOS', nombre_es = 'CATÁLOGO' WHERE id = 62;
UPDATE item SET nombre = 'ÍTEMS DE CATÁLOGO', nombre_es = 'ÍTEMS DE CATÁLOGO' WHERE id = 63;
UPDATE item SET nombre = 'IMPUESTOS', nombre_es = 'IMPUESTOS' WHERE id = 64;
UPDATE item SET nombre = 'TIPOS DE DOCUMENTO', nombre_es = 'TIPOS DE DOCUMENTO' WHERE id = 65;
UPDATE item SET nombre = 'PRODUCTOS Y SERVICIOS', nombre_es = 'PRODUCTO' WHERE id = 66;
UPDATE item SET nombre = 'NUMERACIÓN', nombre_es = 'NUMERACIÓN' WHERE id = 67;
UPDATE item SET nombre = 'CONFIGURACIÓN', nombre_es = 'CONFIGURACIÓN' WHERE id = 68;

-- Facturación (modulo_id=8)
UPDATE item SET nombre = 'GESTIÓN', nombre_es = 'GESTIÓN' WHERE id = 69;
UPDATE item SET nombre = 'CONFIGURACIÓN', nombre_es = 'CONFIGURACIÓN' WHERE id = 70;
UPDATE item SET nombre_es = 'REPORTES' WHERE id = 71;

-- Tesorería (modulo_id=9)
UPDATE item SET nombre = 'GESTIÓN', nombre_es = 'GESTIÓN' WHERE id = 72;
