<?php
/**
 * Controlador de Autenticación - MULTIEMPRESA
 */
require_once BASE_PATH . '/core/Database.php';

class LoginController
{
    public function index()
    {
        // Redirigir si ya está logueado
        if (SessionManager::userLogged()) {
            header("Location: ?url=dashboard");
            exit;
        }
        require BASE_PATH . '/app/views/login.php';
    }

    public function authenticate()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header("Location: ?url=login");
            exit;
        }

        // 1. GUARDAR ERROR/DATOS ANTES DE TODO
        $_SESSION['login_username'] = trim($_POST['username'] ?? '');
        $username = $_SESSION['login_username'];
        $password = trim($_POST['password'] ?? '');

        if (empty($username) || empty($password)) {
            $_SESSION['login_error'] = "Todos los campos son obligatorios";
            header("Location: ?url=login");
            exit;
        }

        $database = new Database();
        $pdo = $database->connect();

        // 2. VALIDAR USUARIO
        $stmt = $pdo->prepare("
            SELECT u.*, r.nombre AS rol_nombre 
            FROM usuario u LEFT JOIN rol r ON r.id = u.rol_id 
            WHERE u.username = ? AND u.estado_id = 1 LIMIT 1
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password'])) {
            $_SESSION['login_error'] = "Usuario o contraseña incorrectos";
            header("Location: ?url=login");
            exit;
        }

        // 3. EMPRESAS
        $stmt = $pdo->prepare("
            SELECT e.id, e.razon_social 
            FROM usuario_empresa ue 
            JOIN empresa e ON e.id = ue.empresa_id 
            WHERE ue.usuario_id = ? AND ue.estado_id = 1 AND e.estado_id = 1 
            ORDER BY e.id
        ");
        $stmt->execute([$user['id']]);
        $empresas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($empresas) === 1) {
            $_SESSION['empresa_id'] = $empresas[0]['id'];
            $_SESSION['empresa'] = $empresas[0]['razon_social'];
        }

        // 🔥 VALIDACIÓN SUSCRIPCIÓN COMPLETA
        if (!empty($_SESSION['empresa_id'])) {
            $stmt = $pdo->prepare("
                SELECT s.*, p.nombre AS plan_nombre 
                FROM suscripcion s 
                JOIN plan p ON p.id = s.plan_id 
                WHERE s.empresa_id = ? AND s.activa = 1 AND s.estado_id = 1 
                ORDER BY s.id DESC LIMIT 1
            ");
            $stmt->execute([$_SESSION['empresa_id']]);
            $suscripcion = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$suscripcion) {
                $_SESSION['login_error'] = "La empresa '{$_SESSION['empresa']}' no tiene plan activo";
                header("Location: ?url=login");
                exit;
            }

            // 🔥 VALIDAR FECHA - HASTA FINAL DEL DÍA
            $fechaFin = date('Y-m-d', strtotime($suscripcion['fecha_fin']));
            $hoy = date('Y-m-d');
            
            if ($fechaFin < $hoy) {
                $_SESSION['login_error'] = "El plan de '{$_SESSION['empresa']}' venció el " . 
                                           date('d/m/Y', strtotime($suscripcion['fecha_fin']));
                header("Location: ?url=login");
                exit;
            }

            // ✅ SUSCRIPCIÓN VÁLIDA
            $_SESSION['plan_id'] = $suscripcion['plan_id'];
            $_SESSION['plan_nombre'] = $suscripcion['plan_nombre'];
            $_SESSION['fecha_fin'] = $suscripcion['fecha_fin'];
        }

        // 5. RESTO SESIÓN
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['nombre'] = $user['nombre'];
        $_SESSION['rol_id'] = $user['rol_id'];
        $_SESSION['rol_nombre'] = $user['rol_nombre'] ?? '';

        // SEDES (tu función)
        $this->loadSedes($pdo, $user['id']);

        header("Location: ?url=dashboard");
        exit;
    }

    private function loadEmpresas($pdo, $userId)
    {
        $stmt = $pdo->prepare("
            SELECT e.id, e.razon_social
            FROM usuario_empresa ue
            JOIN empresa e ON e.id = ue.empresa_id
            WHERE ue.usuario_id = ? AND ue.estado_id = 1 AND e.estado_id = 1
            ORDER BY e.id
        ");
        $stmt->execute([$userId]);
        $empresas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fallback sistema anterior
        if (empty($empresas) && !empty($_SESSION['empresa_id'])) {
            $stmt = $pdo->prepare("
                SELECT id, razon_social FROM empresa 
                WHERE id = ? AND estado_id = 1 LIMIT 1
            ");
            $stmt->execute([$_SESSION['empresa_id']]);
            $empresa = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($empresa) $empresas = [$empresa];
        }

        // Selección empresa
        if (count($empresas) === 1) {
            $_SESSION['empresa_id'] = $empresas[0]['id'];
            $_SESSION['empresa'] = $empresas[0]['razon_social'];
        } elseif (count($empresas) > 1) {
            $_SESSION['empresas'] = $empresas;
            $_SESSION['empresa_id'] = null;
            $_SESSION['empresa'] = 'Seleccione empresa';
        } else {
            $_SESSION['empresa_id'] = null;
            $_SESSION['empresa'] = 'Sin empresa';
        }
    }

    private function loadSedes($pdo, $userId)
    {
        $stmt = $pdo->prepare("
            SELECT s.id, s.nombre
            FROM usuario_sede us
            JOIN sede s ON s.id = us.sede_id
            WHERE us.usuario_id = ? AND us.estado_id = 1 AND s.estado_id = 1
            ORDER BY s.id
        ");
        $stmt->execute([$userId]);
        $sedes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($sedes) === 1) {
            $_SESSION['sede_id'] = $sedes[0]['id'];
            $_SESSION['sede'] = $sedes[0]['nombre'];
        } elseif (count($sedes) > 1) {
            $_SESSION['sedes'] = $sedes;
            $_SESSION['sede_id'] = $sedes[0]['id'] ?? null;
            $_SESSION['sede'] = $sedes[0]['nombre'] ?? 'Seleccione sede';
        } else {
            $_SESSION['sede_id'] = null;
            $_SESSION['sede'] = 'Sin sede';
        }
    }

    private function validateSuscripcion($pdo, $empresaId)
    {
        $stmt = $pdo->prepare("
            SELECT s.*, p.nombre AS plan_nombre 
            FROM suscripcion s 
            JOIN plan p ON p.id = s.plan_id 
            WHERE s.empresa_id = ? AND s.activa = 1 AND s.estado_id = 1 
            ORDER BY s.id DESC LIMIT 1
        ");
        $stmt->execute([$empresaId]);
        $suscripcion = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$suscripcion) {
            return false;
        }

        // 🔥 VERIFICAR FECHA VENCIMIENTO
        if (strtotime($suscripcion['fecha_fin']) < time()) {
            return false;
        }

        return true;
    }

    public function logout()
    {
        SessionManager::destroy();
        header("Location: ?url=login");
        exit;
    }
}