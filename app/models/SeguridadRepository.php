<?php

class SeguridadRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return array{total: int, conDosFactor: int}
     */
    public function estadisticasDosFactor(): array
    {
        $uNd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'usuario', 'u');

        $stmt = $this->pdo->query("
            SELECT COUNT(*) AS total, SUM(u.two_factor_enabled) AS con_2fa
            FROM usuario u
            WHERE u.estado_id = 1
            {$uNd}
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int)($row['total'] ?? 0),
            'conDosFactor' => (int)($row['con_2fa'] ?? 0),
        ];
    }

    /**
     * Usuarios activos sin sesión registrada en los últimos $diasUmbral días (o que nunca
     * han iniciado sesión), candidatos a desactivar.
     *
     * @return list<array{id: int, username: string, ultimo_login: ?string, empresas: ?string}>
     */
    public function cuentasInactivas(int $diasUmbral): array
    {
        $uNd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'usuario', 'u');
        $usNd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'usuario_sesion', 'us');
        $ueNd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'usuario_empresa', 'ue');

        $stmt = $this->pdo->prepare("
            SELECT
                u.id,
                u.username,
                MAX(us.login_at) AS ultimo_login,
                GROUP_CONCAT(DISTINCT t.razon_social ORDER BY t.razon_social SEPARATOR ', ') AS empresas
            FROM usuario u
            LEFT JOIN usuario_sesion us ON us.usuario_id = u.id {$usNd}
            LEFT JOIN usuario_empresa ue ON ue.usuario_id = u.id AND ue.estado_id = 1 {$ueNd}
            LEFT JOIN empresa e ON e.id = ue.empresa_id
            LEFT JOIN tercero t ON t.id = e.tercero_id
            WHERE u.estado_id = 1
            {$uNd}
            GROUP BY u.id, u.username
            HAVING ultimo_login IS NULL OR ultimo_login < DATE_SUB(NOW(3), INTERVAL ? DAY)
            ORDER BY (ultimo_login IS NULL) DESC, ultimo_login ASC
        ");
        $stmt->execute([$diasUmbral]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
