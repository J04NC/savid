<?php

class PermisoService
{
    /** @var array<string, bool> */
    private static $canCache = [];

    /**
     * Roles activos del usuario (tabla puente usuario_rol).
     *
     * @return int[]
     */
    private static function rolIdsForUser(PDO $pdo, int $userId): array
    {
        [$empresaId, $sedeId] = self::sessionEmpresaSede();
        [$urScopeSql, $urScopeParams] = self::tenantScopeSql('ur', $empresaId, $sedeId);

        $stmt = $pdo->prepare("
            SELECT DISTINCT ur.rol_id
            FROM usuario_rol ur
            WHERE ur.usuario_id = ?
            AND ur.estado_id = 1
            $urScopeSql
        ");
        $stmt->execute(array_merge([$userId], $urScopeParams));

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Condición SQL para permiso / rol_permiso aplicable al contexto:
     * global (NULL,NULL), solo empresa, o empresa+sede.
     *
     * @return array{0: string, 1: array<int|float>}
     */
    public static function tenantScopeSql(string $alias, ?int $empresaId, ?int $sedeId): array
    {
        if ($empresaId === null || $empresaId <= 0) {
            return [
                " AND ({$alias}.empresa_id IS NULL AND {$alias}.sede_id IS NULL) ",
                [],
            ];
        }

        $e = (int)$empresaId;

        if ($sedeId === null || $sedeId <= 0) {
            return [
                " AND (
                    ({$alias}.empresa_id IS NULL AND {$alias}.sede_id IS NULL)
                    OR ({$alias}.empresa_id = ? AND {$alias}.sede_id IS NULL)
                ) ",
                [$e],
            ];
        }

        $s = (int)$sedeId;

        return [
            " AND (
                ({$alias}.empresa_id IS NULL AND {$alias}.sede_id IS NULL)
                OR ({$alias}.empresa_id = ? AND {$alias}.sede_id IS NULL)
                OR ({$alias}.empresa_id = ? AND {$alias}.sede_id = ?)
            ) ",
            [$e, $e, $s],
        ];
    }

    private static function sessionEmpresaSede(): array
    {
        $empresaId = isset($_SESSION['empresa_id']) ? (int)$_SESSION['empresa_id'] : null;
        $sedeId = isset($_SESSION['sede_id']) ? (int)$_SESSION['sede_id'] : null;

        if ($empresaId !== null && $empresaId <= 0) {
            $empresaId = null;
        }
        if ($sedeId !== null && $sedeId <= 0) {
            $sedeId = null;
        }

        return [$empresaId, $sedeId];
    }

    /*
    ========================================
    VALIDAR PERMISO COMPLETO PRO
    ========================================
    Prioridad:

    1. Permiso usuario DENEGAR (permiso.empresa_id / sede_id)
    2. Permiso usuario PERMITIR
    3. Permiso rol DENEGAR (rol_permiso por contexto)
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

        $cacheKey = ($ruta ?? '') . '|' . ($accion ?? '');
        if (isset(self::$canCache[$cacheKey])) {
            return self::$canCache[$cacheKey];
        }

        $database = new Database();
        $pdo = $database->connect();

        $userId = $_SESSION['user_id'];

        $itemAccionId = self::resolveItemAccionId($pdo, $ruta, $accion);

        if (!$itemAccionId) {
            self::$canCache[$cacheKey] = false;

            return false;
        }

        $ok = self::checkItemAccionPermission($pdo, (int)$userId, (int)$itemAccionId);

        self::$canCache[$cacheKey] = $ok;

        return $ok;
    }

    /**
     * APIs JSON del formulario usuario (subida foto/firma, búsquedas): ver o guardar.
     */
    public static function canUsuarioFormApi(): bool
    {
        return self::can('usuario', 'ver') || self::can('usuario', 'guardar');
    }

    private static function resolveItemAccionId(PDO $pdo, $ruta, $accion): ?int
    {
        $rutasBuscar = [$ruta];

        if (strpos((string)$ruta, '/') !== false) {
            $raiz = explode('/', (string)$ruta)[0];
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
                return (int)$itemAccionId;
            }
        }

        return null;
    }

    /**
     * Evalúa permiso para un item_accion con contexto de sesión (empresa/sede).
     */
    private static function checkItemAccionPermission(PDO $pdo, int $userId, int $itemAccionId): bool
    {
        [$empresaId, $sedeId] = self::sessionEmpresaSede();

        $rolIds = self::rolIdsForUser($pdo, $userId);

        [$permScopeSql, $permScopeParams] = self::tenantScopeSql('p', $empresaId, $sedeId);

        /*
        ========================================
        1) USUARIO DENEGAR (alcance empresa/sede en tabla permiso)
        ========================================
        */

        $sqlUserDeny = "
            SELECT 1
            FROM permiso p
            WHERE p.usuario_id = ?
            AND p.item_accion_id = ?
            AND p.estado_id = 6
            $permScopeSql
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sqlUserDeny);
        $stmt->execute(array_merge([$userId, $itemAccionId], $permScopeParams));

        if ($stmt->fetch()) {
            return false;
        }

        /*
        ========================================
        2) USUARIO PERMITIR
        ========================================
        */

        $sqlUserAllow = "
            SELECT 1
            FROM permiso p
            WHERE p.usuario_id = ?
            AND p.item_accion_id = ?
            AND p.estado_id = 5
            $permScopeSql
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sqlUserAllow);
        $stmt->execute(array_merge([$userId, $itemAccionId], $permScopeParams));

        if ($stmt->fetch()) {
            return true;
        }

        /*
        ========================================
        ROL: requiere roles y rol_permiso acotado a contexto
        ========================================
        */

        if (empty($rolIds)) {
            return false;
        }

        [$scopeSql, $scopeParams] = self::tenantScopeSql('rp', $empresaId, $sedeId);

        $placeholders = implode(',', array_fill(0, count($rolIds), '?'));

        /*
        ========================================
        3) ROL DENEGAR
        ========================================
        */

        $sqlDeny = "
            SELECT 1
            FROM rol_permiso rp
            WHERE rp.rol_id IN ($placeholders)
            AND rp.item_accion_id = ?
            AND rp.estado_id = 6
            $scopeSql
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sqlDeny);
        $stmt->execute(array_merge($rolIds, [$itemAccionId], $scopeParams));

        if ($stmt->fetch()) {
            return false;
        }

        /*
        ========================================
        4) ROL PERMITIR
        ========================================
        */

        $sqlAllow = "
            SELECT 1
            FROM rol_permiso rp
            WHERE rp.rol_id IN ($placeholders)
            AND rp.item_accion_id = ?
            AND rp.estado_id = 5
            $scopeSql
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sqlAllow);
        $stmt->execute(array_merge($rolIds, [$itemAccionId], $scopeParams));

        return (bool) $stmt->fetch();
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

        $userId = (int)$_SESSION['user_id'];

        return self::checkItemAccionPermission($pdo, $userId, (int)$itemAccionId);
    }

    /**
     * Indica si el usuario tiene al menos un permiso "permitir" (estado 5):
     * en tabla permiso (usuario) o en rol_permiso (alguno de sus roles).
     * No filtra por contexto: sirve para bloqueo temprano en login.
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

        $rolIds = self::rolIdsForUserAllScopes($pdo, $userId);

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

    /**
     * Todos los rol_id activos en usuario_rol sin filtrar por empresa/sede de sesión.
     * Usado en login (userHasAssignedGrants) antes de tener contexto.
     *
     * @return int[]
     */
    private static function rolIdsForUserAllScopes(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare('
            SELECT DISTINCT ur.rol_id
            FROM usuario_rol ur
            WHERE ur.usuario_id = ?
            AND ur.estado_id = 1
        ');
        $stmt->execute([$userId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
