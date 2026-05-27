<?php

/**
 * Rutas bajo `?url=tercero/...`: CRUD automático en index; modales y APIs en el resto.
 */
class TerceroController
{
    private const TIPODOCUMENTO_NIT_ID = 9;

    private function isSuperAdmin(): bool
    {
        return class_exists('PermisoService')
            ? PermisoService::isSuperAdminSession()
            : (!empty($_SESSION['es_super_admin']) || (int)($_SESSION['rol_id'] ?? 0) === 1);
    }

    public function index(): void
    {
        require_once BASE_PATH . '/app/controllers/ModuleController.php';
        (new ModuleController())->index();
    }

    /**
     * Modal: identificaciones del tercero seleccionado en el CRUD.
     * Ruta: ?url=tercero/identificaciones/{terceroId}
     * POST _action: save | toggle | set_principal | delete (mutaciones solo superadmin)
     */
    public function identificaciones($terceroId = null): void
    {
        SessionManager::requireLogin();

        $tid = $terceroId !== null && $terceroId !== '' ? (int)$terceroId : 0;
        if ($tid <= 0) {
            $this->renderModalError('Seleccione un tercero en la tabla y vuelva a abrir la acción.');
            return;
        }

        if (class_exists('PermisoService') && !PermisoService::can('tercero', 'ver')) {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $this->jsonResponse(['success' => false, 'message' => 'Sin permiso para ver terceros.']);
            }
            $this->renderModalError('No tiene permiso para consultar identificaciones de terceros.');
            return;
        }

        $database = new Database();
        $pdo = $database->connect();

        $tercero = $this->fetchTerceroHeader($pdo, $tid);
        if ($tercero === null) {
            $this->renderModalError('Tercero no encontrado.');
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleIdentificacionesPost($pdo, $tid);
            return;
        }

        $tiposDocumento = $this->fetchTiposDocumento($pdo);
        $filas = $this->fetchIdentificaciones($pdo, $tid);
        $esSuperAdmin = $this->isSuperAdmin();

        require BASE_PATH . '/app/views/tercero/identificaciones_modal.php';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchTerceroHeader(PDO $pdo, int $terceroId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT id, nombres, apellidos, razon_social, tipopersona_id, estado_id
             FROM tercero WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$terceroId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchTiposDocumento(PDO $pdo): array
    {
        try {
            $stmt = $pdo->query(
                'SELECT id, nombre, codigo
                 FROM tipodocumento
                 WHERE estado_id = 1
                 ORDER BY nombre ASC, id ASC'
            );

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchIdentificaciones(PDO $pdo, int $terceroId): array
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT ti.id, ti.tercero_id, ti.tipodocumento_id, ti.numero, ti.dv, ti.principal,
                        ti.estado_id, ti.fecha_expedicion, ti.fecha_vencimiento, ti.observacion,
                        td.nombre AS tipo_documento_nombre, td.codigo AS tipo_documento_codigo
                 FROM terceroidentificacion ti
                 LEFT JOIN tipodocumento td ON td.id = ti.tipodocumento_id
                 WHERE ti.tercero_id = ?
                 ORDER BY ti.principal DESC, ti.estado_id ASC, ti.id ASC'
            );
            $stmt->execute([$terceroId]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    private function handleIdentificacionesPost(PDO $pdo, int $terceroId): void
    {
        if (!$this->isSuperAdmin()) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Solo un superadministrador puede modificar identificaciones.',
            ]);
            return;
        }

        $action = (string)($_POST['_action'] ?? '');

        if ($action === 'save') {
            $this->handleIdentificacionSave($pdo, $terceroId);
            return;
        }

        if ($action === 'toggle') {
            $this->handleIdentificacionToggle($pdo, $terceroId);
            return;
        }

        if ($action === 'set_principal') {
            $this->handleIdentificacionSetPrincipal($pdo, $terceroId);
            return;
        }

        if ($action === 'delete') {
            $this->handleIdentificacionDelete($pdo, $terceroId);
            return;
        }

        $this->jsonResponse(['success' => false, 'message' => 'Acción inválida.']);
    }

    private function handleIdentificacionSave(PDO $pdo, int $terceroId): void
    {
        $identId = (int)($_POST['identificacion_id'] ?? 0);
        $tipoDocId = (int)($_POST['tipodocumento_id'] ?? 0);
        $numero = trim((string)($_POST['numero'] ?? ''));
        $dvRaw = trim((string)($_POST['dv'] ?? ''));
        $principal = !empty($_POST['principal']) ? 1 : 0;
        $estadoId = (int)($_POST['estado_id'] ?? 1);
        $fechaExp = $this->nullableDate($_POST['fecha_expedicion'] ?? null);
        $fechaVen = $this->nullableDate($_POST['fecha_vencimiento'] ?? null);
        $observacion = trim((string)($_POST['observacion'] ?? ''));

        if ($tipoDocId <= 0) {
            $this->jsonResponse(['success' => false, 'message' => 'Seleccione el tipo de documento.']);
            return;
        }

        if ($numero === '') {
            $this->jsonResponse(['success' => false, 'message' => 'Indique el número de documento.']);
            return;
        }

        if (!in_array($estadoId, [1, 2], true)) {
            $estadoId = 1;
        }

        $dv = null;
        if ($tipoDocId === self::TIPODOCUMENTO_NIT_ID) {
            $dv = $dvRaw !== '' ? (int)$dvRaw : (int)$this->nitDvFromDigits($numero);
        } elseif ($dvRaw !== '') {
            $dv = (int)$dvRaw;
        }

        $st = $pdo->prepare(
            'SELECT id FROM terceroidentificacion
             WHERE tipodocumento_id = ? AND TRIM(numero) = TRIM(?)
               AND id <> ?
             LIMIT 1'
        );
        $st->execute([$tipoDocId, $numero, $identId > 0 ? $identId : 0]);
        if ($st->fetchColumn()) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'Ya existe otra identificación con ese tipo y número.',
            ]);
            return;
        }

        $pdo->beginTransaction();
        try {
            if ($identId > 0) {
                $own = $pdo->prepare(
                    'SELECT id FROM terceroidentificacion WHERE id = ? AND tercero_id = ? LIMIT 1'
                );
                $own->execute([$identId, $terceroId]);
                if (!$own->fetchColumn()) {
                    throw new RuntimeException('La identificación no pertenece a este tercero.');
                }

                $st = $pdo->prepare(
                    'UPDATE terceroidentificacion
                     SET tipodocumento_id = ?, numero = ?, dv = ?, principal = ?, estado_id = ?,
                         fecha_expedicion = ?, fecha_vencimiento = ?, observacion = ?
                     WHERE id = ? AND tercero_id = ?'
                );
                $st->execute([
                    $tipoDocId,
                    $numero,
                    $dv,
                    $principal,
                    $estadoId,
                    $fechaExp,
                    $fechaVen,
                    $observacion !== '' ? $observacion : null,
                    $identId,
                    $terceroId,
                ]);
                $msg = 'Identificación actualizada.';
            } else {
                $st = $pdo->prepare(
                    'INSERT INTO terceroidentificacion
                     (tercero_id, tipodocumento_id, numero, dv, principal, estado_id,
                      fecha_expedicion, fecha_vencimiento, observacion)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $st->execute([
                    $terceroId,
                    $tipoDocId,
                    $numero,
                    $dv,
                    $principal,
                    $estadoId,
                    $fechaExp,
                    $fechaVen,
                    $observacion !== '' ? $observacion : null,
                ]);
                $identId = (int)$pdo->lastInsertId();
                $msg = 'Identificación creada.';
            }

            if ($principal === 1) {
                $this->clearPrincipalExcept($pdo, $terceroId, $identId);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $this->jsonResponse(['success' => false, 'message' => 'No se pudo guardar: ' . $e->getMessage()]);
            return;
        }

        $this->jsonResponse([
            'success' => true,
            'message' => $msg,
            'identificaciones' => $this->fetchIdentificaciones($pdo, $terceroId),
        ]);
    }

    private function handleIdentificacionToggle(PDO $pdo, int $terceroId): void
    {
        $identId = (int)($_POST['identificacion_id'] ?? 0);
        if ($identId <= 0) {
            $this->jsonResponse(['success' => false, 'message' => 'Identificación no especificada.']);
            return;
        }

        $st = $pdo->prepare('SELECT estado_id FROM terceroidentificacion WHERE id = ? AND tercero_id = ? LIMIT 1');
        $st->execute([$identId, $terceroId]);
        $current = $st->fetchColumn();
        if ($current === false) {
            $this->jsonResponse(['success' => false, 'message' => 'Identificación no encontrada.']);
            return;
        }

        $new = ((int)$current === 1) ? 2 : 1;

        $st = $pdo->prepare('UPDATE terceroidentificacion SET estado_id = ? WHERE id = ? AND tercero_id = ?');
        $st->execute([$new, $identId, $terceroId]);

        $this->jsonResponse([
            'success' => true,
            'message' => $new === 1 ? 'Identificación activada.' : 'Identificación inactivada.',
            'identificaciones' => $this->fetchIdentificaciones($pdo, $terceroId),
        ]);
    }

    private function handleIdentificacionSetPrincipal(PDO $pdo, int $terceroId): void
    {
        $identId = (int)($_POST['identificacion_id'] ?? 0);
        if ($identId <= 0) {
            $this->jsonResponse(['success' => false, 'message' => 'Identificación no especificada.']);
            return;
        }

        $st = $pdo->prepare('SELECT 1 FROM terceroidentificacion WHERE id = ? AND tercero_id = ? LIMIT 1');
        $st->execute([$identId, $terceroId]);
        if (!$st->fetchColumn()) {
            $this->jsonResponse(['success' => false, 'message' => 'Identificación no encontrada.']);
            return;
        }

        $pdo->beginTransaction();
        try {
            $this->clearPrincipalExcept($pdo, $terceroId, $identId);
            $st = $pdo->prepare(
                'UPDATE terceroidentificacion SET principal = 1, estado_id = 1 WHERE id = ? AND tercero_id = ?'
            );
            $st->execute([$identId, $terceroId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $this->jsonResponse(['success' => false, 'message' => 'No se pudo asignar principal: ' . $e->getMessage()]);
            return;
        }

        $this->jsonResponse([
            'success' => true,
            'message' => 'Identificación marcada como principal.',
            'identificaciones' => $this->fetchIdentificaciones($pdo, $terceroId),
        ]);
    }

    private function handleIdentificacionDelete(PDO $pdo, int $terceroId): void
    {
        $identId = (int)($_POST['identificacion_id'] ?? 0);
        if ($identId <= 0) {
            $this->jsonResponse(['success' => false, 'message' => 'Identificación no especificada.']);
            return;
        }

        if ($this->countReferenciasUsuario($pdo, $identId) > 0) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'No se puede eliminar: hay usuarios vinculados a esta identificación.',
            ]);
            return;
        }

        $st = $pdo->prepare('SELECT COUNT(*) FROM empresa WHERE terceroidentificacion_id = ?');
        $st->execute([$identId]);
        if ((int)$st->fetchColumn() > 0) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'No se puede eliminar: hay empresas vinculadas a esta identificación (NIT).',
            ]);
            return;
        }

        $st = $pdo->prepare('SELECT COUNT(*) FROM empresa WHERE representante_terceroidentificacion_id = ?');
        $st->execute([$identId]);
        if ((int)$st->fetchColumn() > 0) {
            $this->jsonResponse([
                'success' => false,
                'message' => 'No se puede eliminar: está asignada como representante legal de una empresa.',
            ]);
            return;
        }

        try {
            $del = $pdo->prepare('DELETE FROM terceroidentificacion WHERE id = ? AND tercero_id = ?');
            $del->execute([$identId, $terceroId]);
        } catch (Throwable $e) {
            $this->jsonResponse(['success' => false, 'message' => 'No se pudo eliminar: ' . $e->getMessage()]);
            return;
        }

        if ($del->rowCount() === 0) {
            $this->jsonResponse(['success' => false, 'message' => 'Identificación no encontrada.']);
            return;
        }

        $this->jsonResponse([
            'success' => true,
            'message' => 'Identificación eliminada.',
            'identificaciones' => $this->fetchIdentificaciones($pdo, $terceroId),
        ]);
    }

    private function clearPrincipalExcept(PDO $pdo, int $terceroId, int $keepId): void
    {
        $st = $pdo->prepare(
            'UPDATE terceroidentificacion SET principal = 0 WHERE tercero_id = ? AND id <> ?'
        );
        $st->execute([$terceroId, $keepId]);
    }

    private function nullableDate(mixed $value): ?string
    {
        $v = trim((string)($value ?? ''));
        if ($v === '') {
            return null;
        }

        return $v;
    }

    private function countReferenciasUsuario(PDO $pdo, int $identificacionId): int
    {
        try {
            $st = $pdo->prepare('SELECT COUNT(*) FROM usuario WHERE terceroidentificacion_id = ?');
            $st->execute([$identificacionId]);

            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function nitDvFromDigits(string $digits): string
    {
        $s = preg_replace('/\D/', '', $digits) ?? '';
        if ($s === '') {
            return '0';
        }

        $factors = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];
        $sum = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $sum += (int)$s[$len - 1 - $i] * $factors[$i % count($factors)];
        }
        $r = $sum % 11;

        return (string)($r < 2 ? $r : 11 - $r);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonResponse(array $payload): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function renderModalError(string $message, string $title = 'Identificaciones del tercero'): void
    {
        echo '<div class="tercero-ident-modal modal-inner">'
            . '<header class="modal-form-head"><h3 class="modal-form-title">'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h3></header>'
            . '<p class="modal-form-alert">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<footer class="tercero-ident-footer">'
            . '<button type="button" class="btn-cancel" onclick="closeModalGod()">Cerrar</button>'
            . '</footer></div>';
        exit;
    }
}
