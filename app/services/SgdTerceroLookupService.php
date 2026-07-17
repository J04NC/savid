<?php

/**
 * Búsqueda ligera de terceros para autocompletar bloques operativos SGD.
 */
class SgdTerceroLookupService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        if ($pdo !== null) {
            $this->pdo = $pdo;
        } else {
            $this->pdo = (new Database())->connect();
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $q, int $limit = 15): array
    {
        $q = trim($q);
        if ($q === '' || mb_strlen($q) < 2) {
            return [];
        }
        $limit = max(1, min($limit, 30));
        $like = '%' . $q . '%';

        $hasMunicipio = $this->tableExists('municipio');
        $municipioJoin = $hasMunicipio
            ? 'LEFT JOIN municipio m ON m.id = t.municipio_id'
            : '';
        $municipioSelect = $hasMunicipio ? ', m.nombre AS municipio_nombre' : ', NULL AS municipio_nombre';

        $sql = "SELECT t.id AS tercero_id, t.razon_social, t.telefono, t.celular, t.direccion,
                       ti.numero AS nit, ti.dv AS nit_dv
                       {$municipioSelect}
                  FROM tercero t
                  LEFT JOIN terceroidentificacion ti ON ti.tercero_id = t.id
                    AND ti.tipodocumento_id = 9
                    AND (ti.estado_id IS NULL OR ti.estado_id = 1)
                    AND ti.principal = 1
                  {$municipioJoin}
                 WHERE (t.estado_id IS NULL OR t.estado_id = 1)
                   AND (t.razon_social LIKE ? OR ti.numero LIKE ?)
                 ORDER BY t.razon_social ASC
                 LIMIT {$limit}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$like, $like]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $nit = trim((string)($row['nit'] ?? ''));
            $dv = trim((string)($row['nit_dv'] ?? ''));
            if ($nit !== '' && $dv !== '') {
                $nit .= '-' . $dv;
            }
            $out[] = [
                'tercero_id' => (int)$row['tercero_id'],
                'razon_social' => trim((string)($row['razon_social'] ?? '')),
                'nit' => $nit,
                'telefono' => trim((string)($row['telefono'] ?? $row['celular'] ?? '')),
                'direccion' => trim((string)($row['direccion'] ?? '')),
                'municipio' => trim((string)($row['municipio_nombre'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $terceroId): ?array
    {
        if ($terceroId <= 0) {
            return null;
        }

        $hasMunicipio = $this->tableExists('municipio');
        $municipioJoin = $hasMunicipio ? 'LEFT JOIN municipio m ON m.id = t.municipio_id' : '';
        $municipioSelect = $hasMunicipio ? ', m.nombre AS municipio_nombre' : ', NULL AS municipio_nombre';

        $stmt = $this->pdo->prepare(
            "SELECT t.id AS tercero_id, t.razon_social, t.telefono, t.celular, t.direccion,
                    ti.numero AS nit, ti.dv AS nit_dv {$municipioSelect}
               FROM tercero t
               LEFT JOIN terceroidentificacion ti ON ti.tercero_id = t.id
                 AND ti.tipodocumento_id = 9 AND ti.principal = 1
               {$municipioJoin}
              WHERE t.id = ? LIMIT 1"
        );
        $stmt->execute([$terceroId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $nit = trim((string)($row['nit'] ?? ''));
        $dv = trim((string)($row['nit_dv'] ?? ''));
        if ($nit !== '' && $dv !== '') {
            $nit .= '-' . $dv;
        }

        return [
            'tercero_id' => (int)$row['tercero_id'],
            'razon_social' => trim((string)($row['razon_social'] ?? '')),
            'nit' => $nit,
            'telefono' => trim((string)($row['telefono'] ?? $row['celular'] ?? '')),
            'direccion' => trim((string)($row['direccion'] ?? '')),
            'municipio' => trim((string)($row['municipio_nombre'] ?? '')),
        ];
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
        );
        $stmt->execute([$table]);

        return (bool)$stmt->fetchColumn();
    }
}
