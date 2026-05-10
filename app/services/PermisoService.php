<?php

class PermisoService
{
    /**
     * Roles activos del usuario (tabla puente usuario_rol).
     *
     * @return int[]
     */
    private static function rolIdsForUser(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare('
            SELECT rol_id
            FROM usuario_rol
            WHERE usuario_id = ?
            AND estado_id = 1
        ');
        $stmt->execute([$userId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /*
    ========================================
    VALIDAR PERMISO COMPLETO PRO
    ========================================
    Prioridad:

    1. Permiso usuario DENEGAR
    2. Permiso usuario PERMITIR
    3. Permiso rol DENEGAR
    4. Permiso rol PERMITIR
    5. Sin permiso = false
    ========================================
    */

    public static function can($ruta, $accion)
    {
        if (!isset($_SESSION['user_id'])) {
            return false;
        }

        if (!empty($_SESSION['es_super_admin'])) {
            return true;
        }

        $database = new Database();
        $pdo = $database->connect();

        $userId    = $_SESSION['user_id'];
        $empresaId = $_SESSION['empresa_id'] ?? null;
        $sedeId    = $_SESSION['sede_id'] ?? null;

        /*
        ========================================
        OBTENER ROL DEL USUARIO
        ========================================
        */

        $rolIds = self::rolIdsForUser($pdo, (int)$userId);

        /*
        ========================================
        BUSCAR ITEM_ACCION
        1) ruta completa
        2) fallback ruta raíz
        ========================================
        */

        $itemAccionId = null;

        $rutasBuscar = [$ruta];

        if (strpos($ruta, '/') !== false) {
            $raiz = explode('/', $ruta)[0];
            if ($raiz !== $ruta) {
                $rutasBuscar[] = $raiz;
            }
        }

        foreach ($rutasBuscar as $rutaBuscar) {

            $stmt = $pdo->prepare("
                SELECT ia.id
                FROM item_accion ia
                INNER JOIN item i ON i.id = ia.item_id
                INNER JOIN accion a ON a.id = ia.accion_id
                WHERE i.ruta = ?
                AND a.codigo = ?
                LIMIT 1
            ");

            $stmt->execute([$rutaBuscar, $accion]);

            $itemAccionId = $stmt->fetchColumn();

            if ($itemAccionId) {
                break;
            }
        }

        if (!$itemAccionId) {
            return false;
        }

        /*
        ========================================
        1) USUARIO DENEGAR
        ========================================
        */

        $stmt = $pdo->prepare("
            SELECT 1
            FROM permiso
            WHERE usuario_id = ?
            AND item_accion_id = ?
            AND estado_id = 6
            LIMIT 1
        ");

        $stmt->execute([$userId, $itemAccionId]);

        if ($stmt->fetch()) {
            return false;
        }

        /*
        ========================================
        2) USUARIO PERMITIR
        ========================================
        */

        $stmt = $pdo->prepare("
            SELECT 1
            FROM permiso
            WHERE usuario_id = ?
            AND item_accion_id = ?
            AND estado_id = 5
            LIMIT 1
        ");

        $stmt->execute([$userId, $itemAccionId]);

        if ($stmt->fetch()) {
            return true;
        }

        /*
        ========================================
        SI NO TIENE ROL
        ========================================
        */

        if (empty($rolIds)) {
            return false;
        }

        /*
        ========================================
        3) ROL DENEGAR (cualquier rol asignado)
        ========================================
        */

        $placeholders = implode(',', array_fill(0, count($rolIds), '?'));

        $stmt = $pdo->prepare("
            SELECT 1
            FROM rol_permiso
            WHERE rol_id IN ($placeholders)
            AND item_accion_id = ?
            AND estado_id = 6
            LIMIT 1
        ");

        $stmt->execute(array_merge($rolIds, [$itemAccionId]));

        if ($stmt->fetch()) {
            return false;
        }

        /*
        ========================================
        4) ROL PERMITIR (cualquier rol asignado)
        ========================================
        */

        $stmt = $pdo->prepare("
            SELECT 1
            FROM rol_permiso
            WHERE rol_id IN ($placeholders)
            AND item_accion_id = ?
            AND estado_id = 5
            LIMIT 1
        ");

        $stmt->execute(array_merge($rolIds, [$itemAccionId]));

        if ($stmt->fetch()) {
            return true;
        }

        return false;
    }


    /*
    ========================================
    VALIDAR POR item_accion_id
    (botones CRUD)
    ========================================
    */

    public static function canByItemAccion($itemAccionId)
    {
        if (!isset($_SESSION['user_id'])) {
            return false;
        }

        if (!empty($_SESSION['es_super_admin'])) {
            return true;
        }

        $database = new Database();
        $pdo = $database->connect();

        $userId = $_SESSION['user_id'];

        /*
        ========================================
        OBTENER ROL
        ========================================
        */

        $rolIds = self::rolIdsForUser($pdo, (int)$userId);

        /*
        ========================================
        1) USUARIO DENEGAR
        ========================================
        */

        $stmt = $pdo->prepare("
            SELECT 1
            FROM permiso
            WHERE usuario_id = ?
            AND item_accion_id = ?
            AND estado_id = 6
            LIMIT 1
        ");

        $stmt->execute([$userId, $itemAccionId]);

        if ($stmt->fetch()) {
            return false;
        }

        /*
        ========================================
        2) USUARIO PERMITIR
        ========================================
        */

        $stmt = $pdo->prepare("
            SELECT 1
            FROM permiso
            WHERE usuario_id = ?
            AND item_accion_id = ?
            AND estado_id = 5
            LIMIT 1
        ");

        $stmt->execute([$userId, $itemAccionId]);

        if ($stmt->fetch()) {
            return true;
        }

        /*
        ========================================
        3) ROL DENEGAR (cualquier rol asignado)
        ========================================
        */

        if (!empty($rolIds)) {

            $placeholders = implode(',', array_fill(0, count($rolIds), '?'));

            $stmt = $pdo->prepare("
                SELECT 1
                FROM rol_permiso
                WHERE rol_id IN ($placeholders)
                AND item_accion_id = ?
                AND estado_id = 6
                LIMIT 1
            ");

            $stmt->execute(array_merge($rolIds, [$itemAccionId]));

            if ($stmt->fetch()) {
                return false;
            }

            /*
            ========================================
            4) ROL PERMITIR (cualquier rol asignado)
            ========================================
            */

            $stmt = $pdo->prepare("
                SELECT 1
                FROM rol_permiso
                WHERE rol_id IN ($placeholders)
                AND item_accion_id = ?
                AND estado_id = 5
                LIMIT 1
            ");

            $stmt->execute(array_merge($rolIds, [$itemAccionId]));

            if ($stmt->fetch()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Indica si el usuario tiene al menos un permiso "permitir" (estado 5):
     * en tabla permiso (usuario) o en rol_permiso (alguno de sus roles).
     * Super Admin no debe usar este método (se asume acceso global antes).
     */
    public static function userHasAssignedGrants(int $userId): bool
    {
        $database = new Database();
        $pdo = $database->connect();

        $stmt = $pdo->prepare('
            SELECT 1
            FROM permiso
            WHERE usuario_id = ?
            AND estado_id = 5
            LIMIT 1
        ');
        $stmt->execute([$userId]);

        if ($stmt->fetch()) {
            return true;
        }

        $rolIds = self::rolIdsForUser($pdo, $userId);

        if (empty($rolIds)) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($rolIds), '?'));

        $stmt = $pdo->prepare("
            SELECT 1
            FROM rol_permiso
            WHERE rol_id IN ($placeholders)
            AND estado_id = 5
            LIMIT 1
        ");
        $stmt->execute($rolIds);

        return (bool) $stmt->fetchColumn();
    }
}