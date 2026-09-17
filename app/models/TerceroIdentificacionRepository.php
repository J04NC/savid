<?php

/**
 * Datos del modal "Identificaciones del tercero" (?url=tercero/identificaciones/{id}):
 * el tercero, el catálogo de tipos de documento, y el CRUD de
 * terceroidentificacion (varias por tercero, con una marcada "principal").
 * Antes vivía como SQL directo en TerceroController.
 */
class TerceroIdentificacionRepository
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
     * @return array<string, mixed>|null
     */
    public function findTerceroHeader(int $terceroId): ?array
    {
        $stmt = $this->pdo->prepare(
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
    public function findTiposDocumento(): array
    {
        try {
            $stmt = $this->pdo->query(
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
    public function findIdentificaciones(int $terceroId): array
    {
        try {
            $stmt = $this->pdo->prepare(
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

    public function existeDuplicado(int $tipoDocId, string $numero, int $excluirId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM terceroidentificacion
             WHERE tipodocumento_id = ? AND TRIM(numero) = TRIM(?)
               AND id <> ?
             LIMIT 1'
        );
        $stmt->execute([$tipoDocId, $numero, $excluirId]);

        return (bool)$stmt->fetchColumn();
    }

    public function perteneceAlTercero(int $identId, int $terceroId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM terceroidentificacion WHERE id = ? AND tercero_id = ? LIMIT 1'
        );
        $stmt->execute([$identId, $terceroId]);

        return (bool)$stmt->fetchColumn();
    }

    /**
     * @param array{tipodocumento_id:int,numero:string,dv:?int,principal:int,estado_id:int,fecha_expedicion:?string,fecha_vencimiento:?string,observacion:?string} $datos
     */
    public function update(int $identId, int $terceroId, array $datos): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE terceroidentificacion
             SET tipodocumento_id = ?, numero = ?, dv = ?, principal = ?, estado_id = ?,
                 fecha_expedicion = ?, fecha_vencimiento = ?, observacion = ?
             WHERE id = ? AND tercero_id = ?'
        );
        $stmt->execute([
            $datos['tipodocumento_id'],
            $datos['numero'],
            $datos['dv'],
            $datos['principal'],
            $datos['estado_id'],
            $datos['fecha_expedicion'],
            $datos['fecha_vencimiento'],
            $datos['observacion'],
            $identId,
            $terceroId,
        ]);
    }

    /**
     * @param array{tipodocumento_id:int,numero:string,dv:?int,principal:int,estado_id:int,fecha_expedicion:?string,fecha_vencimiento:?string,observacion:?string} $datos
     */
    public function insert(int $terceroId, array $datos): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO terceroidentificacion
             (tercero_id, tipodocumento_id, numero, dv, principal, estado_id,
              fecha_expedicion, fecha_vencimiento, observacion)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $terceroId,
            $datos['tipodocumento_id'],
            $datos['numero'],
            $datos['dv'],
            $datos['principal'],
            $datos['estado_id'],
            $datos['fecha_expedicion'],
            $datos['fecha_vencimiento'],
            $datos['observacion'],
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function clearPrincipalExcept(int $terceroId, int $keepId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE terceroidentificacion SET principal = 0 WHERE tercero_id = ? AND id <> ?'
        );
        $stmt->execute([$terceroId, $keepId]);
    }

    public function findEstado(int $identId, int $terceroId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT estado_id FROM terceroidentificacion WHERE id = ? AND tercero_id = ? LIMIT 1');
        $stmt->execute([$identId, $terceroId]);
        $estado = $stmt->fetchColumn();

        return $estado !== false ? (int)$estado : null;
    }

    public function setEstado(int $identId, int $terceroId, int $nuevoEstado): void
    {
        $stmt = $this->pdo->prepare('UPDATE terceroidentificacion SET estado_id = ? WHERE id = ? AND tercero_id = ?');
        $stmt->execute([$nuevoEstado, $identId, $terceroId]);
    }

    public function setPrincipal(int $identId, int $terceroId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE terceroidentificacion SET principal = 1, estado_id = 1 WHERE id = ? AND tercero_id = ?'
        );
        $stmt->execute([$identId, $terceroId]);
    }

    public function countReferenciasUsuario(int $identificacionId): int
    {
        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM usuario WHERE terceroidentificacion_id = ?');
            $stmt->execute([$identificacionId]);

            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    public function countReferenciasEmpresaNit(int $identificacionId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM empresa WHERE terceroidentificacion_id = ?');
        $stmt->execute([$identificacionId]);

        return (int)$stmt->fetchColumn();
    }

    public function countReferenciasEmpresaRepresentante(int $identificacionId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM empresa WHERE representante_terceroidentificacion_id = ?');
        $stmt->execute([$identificacionId]);

        return (int)$stmt->fetchColumn();
    }

    public function delete(int $identId, int $terceroId): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM terceroidentificacion WHERE id = ? AND tercero_id = ?');
        $stmt->execute([$identId, $terceroId]);

        return $stmt->rowCount();
    }
}
