<?php

class ContextController
{
    public function cambiarSede()
    {
        if (!isset($_SESSION['user_id'])) {
            header("Location: ?url=login");
            exit;
        }

        $database = new Database();
        $pdo = $database->connect();

        // =========================================
        // 🔥 AJAX: CARGAR SEDES POR EMPRESA
        // =========================================
        if (isset($_GET['empresa_id'])) {

            $empresaId = $_GET['empresa_id'];

            $stmt = $pdo->prepare("
                SELECT id, nombre
                FROM sede
                WHERE empresa_id = ?
                AND estado_id = 1
                ORDER BY nombre
            ");
            $stmt->execute([$empresaId]);

            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            exit;
        }

        // =========================================
        // 🔥 GUARDAR SELECCIÓN (AJAX)
        // =========================================
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

            $empresaId = $_POST['empresa_id'] ?? null;
            $sedeId = $_POST['sede_id'] ?? null;

            // validar empresa
            $stmt = $pdo->prepare("
                SELECT id, razon_social
                FROM empresa
                WHERE id = ?
                AND estado_id = 1
                LIMIT 1
            ");
            $stmt->execute([$empresaId]);
            $empresa = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$empresa) {
                echo json_encode(['success' => false]);
                exit;
            }

            $_SESSION['empresa_id'] = $empresa['id'];
            $_SESSION['empresa'] = $empresa['razon_social'];

            // validar sede (opcional)
            if ($sedeId) {

                $stmt = $pdo->prepare("
                    SELECT id, nombre
                    FROM sede
                    WHERE id = ?
                    AND empresa_id = ?
                    AND estado_id = 1
                    LIMIT 1
                ");
                $stmt->execute([$sedeId, $empresaId]);
                $sede = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($sede) {
                    $_SESSION['sede_id'] = $sede['id'];
                    $_SESSION['sede'] = $sede['nombre'];
                } else {
                    $_SESSION['sede_id'] = null;
                    $_SESSION['sede'] = 'Sin sede';
                }

            } else {
                $_SESSION['sede_id'] = null;
                $_SESSION['sede'] = 'Sin sede';
            }

            echo json_encode([
                'success' => true,
                'empresa' => $_SESSION['empresa'],
                'sede' => $_SESSION['sede']
            ]);
            exit;
        }

        // =========================================
        // 🔥 OBTENER EMPRESAS (para vista)
        // =========================================
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
        $empresas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // =========================================
        // 🔥 CARGAR VISTA
        // =========================================
        require BASE_PATH . '/app/views/select_context.php';
    }
}