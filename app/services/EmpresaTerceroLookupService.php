<?php

/**
 * Lookup por NIT para el formulario CRUD de empresa.
 *
 * Casos:
 *   - none             → NIT no existe en terceroidentificacion (se podrá crear todo nuevo).
 *   - tercero_only     → NIT existe asociado a un tercero pero ninguna empresa lo usa.
 *                        Al guardar la nueva empresa se reusará ese tercero.
 *   - empresa_exists   → NIT ya pertenece a otra empresa. El guardado debe bloquearse.
 *   - same_empresa     → NIT pertenece a la empresa que se está editando (sin cambios).
 *   - inactive_tercero → El tercero correspondiente al NIT está inactivo.
 */
class EmpresaTerceroLookupService
{
    public const TIPODOCUMENTO_NIT_ID = 9;
    public const TIPOPERSONA_JURIDICA_ID = 2;

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        if ($pdo !== null) {
            $this->pdo = $pdo;
        } else {
            $database = new Database();
            $this->pdo = $database->connect();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function lookupNit(string $nit, ?int $excludeEmpresaId = null): array
    {
        $nit = trim($nit);
        if ($nit === '') {
            return ['status' => 'none'];
        }

        $row = $this->fetchIdentificacionRow($nit);
        if ($row === null) {
            return ['status' => 'none'];
        }

        $terceroId = (int)$row['tercero_id'];
        $identificacionId = (int)$row['identificacion_id'];

        $tercero = $this->fetchTercero($terceroId);
        if ($tercero === null) {
            return ['status' => 'none'];
        }

        $estadoTercero = isset($tercero['estado_id']) ? (int)$tercero['estado_id'] : 1;
        if ($estadoTercero !== 1) {
            return [
                'status' => 'inactive_tercero',
                'blocked' => true,
                'message' => 'El tercero correspondiente a este NIT está inactivo. Reactívelo antes de continuar.',
                'tercero_id' => $terceroId,
                'terceroidentificacion_id' => $identificacionId,
            ];
        }

        $empresa = $this->fetchEmpresaByTercero($terceroId);

        if ($empresa !== null) {
            $empresaId = (int)$empresa['id'];

            if ($excludeEmpresaId !== null && $empresaId === $excludeEmpresaId) {
                return [
                    'status' => 'same_empresa',
                    'blocked' => false,
                    'message' => 'NIT vinculado a la empresa actual.',
                    'tercero_id' => $terceroId,
                    'terceroidentificacion_id' => $identificacionId,
                    'tercero' => $this->buildPrefill($tercero),
                ];
            }

            return [
                'status' => 'empresa_exists',
                'blocked' => true,
                'message' => 'Ya existe una empresa con este NIT (' . htmlspecialchars((string)$empresa['razon_social'], ENT_QUOTES, 'UTF-8') . ').',
                'empresa_id' => $empresaId,
                'tercero_id' => $terceroId,
                'terceroidentificacion_id' => $identificacionId,
            ];
        }

        return [
            'status' => 'tercero_only',
            'blocked' => false,
            'message' => 'Ya existe un tercero con este NIT. Se completaron los datos comunes. Al guardar se reusará ese tercero.',
            'tercero_id' => $terceroId,
            'terceroidentificacion_id' => $identificacionId,
            'tercero' => $this->buildPrefill($tercero),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchIdentificacionRow(string $nit): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ti.id AS identificacion_id, ti.tercero_id, ti.numero
               FROM terceroidentificacion ti
              WHERE ti.tipodocumento_id = ?
                AND TRIM(ti.numero) = TRIM(?)
                AND (ti.estado_id IS NULL OR ti.estado_id = 1)
              ORDER BY ti.principal DESC, ti.id ASC
              LIMIT 1'
        );
        $stmt->execute([self::TIPODOCUMENTO_NIT_ID, $nit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchTercero(int $terceroId): ?array
    {
        if ($terceroId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, razon_social, email, telefono, direccion, estado_id, tipopersona_id
               FROM tercero
              WHERE id = ?
              LIMIT 1'
        );
        $stmt->execute([$terceroId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchEmpresaByTercero(int $terceroId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, razon_social
               FROM empresa
              WHERE tercero_id = ?
              ORDER BY id ASC
              LIMIT 1'
        );
        $stmt->execute([$terceroId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $tercero
     * @return array<string, mixed>
     */
    private function buildPrefill(array $tercero): array
    {
        return [
            'razon_social' => (string)($tercero['razon_social'] ?? ''),
            'email' => (string)($tercero['email'] ?? ''),
            'telefono' => (string)($tercero['telefono'] ?? ''),
            'direccion' => (string)($tercero['direccion'] ?? ''),
        ];
    }
}
