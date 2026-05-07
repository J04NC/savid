<?php
/**
 * Usuario - CRUD Automático + Multi-Rol
 */
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/app/services/CrudService.php';

class UsuarioController
{
    public function index()
    {
        require BASE_PATH . '/app/controllers/ModuleController.php';

        $module = new ModuleController();
        $module->index();
    }
    private $service;

    public function __construct()
    {
        $this->service = new CrudService();
    }

    // ✅ MÉTODO FALTANTE
    public function index()
    {
        // CRUD AUTOMÁTICO - IGUAL QUE ROL
        $tabla = 'usuario';
        
        // Filtros empresa/sede
        $data = $this->service->getTableData($tabla);
        
        require BASE_PATH . '/app/views/crud/table.php';
    }

    public function permisos($usuarioId = null)
    {
        SessionManager::requireLogin();
        
        $database = new Database();
        $pdo = $database->connect();

        if (!$usuarioId) {
            $_SESSION['error'] = "Usuario no especificado";
            header("Location: ?url=usuario");
            exit;
        }

        // Datos usuario
        $stmt = $pdo->prepare("SELECT * FROM usuario WHERE id = ? AND estado_id = 1");
        $stmt->execute([$usuarioId]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usuario) {
            $_SESSION['error'] = "Usuario no encontrado";
            header("Location: ?url=usuario");
            exit;
        }

        // Roles del usuario
        $stmt = $pdo->prepare("
            SELECT ur.*, r.nombre, r.id as rol_id
            FROM usuario_rol ur 
            JOIN rol r ON r.id = ur.rol_id 
            WHERE ur.usuario_id = ? AND ur.estado_id = 1
        ");
        $stmt->execute([$usuarioId]);
        $rolesUsuario = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Todos los roles
        $stmt = $pdo->prepare("SELECT * FROM rol WHERE estado_id = 1 ORDER BY nombre");
        $stmt->execute();
        $todosRoles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Pasar a vista
        $viewData = [
            'usuario' => $usuario,
            'rolesUsuario' => $rolesUsuario,
            'todosRoles' => $todosRoles,
            'usuarioId' => $usuarioId
        ];

        require BASE_PATH . '/app/views/usuario/permisos.php';
    }

    public function saveRoles($usuarioId)
    {
        SessionManager::requireLogin();
        
        $database = new Database();
        $pdo = $database->connect();

        // Limpiar roles anteriores
        $stmt = $pdo->prepare("DELETE FROM usuario_rol WHERE usuario_id = ?");
        $stmt->execute([$usuarioId]);

        // Nuevos roles
        if (!empty($_POST['roles'])) {
            $stmt = $pdo->prepare("INSERT INTO usuario_rol (usuario_id, rol_id, estado_id) VALUES (?, ?, 1)");
            foreach ($_POST['roles'] as $rolId) {
                $stmt->execute([$usuarioId, $rolId]);
            }
        }

        $_SESSION['success'] = "Roles guardados correctamente";
        header("Location: ?url=usuario/permisos/$usuarioId");
        exit;
    }
}