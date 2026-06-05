<?php

/**
 * Define el modo de guardado solo con datos de BD y sesión (spec §2).
 */
class UsuarioSaveModeResolver
{
    private PDO $pdo;
    private UsuarioFormValidationService $validator;

    public function __construct(?PDO $pdo = null)
    {
        if ($pdo !== null) {
            $this->pdo = $pdo;
        } else {
            $this->pdo = (new Database())->connect();
        }

        $this->validator = new UsuarioFormValidationService($this->pdo);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function resolve(array &$data): UsuarioSaveContext
    {
        $stale = $this->stripInvalidPostReferences($data);

        $rawId = $data['id'] ?? null;
        $idPost = ($rawId !== null && $rawId !== '' && (int)$rawId > 0) ? (int)$rawId : 0;

        if ($idPost <= 0 || !$this->usuarioRowExists($idPost)) {
            unset($data['id']);

            return new UsuarioSaveContext(UsuarioSaveMode::ALTA, null, $stale || $idPost > 0);
        }

        if ($this->validator->isSuperAdminViewer()
            || $this->validator->isSuperAdminUsuario($idPost)
            || $this->validator->usuarioVisibleInSessionScope($idPost)
        ) {
            $data['id'] = $idPost;

            return new UsuarioSaveContext(UsuarioSaveMode::EDICION, $idPost, $stale);
        }

        $data['id'] = $idPost;

        return new UsuarioSaveContext(UsuarioSaveMode::LINK_ONLY, $idPost, $stale);
    }

    /**
     * Limpia POST para re-render tras error (spec fase 5).
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public function sanitizeFormPostForDisplay(array $post): array
    {
        $stale = $this->stripInvalidPostReferences($post);

        if (!isset($post['id']) || $post['id'] === '' || (int)$post['id'] <= 0 || !$this->usuarioRowExists((int)$post['id'])) {
            $post['id'] = '';
        } else {
            $post['id'] = (int)$post['id'];
        }

        if ($stale && empty($post['usuario_form_stale_notice'])) {
            $post['usuario_form_stale_notice'] = '1';
        }

        return $post;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function stripInvalidPostReferences(array &$data): bool
    {
        $changed = false;

        $rawId = $data['id'] ?? null;
        if ($rawId !== null && $rawId !== '' && (int)$rawId > 0 && !$this->usuarioRowExists((int)$rawId)) {
            unset($data['id']);
            $changed = true;
        }

        $terceroId = isset($data['tercero_id']) && $data['tercero_id'] !== '' && $data['tercero_id'] !== null
            ? (int)$data['tercero_id']
            : 0;
        if ($terceroId > 0 && !$this->terceroRowExists($terceroId)) {
            $data['tercero_id'] = '';
            $changed = true;
        }

        $identId = isset($data['terceroidentificacion_id']) && $data['terceroidentificacion_id'] !== ''
            && $data['terceroidentificacion_id'] !== null
            ? (int)$data['terceroidentificacion_id']
            : 0;
        if ($identId > 0 && !$this->identificacionRowValid($identId)) {
            $data['terceroidentificacion_id'] = '';
            $changed = true;
        }

        return $changed;
    }

    private function usuarioRowExists(int $usuarioId): bool
    {
        if ($usuarioId <= 0 || !$this->tableExists('usuario')) {
            return false;
        }

        $sql = 'SELECT 1 FROM `usuario` WHERE `id` = ?';
        if (SoftDeleteService::supports($this->pdo, 'usuario')) {
            $sql .= SoftDeleteService::sqlAndNotDeleted($this->pdo, 'usuario');
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$usuarioId]);

        return (bool)$stmt->fetchColumn();
    }

    private function terceroRowExists(int $terceroId): bool
    {
        if ($terceroId <= 0 || !$this->tableExists('tercero')) {
            return false;
        }

        $sql = 'SELECT 1 FROM `tercero` WHERE `id` = ?';
        if (SoftDeleteService::supports($this->pdo, 'tercero')) {
            $sql .= SoftDeleteService::sqlAndNotDeleted($this->pdo, 'tercero');
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$terceroId]);

        return (bool)$stmt->fetchColumn();
    }

    private function identificacionRowValid(int $identId): bool
    {
        if ($identId <= 0 || !$this->tableExists('terceroidentificacion')) {
            return false;
        }

        $stmt = $this->pdo->prepare('SELECT `tercero_id` FROM `terceroidentificacion` WHERE `id` = ? LIMIT 1');
        $stmt->execute([$identId]);
        $tid = (int)($stmt->fetchColumn() ?: 0);

        return $tid > 0 && $this->terceroRowExists($tid);
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare('
            SELECT 1 FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            LIMIT 1
        ');
        $stmt->execute([$table]);

        return (bool)$stmt->fetchColumn();
    }
}
