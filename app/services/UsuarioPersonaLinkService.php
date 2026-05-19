<?php

/**
 * Resuelve el vínculo usuario ↔ persona (tercero_id legado o terceroidentificacion_id).
 */
class UsuarioPersonaLinkService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        if ($pdo !== null) {
            $this->pdo = $pdo;

            return;
        }

        $database = new Database();
        $this->pdo = $database->connect();
    }

    /**
     * Columna en `usuario` que enlaza la cuenta con la persona.
     */
    public function personaLinkColumn(array $usuarioColumnNames): ?string
    {
        if (in_array('terceroidentificacion_id', $usuarioColumnNames, true)) {
            return 'terceroidentificacion_id';
        }
        if (in_array('tercero_id', $usuarioColumnNames, true)) {
            return 'tercero_id';
        }

        return null;
    }

    public function usesIdentificacionLink(array $usuarioColumnNames): bool
    {
        return in_array('terceroidentificacion_id', $usuarioColumnNames, true);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function linkValueFromData(array $data, ?string $linkColumn = null, ?array $usuarioColumnNames = null): ?int
    {
        if ($linkColumn === null && $usuarioColumnNames !== null) {
            $linkColumn = $this->personaLinkColumn($usuarioColumnNames);
        }
        if ($linkColumn === null) {
            return null;
        }

        if (!isset($data[$linkColumn]) || $data[$linkColumn] === '' || $data[$linkColumn] === null) {
            return null;
        }

        $v = (int)$data[$linkColumn];

        return $v > 0 ? $v : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function linkValueFromRow(array $row, ?string $linkColumn = null, ?array $usuarioColumnNames = null): ?int
    {
        return $this->linkValueFromData($row, $linkColumn, $usuarioColumnNames);
    }

    /**
     * Tercero maestro asociado al vínculo del usuario (desde identificación o FK directa).
     */
    public function resolveTerceroId(?int $linkValue, ?string $linkColumn): ?int
    {
        if ($linkValue === null || $linkValue <= 0 || $linkColumn === null) {
            return null;
        }

        if ($linkColumn === 'tercero_id') {
            return $linkValue;
        }

        if ($linkColumn === 'terceroidentificacion_id' && $this->tableExists('terceroidentificacion')) {
            $stmt = $this->pdo->prepare('SELECT tercero_id FROM terceroidentificacion WHERE id = ? LIMIT 1');
            $stmt->execute([$linkValue]);
            $tid = $stmt->fetchColumn();

            return $tid !== false && $tid !== null ? (int)$tid : null;
        }

        return null;
    }

    /**
     * Identificación enlazada al usuario (o principal del tercero si el vínculo es tercero_id).
     */
    public function resolveIdentificacionId(?int $linkValue, ?string $linkColumn): ?int
    {
        if ($linkValue === null || $linkValue <= 0 || $linkColumn === null) {
            return null;
        }

        if ($linkColumn === 'terceroidentificacion_id') {
            return $linkValue;
        }

        if ($linkColumn === 'tercero_id') {
            $principal = $this->fetchPrincipalIdentificacionId($linkValue);

            return $principal > 0 ? $principal : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function resolveTerceroIdFromData(array $data, array $usuarioColumnNames): ?int
    {
        if (isset($data['tercero_id']) && $data['tercero_id'] !== '' && $data['tercero_id'] !== null) {
            $tid = (int)$data['tercero_id'];
            if ($tid > 0) {
                return $tid;
            }
        }

        $linkColumn = $this->personaLinkColumn($usuarioColumnNames);
        $linkValue = $this->linkValueFromData($data, $linkColumn);

        return $this->resolveTerceroId($linkValue, $linkColumn);
    }

    /**
     * Asigna el FK de persona en $data tras crear/actualizar identificación.
     *
     * @param array<string, mixed> $data
     */
    public function assignPersonaLink(array &$data, int $identificacionId, array $usuarioColumnNames): void
    {
        if ($identificacionId <= 0) {
            return;
        }

        if ($this->usesIdentificacionLink($usuarioColumnNames)) {
            $data['terceroidentificacion_id'] = $identificacionId;
            if (!in_array('tercero_id', $usuarioColumnNames, true)) {
                unset($data['tercero_id']);
            }
        } elseif (in_array('tercero_id', $usuarioColumnNames, true)) {
            $tid = $this->resolveTerceroId($identificacionId, 'terceroidentificacion_id');
            if ($tid !== null && $tid > 0) {
                $data['tercero_id'] = $tid;
            }
        }
    }

    public function fetchPrincipalIdentificacionId(int $terceroId): int
    {
        if ($terceroId <= 0 || !$this->tableExists('terceroidentificacion')) {
            return 0;
        }

        $stmt = $this->pdo->prepare('
            SELECT id FROM terceroidentificacion
            WHERE tercero_id = ?
            ORDER BY principal DESC, id ASC
            LIMIT 1
        ');
        $stmt->execute([$terceroId]);
        $v = $stmt->fetchColumn();

        return $v !== false && $v !== null ? (int)$v : 0;
    }

    public function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare('
            SELECT 1 FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            LIMIT 1
        ');
        $stmt->execute([$table]);

        return (bool)$stmt->fetchColumn();
    }

    /**
     * @return list<string>
     */
    public function getUsuarioColumnNames(): array
    {
        if (!$this->tableExists('usuario')) {
            return [];
        }

        $stmt = $this->pdo->query('SHOW COLUMNS FROM `usuario`');

        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    }
}
