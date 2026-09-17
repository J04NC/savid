<?php

/**
 * Datos del modal "Acciones del ítem" (?url=item/acciones/{id}): el ítem en
 * sí, el catálogo de acciones disponibles y sus vínculos vía item_accion.
 * Antes vivía como SQL directo en ItemController.
 */
class ItemAccionRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findItemConModulo(int $itemId): ?array
    {
        $itemNotDeleted = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'item', 'i');

        $stmt = $this->pdo->prepare(
            "SELECT i.id, i.nombre, i.ruta, i.modulo_id, m.nombre AS modulo_nombre
             FROM item i
             LEFT JOIN modulo m ON m.id = i.modulo_id
             WHERE i.id = ?
             {$itemNotDeleted}
             LIMIT 1"
        );
        $stmt->execute([$itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAccionesDisponibles(int $itemId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id AS accion_id, a.nombre, a.codigo, a.accion_codigo, a.icono, a.descripcion, a.orden,
                    ia.id AS item_accion_id,
                    ia.estado_id AS link_estado_id
               FROM accion a
               LEFT JOIN item_accion ia ON ia.accion_id = a.id AND ia.item_id = ?
              WHERE a.estado_id = 1
              ORDER BY a.orden ASC, a.nombre ASC, a.id ASC'
        );
        $stmt->execute([$itemId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function accionActivaExiste(int $accionId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM accion WHERE id = ? AND estado_id = 1 LIMIT 1');
        $stmt->execute([$accionId]);

        return (bool)$stmt->fetchColumn();
    }

    public function linkAccion(int $itemId, int $accionId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO item_accion (item_id, accion_id, estado_id)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE estado_id = 1'
        );
        $stmt->execute([$itemId, $accionId]);
    }

    /**
     * @return array{id:int}|null
     */
    public function findVinculo(int $itemId, int $accionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM item_accion WHERE item_id = ? AND accion_id = ? LIMIT 1');
        $stmt->execute([$itemId, $accionId]);
        $id = $stmt->fetchColumn();

        return $id !== false ? ['id' => (int)$id] : null;
    }

    /**
     * @return array{id:int,estado_id:int}|null
     */
    public function findVinculoConEstado(int $itemId, int $accionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, estado_id FROM item_accion WHERE item_id = ? AND accion_id = ? LIMIT 1'
        );
        $stmt->execute([$itemId, $accionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? ['id' => (int)$row['id'], 'estado_id' => (int)$row['estado_id']] : null;
    }

    public function unlinkAccion(int $itemId, int $accionId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM item_accion WHERE item_id = ? AND accion_id = ?');
        $stmt->execute([$itemId, $accionId]);
    }

    public function setEstadoVinculo(int $vinculoId, int $nuevoEstado): void
    {
        $stmt = $this->pdo->prepare('UPDATE item_accion SET estado_id = ? WHERE id = ?');
        $stmt->execute([$nuevoEstado, $vinculoId]);
    }
}
