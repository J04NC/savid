-- CRUD propio de documentos SGD (ruta dedicada en lugar de sgd_documento genérico)
UPDATE item
SET ruta = 'sgd/documentos'
WHERE ruta = 'sgd_documento'
LIMIT 1;
