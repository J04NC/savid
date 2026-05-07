<?php

class RolController
{
    public function index()
    {
        require BASE_PATH . '/app/controllers/ModuleController.php';

        $module = new ModuleController();
        $module->index();
    }

    public function permisos()
    {
        if (!isset($_SESSION['user_id'])) {
            exit;
        }

        $database = new Database();
        $pdo = $database->connect();

        $rolId = $_GET['id'] ?? 0;

        $esSuperAdmin = (int)($_SESSION['rol_id'] ?? 0) === 1;

        /*
        ==========================================
        AJAX SEDES (PRIORIDAD TOTAL)
        ==========================================
        */
        if (isset($_GET['ajax']) && $_GET['ajax'] === 'sedes') {

            $empresaId = $_GET['empresa_id'] ?? null;

            if (!$esSuperAdmin) {
                $empresaId = $_SESSION['empresa_id'] ?? null;
            }

            if (!$empresaId) {
                echo json_encode([]);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT id, nombre
                FROM sede
                WHERE empresa_id = ?
                AND estado_id = 1
                ORDER BY nombre
            ");
            $stmt->execute([$empresaId]);

            echo json_encode(
                $stmt->fetchAll(PDO::FETCH_ASSOC)
            );
            exit;
        }

        /*
        ==========================================
        NUEVO: AJAX MATRIZ PERMISOS
        ==========================================
        */
        if (isset($_GET['ajax']) && $_GET['ajax'] === 'matriz') {

            $empresaId = $_GET['empresa_id'] ?? null;
            $sedeId    = $_GET['sede_id'] ?? null;

            // MATRIZ BASE
            $rows = $pdo->query("
                SELECT
                    m.nombre AS modulo,
                    i.id AS item_id,
                    i.nombre AS item,
                    a.nombre AS accion,
                    a.codigo,
                    ia.id AS item_accion_id
                FROM item_accion ia
                JOIN item i ON i.id = ia.item_id
                JOIN modulo m ON m.id = i.modulo_id
                JOIN accion a ON a.id = ia.accion_id
                WHERE ia.estado_id = 1
                AND i.estado_id = 1
                ORDER BY m.id, i.orden, a.orden
            ")->fetchAll(PDO::FETCH_ASSOC);

            // PERMISOS ACTUALES
            $params = [$rolId];
            $sql = "SELECT item_accion_id FROM rol_permiso WHERE rol_id = ?";

            if ($empresaId === null) {
                $sql .= " AND empresa_id IS NULL";
            } else {
                $sql .= " AND empresa_id = ?";
                $params[] = $empresaId;
            }

            if ($sedeId === null) {
                $sql .= " AND sede_id IS NULL";
            } else {
                $sql .= " AND sede_id = ?";
                $params[] = $sedeId;
            }

            $sql .= " AND estado_id = 5";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $actuales = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // CONSTRUIR MATRIZ
            $acciones = [];
            $matriz = [];

            foreach ($rows as $r) {

                $acciones[$r['codigo']] = $r['accion'];

                $modulo = $r['modulo'];
                $itemId = $r['item_id'];

                if (!isset($matriz[$modulo])) {
                    $matriz[$modulo] = [];
                }

                if (!isset($matriz[$modulo][$itemId])) {
                    $matriz[$modulo][$itemId] = [
                        'item' => $r['item'],
                        'acciones' => []
                    ];
                }

                $matriz[$modulo][$itemId]['acciones'][$r['codigo']] = [
                    'id' => $r['item_accion_id'],
                    'checked' => in_array($r['item_accion_id'], $actuales)
                ];
            }

            echo json_encode([
                'acciones' => $acciones,
                'matriz' => $matriz
            ]);
            exit;
        }

        /*
        ==========================================
        CONTEXTO EMPRESA / SEDE
        ==========================================
        */
        $empresaId = $_GET['empresa_id'] ?? null;
        $sedeId    = $_GET['sede_id'] ?? null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $empresaId = $_POST['empresa_id'] ?? null;
            $sedeId    = $_POST['sede_id'] ?? null;
        }

        if (!$esSuperAdmin) {
            $empresaId = $_SESSION['empresa_id'] ?? null;
        }

        if ($empresaId === '') $empresaId = null;
        if ($sedeId === '') $sedeId = null;

        /*
        ==========================================
        GUARDAR
        ==========================================
        */
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

            $checks = $_POST['permisos'] ?? [];

            $where = "
                rol_id = ?
                AND " . ($empresaId === null ? "empresa_id IS NULL" : "empresa_id = ?") . "
                AND " . ($sedeId === null ? "sede_id IS NULL" : "sede_id = ?") . "
                AND estado_id = 5
            ";

            $params = [$rolId];
            if ($empresaId !== null) $params[] = $empresaId;
            if ($sedeId !== null) $params[] = $sedeId;

            $stmt = $pdo->prepare("
                SELECT item_accion_id
                FROM rol_permiso
                WHERE $where
            ");
            $stmt->execute($params);

            $actuales = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $checks   = array_map('intval', $checks);
            $actuales = array_map('intval', $actuales);

            $insertar = array_diff($checks, $actuales);
            $eliminar = array_diff($actuales, $checks);

            foreach ($insertar as $itemAccionId) {

                $stmt = $pdo->prepare("
                    INSERT INTO rol_permiso
                    (rol_id, empresa_id, sede_id, item_accion_id, estado_id)
                    VALUES (?,?,?,?,5)
                ");

                $stmt->execute([
                    $rolId,
                    $empresaId,
                    $sedeId,
                    $itemAccionId
                ]);
            }

            foreach ($eliminar as $itemAccionId) {

                $sql = "DELETE FROM rol_permiso WHERE rol_id = ?";

                $params = [$rolId];

                if ($empresaId === null) {
                    $sql .= " AND empresa_id IS NULL ";
                } else {
                    $sql .= " AND empresa_id = ? ";
                    $params[] = $empresaId;
                }

                if ($sedeId === null) {
                    $sql .= " AND sede_id IS NULL ";
                } else {
                    $sql .= " AND sede_id = ? ";
                    $params[] = $sedeId;
                }

                $sql .= " AND item_accion_id = ?";
                $params[] = $itemAccionId;

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            }

            echo json_encode(['success' => true]);
            exit;
        }

        /*
        ==========================================
        EMPRESAS
        ==========================================
        */
        if ($esSuperAdmin) {

            $stmt = $pdo->query("
                SELECT id, razon_social
                FROM empresa
                WHERE estado_id = 1
                ORDER BY razon_social
            ");

        } else {

            $stmt = $pdo->prepare("
                SELECT e.id, e.razon_social
                FROM usuario_empresa ue
                JOIN empresa e ON e.id = ue.empresa_id
                WHERE ue.usuario_id = ?
                AND ue.estado_id = 1
                AND e.estado_id = 1
                ORDER BY e.razon_social
            ");

            $stmt->execute([$_SESSION['user_id']]);
        }

        $empresas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        /*
        ==========================================
        SEDES
        ==========================================
        */
        $sedes = [];

        if ($empresaId) {

            $stmt = $pdo->prepare("
                SELECT id, nombre
                FROM sede
                WHERE empresa_id = ?
                AND estado_id = 1
                ORDER BY nombre
            ");

            $stmt->execute([$empresaId]);
            $sedes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        /*
        ==========================================
        MATRIZ (solo para carga inicial)
        ==========================================
        */
        $rows = $pdo->query("
            SELECT
                m.nombre AS modulo,
                i.id AS item_id,
                i.nombre AS item,
                a.nombre AS accion,
                a.codigo,
                ia.id AS item_accion_id
            FROM item_accion ia
            JOIN item i ON i.id = ia.item_id
            JOIN modulo m ON m.id = i.modulo_id
            JOIN accion a ON a.id = ia.accion_id
            WHERE ia.estado_id = 1
            AND i.estado_id = 1
            ORDER BY m.id, i.orden, a.orden
        ")->fetchAll(PDO::FETCH_ASSOC);

        $params = [$rolId];

        $sql = "
            SELECT item_accion_id
            FROM rol_permiso
            WHERE rol_id = ?
        ";

        if ($empresaId === null) {
            $sql .= " AND empresa_id IS NULL ";
        } else {
            $sql .= " AND empresa_id = ? ";
            $params[] = $empresaId;
        }

        if ($sedeId === null) {
            $sql .= " AND sede_id IS NULL ";
        } else {
            $sql .= " AND sede_id = ? ";
            $params[] = $sedeId;
        }

        $sql .= " AND estado_id = 5";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $actuales = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $acciones = [];
        $matriz = [];

        foreach ($rows as $r) {

            $acciones[$r['codigo']] = $r['accion'];

            $modulo = $r['modulo'];
            $itemId = $r['item_id'];

            if (!isset($matriz[$modulo])) {
                $matriz[$modulo] = [];
            }

            if (!isset($matriz[$modulo][$itemId])) {
                $matriz[$modulo][$itemId] = [
                    'item' => $r['item'],
                    'acciones' => []
                ];
            }

            $matriz[$modulo][$itemId]['acciones'][$r['codigo']] = [
                'id' => $r['item_accion_id'],
                'checked' => in_array($r['item_accion_id'], $actuales)
            ];
        }

        require BASE_PATH . '/app/views/rol/permisos.php';
    }
}