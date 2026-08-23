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

        $identRow = ['dv' => $row['dv'] ?? null];

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
                    'tercero' => $this->buildPrefill($tercero, $identRow),
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
            'tercero' => $this->buildPrefill($tercero, $identRow),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchIdentificacionRow(string $nit): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ti.id AS identificacion_id, ti.tercero_id, ti.numero, ti.dv
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
            'SELECT id, razon_social, email, telefono, celular, direccion,
                    pais_id, departamento_id, municipio_id, zona_id,
                    comuna_id, barrio_id,
                    estado_id, tipopersona_id
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
            'SELECT e.id, t.razon_social
               FROM empresa e
               INNER JOIN tercero t ON t.id = e.tercero_id
              WHERE e.tercero_id = ?
              ORDER BY e.id ASC
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
    /**
     * @param array<string, mixed> $tercero
     * @param array<string, mixed>|null $ident
     */
    private function buildPrefill(array $tercero, ?array $ident = null): array
    {
        $prefill = [
            'razon_social' => (string)($tercero['razon_social'] ?? ''),
            'email' => (string)($tercero['email'] ?? ''),
            'telefono' => (string)($tercero['telefono'] ?? ''),
            'celular' => (string)($tercero['celular'] ?? ''),
            'direccion' => (string)($tercero['direccion'] ?? ''),
            'pais_id' => $tercero['pais_id'] ?? '',
            'departamento_id' => $tercero['departamento_id'] ?? '',
            'municipio_id' => $tercero['municipio_id'] ?? '',
            'zona_id' => $tercero['zona_id'] ?? '',
            'comuna_id' => $tercero['comuna_id'] ?? '',
            'barrio_id' => $tercero['barrio_id'] ?? '',
        ];

        if ($ident !== null && isset($ident['dv']) && $ident['dv'] !== null && $ident['dv'] !== '') {
            $prefill['documento_dv'] = (string)$ident['dv'];
        }

        return $prefill;
    }

    /**
     * Búsqueda para autocompletar representante legal (persona natural, no NIT).
     *
     * @return list<array<string, mixed>>
     */
    public function searchRepresentante(string $term, int $limit = 15): array
    {
        $term = trim($term);
        if ($term === '' || mb_strlen($term) < 2) {
            return [];
        }

        $limit = max(1, min(25, $limit));
        $like = '%' . $term . '%';

        $stmt = $this->pdo->prepare(
            'SELECT ti.id AS identificacion_id, ti.tipodocumento_id, ti.numero,
                    t.nombres, t.apellidos, td.nombre AS tipo_doc
               FROM terceroidentificacion ti
               INNER JOIN tercero t ON t.id = ti.tercero_id
               LEFT JOIN tipodocumento td ON td.id = ti.tipodocumento_id
              WHERE ti.tipodocumento_id <> ?
                AND (ti.estado_id IS NULL OR ti.estado_id = 1)
                AND (t.estado_id IS NULL OR t.estado_id = 1)
                AND (t.tipopersona_id IS NULL OR t.tipopersona_id = 1)
                AND (
                    ti.numero LIKE ?
                    OR t.nombres LIKE ?
                    OR t.apellidos LIKE ?
                    OR CONCAT(COALESCE(t.nombres, ""), " ", COALESCE(t.apellidos, "")) LIKE ?
                )
              ORDER BY t.nombres ASC, t.apellidos ASC, ti.numero ASC
              LIMIT ' . (int)$limit
        );
        $stmt->execute([
            self::TIPODOCUMENTO_NIT_ID,
            $like,
            $like,
            $like,
            $like,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];

        foreach ($rows as $r) {
            $identId = (int)($r['identificacion_id'] ?? 0);
            if ($identId <= 0) {
                continue;
            }
            $tipoDoc = trim((string)($r['tipo_doc'] ?? ''));
            $numero = trim((string)($r['numero'] ?? ''));
            $nombre = trim((string)($r['nombres'] ?? '') . ' ' . (string)($r['apellidos'] ?? ''));
            $label = trim(($tipoDoc !== '' ? $tipoDoc . ' ' : '') . $numero . ($nombre !== '' ? ' · ' . $nombre : ''));

            $out[] = [
                'identificacion_id' => $identId,
                'tipodocumento_id' => (int)($r['tipodocumento_id'] ?? 0),
                'numero' => $numero,
                'nombres' => (string)($r['nombres'] ?? ''),
                'apellidos' => (string)($r['apellidos'] ?? ''),
                'label' => $label !== '' ? $label : ('#' . $identId),
            ];
        }

        return $out;
    }

    /**
     * Lookup exacto tipo + número para representante legal.
     *
     * @return array<string, mixed>
     */
    public function lookupRepresentanteByDocumento(int $tipodocumentoId, string $numero): array
    {
        $numero = trim($numero);
        if ($tipodocumentoId <= 0 || $numero === '') {
            return ['status' => 'none'];
        }

        if ($tipodocumentoId === self::TIPODOCUMENTO_NIT_ID) {
            return [
                'status' => 'blocked',
                'blocked' => true,
                'message' => 'El representante legal debe ser una persona natural; use un documento distinto al NIT.',
            ];
        }

        $stmt = $this->pdo->prepare(
            'SELECT ti.id AS identificacion_id, ti.tercero_id, ti.tipodocumento_id, ti.numero,
                    t.nombres, t.apellidos, t.estado_id AS tercero_estado_id
               FROM terceroidentificacion ti
               INNER JOIN tercero t ON t.id = ti.tercero_id
              WHERE ti.tipodocumento_id = ?
                AND TRIM(ti.numero) = TRIM(?)
                AND (ti.estado_id IS NULL OR ti.estado_id = 1)
              ORDER BY ti.principal DESC, ti.id ASC
              LIMIT 1'
        );
        $stmt->execute([$tipodocumentoId, $numero]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return ['status' => 'none'];
        }

        $estadoTercero = isset($row['tercero_estado_id']) ? (int)$row['tercero_estado_id'] : 1;
        if ($estadoTercero !== 1) {
            return [
                'status' => 'inactive_tercero',
                'blocked' => true,
                'message' => 'El tercero con este documento está inactivo.',
            ];
        }

        return [
            'status' => 'found',
            'representante_terceroidentificacion_id' => (int)$row['identificacion_id'],
            'rep_tipodocumento_id' => (int)$row['tipodocumento_id'],
            'rep_numero_documento' => (string)$row['numero'],
            'rep_nombres' => (string)($row['nombres'] ?? ''),
            'rep_apellidos' => (string)($row['apellidos'] ?? ''),
        ];
    }
}
