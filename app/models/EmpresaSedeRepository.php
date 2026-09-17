<?php

/**
 * Datos del modal "Sedes de la empresa" (?url=empresa/sedes/{id}): CRUD de
 * `sede` (nombre/dirección/teléfono/código interno/estado) delimitado por
 * empresa_id, con la protección de retirar asignaciones `usuario_sede` al
 * eliminar o inactivar. Antes esta lógica (con SQL directo) vivía en
 * EmpresaController.
 */
class EmpresaSedeRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchSedes(int $empresaId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, nombre, direccion, telefono, codigo_interno, estado_id
             FROM sede
             WHERE empresa_id = ?
             ORDER BY estado_id DESC, nombre ASC, id ASC'
        );
        $stmt->execute([$empresaId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function sedePerteneceAEmpresa(int $sedeId, int $empresaId): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM sede WHERE id = ? AND empresa_id = ? LIMIT 1');
        $stmt->execute([$sedeId, $empresaId]);

        return (bool)$stmt->fetchColumn();
    }

    public function countAsignacionesUsuario(int $sedeId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM usuario_sede WHERE sede_id = ?');
        $stmt->execute([$sedeId]);

        return (int)$stmt->fetchColumn();
    }

    public function deleteAsignacionesUsuario(int $sedeId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM usuario_sede WHERE sede_id = ?');
        $stmt->execute([$sedeId]);
    }

    public function deleteSede(int $sedeId, int $empresaId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM sede WHERE id = ? AND empresa_id = ?');
        $stmt->execute([$sedeId, $empresaId]);
    }

    public function existeNombreDuplicado(int $empresaId, string $nombre, int $excluirId): bool
    {
        if ($excluirId > 0) {
            $stmt = $this->pdo->prepare(
                'SELECT id FROM sede WHERE empresa_id = ? AND LOWER(nombre) = LOWER(?) AND id <> ? LIMIT 1'
            );
            $stmt->execute([$empresaId, $nombre, $excluirId]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT id FROM sede WHERE empresa_id = ? AND LOWER(nombre) = LOWER(?) LIMIT 1'
            );
            $stmt->execute([$empresaId, $nombre]);
        }

        return (bool)$stmt->fetchColumn();
    }

    /**
     * @param array{nombre:string,direccion:?string,telefono:?string,codigo_interno:?string,estado_id:int} $datos
     */
    public function insertSede(int $empresaId, array $datos): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sede (empresa_id, nombre, direccion, telefono, codigo_interno, estado_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $empresaId,
            $datos['nombre'],
            $datos['direccion'],
            $datos['telefono'],
            $datos['codigo_interno'],
            $datos['estado_id'],
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array{nombre:string,direccion:?string,telefono:?string,codigo_interno:?string,estado_id:int} $datos
     */
    public function updateSede(int $sedeId, int $empresaId, array $datos): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sede
             SET nombre = ?, direccion = ?, telefono = ?, codigo_interno = ?, estado_id = ?
             WHERE id = ? AND empresa_id = ?'
        );
        $stmt->execute([
            $datos['nombre'],
            $datos['direccion'],
            $datos['telefono'],
            $datos['codigo_interno'],
            $datos['estado_id'],
            $sedeId,
            $empresaId,
        ]);
    }

    public function findEstado(int $sedeId, int $empresaId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT estado_id FROM sede WHERE id = ? AND empresa_id = ? LIMIT 1');
        $stmt->execute([$sedeId, $empresaId]);
        $estado = $stmt->fetchColumn();

        return $estado !== false ? (int)$estado : null;
    }

    public function updateEstado(int $sedeId, int $empresaId, int $nuevoEstado): void
    {
        $stmt = $this->pdo->prepare('UPDATE sede SET estado_id = ? WHERE id = ? AND empresa_id = ?');
        $stmt->execute([$nuevoEstado, $sedeId, $empresaId]);
    }
}
