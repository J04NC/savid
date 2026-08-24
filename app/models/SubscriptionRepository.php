<?php

class SubscriptionRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findActiveLatestByEmpresaId($empresaId)
    {
        $stmt = $this->pdo->prepare("
            SELECT s.*, p.nombre AS plan_nombre
            FROM suscripcion s
            JOIN plan p ON p.id = s.plan_id
            WHERE s.empresa_id = ?
            AND s.estado_id = 1
            ORDER BY s.id DESC
            LIMIT 1
        ");
        $stmt->execute([$empresaId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findEmpresaIdById(int $suscripcionId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT empresa_id FROM suscripcion WHERE id = ?');
        $stmt->execute([$suscripcionId]);
        $value = $stmt->fetchColumn();

        return $value !== false ? (int)$value : null;
    }

    /**
     * Última suscripción registrada para la empresa (activa o no), usada como base para renovar.
     */
    public function findLatestByEmpresaId(int $empresaId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT s.*, p.nombre AS plan_nombre
            FROM suscripcion s
            JOIN plan p ON p.id = s.plan_id
            WHERE s.empresa_id = ?
            ORDER BY s.id DESC
            LIMIT 1
        ");
        $stmt->execute([$empresaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Crea la renovación. Devuelve 0 si ya existía una para esa empresa y
     * fecha de inicio.
     *
     * La exclusión la garantiza el índice único uk_suscripcion_empresa_inicio,
     * no una comprobación previa en PHP: entre un SELECT y este INSERT cabe
     * otra petición, y dos clics en "Renovar" creaban dos suscripciones. Aquí
     * solo se traduce el choque (1062) en un resultado controlado.
     */
    public function createRenewal(int $empresaId, int $planId, string $fechaInicio, string $fechaFin): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO suscripcion (empresa_id, plan_id, fecha_inicio, fecha_fin, estado_id)
            VALUES (?, ?, ?, ?, 1)
        ");

        try {
            $stmt->execute([$empresaId, $planId, $fechaInicio, $fechaFin]);
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                return 0;
            }

            throw $e;
        }

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Suscripciones activas que vencen dentro de $diasUmbral días y todavía no
     * dispararon el aviso por correo.
     *
     * @return list<array{id: int, empresa_id: int, fecha_fin: string, razon_social: string}>
     */
    public function findProximasAVencerSinAlertar(int $diasUmbral): array
    {
        $stmt = $this->pdo->prepare("
            SELECT s.id, s.empresa_id, s.fecha_fin, t.razon_social
            FROM suscripcion s
            INNER JOIN empresa e ON e.id = s.empresa_id
            INNER JOIN tercero t ON t.id = e.tercero_id
            WHERE s.estado_id = 1
            AND s.alerta_vencimiento_enviada_at IS NULL
            AND s.fecha_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
        ");
        $stmt->execute([$diasUmbral]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function marcarAlertaVencimientoEnviada(int $suscripcionId): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE suscripcion SET alerta_vencimiento_enviada_at = NOW(3) WHERE id = ?
        ');
        $stmt->execute([$suscripcionId]);
    }
}
