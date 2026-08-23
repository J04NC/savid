<?php

class UserAccessRepository
{
    /** Reintentos ante deadlock/lock wait al guardar permisos por lote. */
    private const MAX_INTENTOS_LOCK = 3;

    /** Espera incremental entre reintentos (se multiplica por el nº de intento). */
    private const ESPERA_BASE_REINTENTO_US = 50000;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findActiveUserById($usuarioId)
    {
        $uNd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'usuario', 'u');

        $stmt = $this->pdo->prepare("
            SELECT u.*,
                COALESCE(
                    NULLIF(TRIM(CONCAT_WS(' ', t.nombres, t.apellidos)), ''),
                    u.username
                ) AS nombre
            FROM usuario u
            LEFT JOIN terceroidentificacion ti ON ti.id = u.terceroidentificacion_id
            LEFT JOIN tercero t ON t.id = ti.tercero_id
            WHERE u.id = ?
            AND u.estado_id = 1
            {$uNd}
            LIMIT 1
        ");
        $stmt->execute([$usuarioId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findAllActiveRoles()
    {
        $stmt = $this->pdo->query("
            SELECT id, nombre
            FROM rol
            WHERE estado_id = 1
            ORDER BY nombre
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Asignaciones activas rol + alcance (empresa/sede NULL = global o según negocio).
     *
     * @return array<int, array{rol_id:int, empresa_id:?int, sede_id:?int}>
     */
    public function findRoleAssignmentsByUserId(int $usuarioId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT rol_id, empresa_id, sede_id
            FROM usuario_rol
            WHERE usuario_id = ?
            AND estado_id = 1
            ORDER BY rol_id, empresa_id, sede_id
        ");
        $stmt->execute([$usuarioId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];

        foreach ($rows as $r) {
            $out[] = [
                'rol_id' => (int)$r['rol_id'],
                'empresa_id' => $r['empresa_id'] === null || $r['empresa_id'] === '' ? null : (int)$r['empresa_id'],
                'sede_id' => $r['sede_id'] === null || $r['sede_id'] === '' ? null : (int)$r['sede_id'],
            ];
        }

        return $out;
    }

    public function deleteRolesByUserId($usuarioId)
    {
        $stmt = $this->pdo->prepare("DELETE FROM usuario_rol WHERE usuario_id = ?");
        $stmt->execute([$usuarioId]);
    }

    public function insertUserRole(int $usuarioId, int $rolId, ?int $empresaId, ?int $sedeId): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO usuario_rol (usuario_id, rol_id, empresa_id, sede_id, estado_id)
            VALUES (?, ?, ?, ?, 1)
        ");
        $stmt->execute([$usuarioId, $rolId, $empresaId, $sedeId]);
    }

    /**
     * @param list<int>|null $allowedItemIds si viene no-null, restringe a esos item_id
     *        (ítems habilitados para la empresa en foco vía empresa_item). Null = sin
     *        restringir (solo cuando el superadmin ve "todas las empresas").
     */
    public function getPermissionMatrixRows(?array $allowedItemIds = null)
    {
        $itemNd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'item', 'i');
        $modNd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'modulo', 'm');
        $accNd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'accion', 'a');

        $params = [];
        $allowedSql = '';
        if ($allowedItemIds !== null) {
            if ($allowedItemIds === []) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($allowedItemIds), '?'));
            $allowedSql = "AND i.id IN ({$placeholders})";
            $params = $allowedItemIds;
        }

        $stmt = $this->pdo->prepare("
            SELECT
                m.nombre AS modulo,
                i.id AS item_id,
                i.nombre AS item,
                a.id AS accion_id,
                a.nombre AS accion,
                a.codigo,
                ia.id AS item_accion_id
            FROM item_accion ia
            JOIN item i ON i.id = ia.item_id
            JOIN modulo m ON m.id = i.modulo_id
            JOIN accion a ON a.id = ia.accion_id
            WHERE ia.estado_id = 1
            AND i.estado_id = 1
            {$itemNd}
            {$modNd}
            {$accNd}
            {$allowedSql}
            ORDER BY m.id, i.orden, a.id
        ");
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Permisos directos del usuario para el contexto (empresa/sede) indicado.
     * Coincide con la lógica de alcance de PermisoService::tenantScopeSql.
     */
    public function getAllowedPermissionItemAccionIdsForScope(int $usuarioId, ?int $empresaId, ?int $sedeId): array
    {
        [$scopeSql, $scopeParams] = PermisoService::tenantScopeSql('p', $empresaId, $sedeId);

        $sql = "
            SELECT DISTINCT p.item_accion_id
            FROM permiso p
            WHERE p.usuario_id = ?
            AND p.estado_id = 5
            $scopeSql
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$usuarioId], $scopeParams));

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Permisos denegados explícitos (estado 6) para el alcance indicado.
     *
     * @return int[]
     */
    public function getDeniedPermissionItemAccionIdsForScope(int $usuarioId, ?int $empresaId, ?int $sedeId): array
    {
        [$scopeSql, $scopeParams] = PermisoService::tenantScopeSql('p', $empresaId, $sedeId);

        $sql = "
            SELECT DISTINCT p.item_accion_id
            FROM permiso p
            WHERE p.usuario_id = ?
            AND p.estado_id = 6
            $scopeSql
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$usuarioId], $scopeParams));

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Permisos concedidos vía roles del usuario (rol_permiso) para el mismo alcance empresa/sede
     * que usuario_rol y rol_permiso (alineado con PermisoService::checkItemAccionPermission).
     *
     * @return int[]
     */
    public function getRoleGrantedItemAccionIdsForUserScope(int $usuarioId, ?int $empresaId, ?int $sedeId): array
    {
        [$urSql, $urParams] = PermisoService::tenantScopeSql('ur', $empresaId, $sedeId);
        [$rpSql, $rpParams] = PermisoService::tenantScopeSql('rp', $empresaId, $sedeId);

        $sql = "
            SELECT DISTINCT rp.item_accion_id
            FROM usuario_rol ur
            INNER JOIN rol_permiso rp ON rp.rol_id = ur.rol_id AND rp.estado_id = 5
            WHERE ur.usuario_id = ?
            AND ur.estado_id = 1
            $urSql
            $rpSql
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$usuarioId], $urParams, $rpParams));

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function insertAllowedPermission(int $usuarioId, int $itemAccionId, ?int $empresaId, ?int $sedeId): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO permiso (usuario_id, item_accion_id, estado_id, empresa_id, sede_id)
            VALUES (?, ?, 5, ?, ?)
        ');
        $stmt->execute([$usuarioId, $itemAccionId, $empresaId, $sedeId]);
    }

    public function insertDeniedPermission(int $usuarioId, int $itemAccionId, ?int $empresaId, ?int $sedeId): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO permiso (usuario_id, item_accion_id, estado_id, empresa_id, sede_id)
            VALUES (?, ?, 6, ?, ?)
        ');
        $stmt->execute([$usuarioId, $itemAccionId, $empresaId, $sedeId]);
    }

    /**
     * Elimina cualquier fila permiso del usuario para ese item_accion.
     * Necesario porque uk_permiso es (usuario_id, item_accion_id) sin empresa/sede.
     */
    public function deletePermissionForUserItem(int $usuarioId, int $itemAccionId): void
    {
        $stmt = $this->pdo->prepare('
            DELETE FROM permiso
            WHERE usuario_id = ?
            AND item_accion_id = ?
        ');
        $stmt->execute([$usuarioId, $itemAccionId]);
    }

    /**
     * Igual que deletePermissionForUserItem pero para varios item_accion en una
     * sola sentencia.
     *
     * Con REPEATABLE READ, un DELETE que no encuentra fila deja gap locks. Al
     * hacerlo fila por fila sobre un lote grande (p. ej. un módulo completo) se
     * acumulaban decenas de gaps sostenidos durante toda la transacción, lo que
     * bloqueaba los INSERT de cualquier petición concurrente hasta agotar el
     * innodb_lock_wait_timeout (50s). Una sola sentencia acota ese bloqueo.
     *
     * @param int[] $itemAccionIds
     */
    public function deletePermissionsForUserItems(int $usuarioId, array $itemAccionIds): void
    {
        $itemAccionIds = array_values(array_unique(array_map('intval', $itemAccionIds)));

        if ($itemAccionIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($itemAccionIds), '?'));
        $stmt = $this->pdo->prepare("
            DELETE FROM permiso
            WHERE usuario_id = ?
            AND item_accion_id IN ({$placeholders})
        ");
        $stmt->execute(array_merge([$usuarioId], $itemAccionIds));
    }

    /**
     * Quita cualquier fila permiso (permitir o denegar) para ese alcance.
     */
    public function deletePermissionForScope(int $usuarioId, int $itemAccionId, ?int $empresaId, ?int $sedeId): void
    {
        $stmt = $this->pdo->prepare('
            DELETE FROM permiso
            WHERE usuario_id = ?
            AND item_accion_id = ?
            AND empresa_id <=> ?
            AND sede_id <=> ?
        ');
        $stmt->execute([$usuarioId, $itemAccionId, $empresaId, $sedeId]);
    }

    public function deleteAllowedPermission(int $usuarioId, int $itemAccionId, ?int $empresaId, ?int $sedeId): void
    {
        $stmt = $this->pdo->prepare('
            DELETE FROM permiso
            WHERE usuario_id = ?
            AND item_accion_id = ?
            AND estado_id = 5
            AND empresa_id <=> ?
            AND sede_id <=> ?
        ');
        $stmt->execute([$usuarioId, $itemAccionId, $empresaId, $sedeId]);
    }

    /**
     * @param int[] $removeIds eliminar fila permiso (cualquier estado) en el alcance
     * @param int[] $grantIds insertar permitir (5) — solo ítems sin concesión por rol en este alcance
     * @param int[] $denyIds insertar denegar (6) — solo ítems con concesión por rol en este alcance
     */
    public function applyUsuarioPermisosMutations(
        int $usuarioId,
        array $removeIds,
        array $grantIds,
        array $denyIds,
        ?int $empresaId,
        ?int $sedeId
    ): void {
        $removeIds = array_values(array_unique(array_map('intval', $removeIds)));
        $grantIds = array_values(array_unique(array_map('intval', $grantIds)));
        $denyIds = array_values(array_unique(array_map('intval', $denyIds)));

        // Un solo DELETE para todo el lote (los tres grupos son disjuntos): deja
        // la tabla en el mismo estado que el bucle anterior pero mantiene la
        // transacción corta y acota los gap locks. Ver deletePermissionsForUserItems.
        $affectedIds = array_values(array_unique(array_merge($removeIds, $grantIds, $denyIds)));

        if ($affectedIds === []) {
            return;
        }

        // Dos guardados simultáneos sobre el mismo usuario pueden cruzarse en un
        // deadlock (1213) o agotar el lock wait (1205). En ambos casos InnoDB
        // deshace la transacción completa, así que reintentar es seguro.
        $intentos = 0;

        while (true) {
            $intentos++;

            try {
                $this->runUsuarioPermisosMutations(
                    $usuarioId,
                    $affectedIds,
                    $grantIds,
                    $denyIds,
                    $empresaId,
                    $sedeId
                );

                return;
            } catch (\PDOException $e) {
                if ($intentos >= self::MAX_INTENTOS_LOCK || !self::esErrorDeLockReintentable($e)) {
                    throw $e;
                }

                usleep(self::ESPERA_BASE_REINTENTO_US * $intentos);
            }
        }
    }

    /**
     * @param int[] $affectedIds
     * @param int[] $grantIds
     * @param int[] $denyIds
     */
    private function runUsuarioPermisosMutations(
        int $usuarioId,
        array $affectedIds,
        array $grantIds,
        array $denyIds,
        ?int $empresaId,
        ?int $sedeId
    ): void {
        $this->pdo->beginTransaction();

        try {
            $this->deletePermissionsForUserItems($usuarioId, $affectedIds);

            foreach ($grantIds as $itemAccionId) {
                $this->insertAllowedPermission($usuarioId, $itemAccionId, $empresaId, $sedeId);
            }

            foreach ($denyIds as $itemAccionId) {
                $this->insertDeniedPermission($usuarioId, $itemAccionId, $empresaId, $sedeId);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            // Ante un deadlock InnoDB ya deshizo la transacción por su cuenta;
            // rollBack() lanzaría "no active transaction" y taparía el error real.
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    private static function esErrorDeLockReintentable(\PDOException $e): bool
    {
        $codigo = (int)($e->errorInfo[1] ?? 0);

        // 1213 = deadlock, 1205 = lock wait timeout.
        return $codigo === 1213 || $codigo === 1205;
    }
}
