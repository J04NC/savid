<?php

/**
 * Orquesta el modal "Identificaciones del tercero" (?url=tercero/identificaciones/{id}):
 * varios documentos por tercero, uno marcado "principal", cálculo de DV para
 * NIT, y las protecciones de borrado (usuario/empresa vinculados). Antes
 * esta lógica (con SQL directo) vivía en TerceroController.
 */
class TerceroIdentificacionService
{
    private const TIPODOCUMENTO_NIT_ID = 9;

    private TerceroIdentificacionRepository $repo;

    public function __construct()
    {
        $database = new Database();
        $this->repo = new TerceroIdentificacionRepository($database->connect());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function obtenerHeader(int $terceroId): ?array
    {
        return $this->repo->findTerceroHeader($terceroId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function obtenerTiposDocumento(): array
    {
        return $this->repo->findTiposDocumento();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function obtenerIdentificaciones(int $terceroId): array
    {
        return $this->repo->findIdentificaciones($terceroId);
    }

    /**
     * @param array<string, mixed> $post
     * @return array{success: bool, message: string, identificaciones?: list<array<string, mixed>>}
     */
    public function guardar(int $terceroId, array $post): array
    {
        $identId = (int)($post['identificacion_id'] ?? 0);
        $tipoDocId = (int)($post['tipodocumento_id'] ?? 0);
        $numero = trim((string)($post['numero'] ?? ''));
        $dvRaw = trim((string)($post['dv'] ?? ''));
        $principal = !empty($post['principal']) ? 1 : 0;
        $estadoId = (int)($post['estado_id'] ?? 1);
        $fechaExp = $this->nullableDate($post['fecha_expedicion'] ?? null);
        $fechaVen = $this->nullableDate($post['fecha_vencimiento'] ?? null);
        $observacion = trim((string)($post['observacion'] ?? ''));

        if ($tipoDocId <= 0) {
            return ['success' => false, 'message' => 'Seleccione el tipo de documento.'];
        }

        if ($numero === '') {
            return ['success' => false, 'message' => 'Indique el número de documento.'];
        }

        if (!in_array($estadoId, [1, 2], true)) {
            $estadoId = 1;
        }

        $dv = null;
        if ($tipoDocId === self::TIPODOCUMENTO_NIT_ID) {
            $dv = $dvRaw !== '' ? (int)$dvRaw : (int)$this->nitDvFromDigits($numero);
        } elseif ($dvRaw !== '') {
            $dv = (int)$dvRaw;
        }

        if ($this->repo->existeDuplicado($tipoDocId, $numero, $identId > 0 ? $identId : 0)) {
            return ['success' => false, 'message' => 'Ya existe otra identificación con ese tipo y número.'];
        }

        $datos = [
            'tipodocumento_id' => $tipoDocId,
            'numero' => $numero,
            'dv' => $dv,
            'principal' => $principal,
            'estado_id' => $estadoId,
            'fecha_expedicion' => $fechaExp,
            'fecha_vencimiento' => $fechaVen,
            'observacion' => $observacion !== '' ? $observacion : null,
        ];

        $pdo = $this->repo->getPdo();
        $pdo->beginTransaction();
        try {
            if ($identId > 0) {
                if (!$this->repo->perteneceAlTercero($identId, $terceroId)) {
                    throw new RuntimeException('La identificación no pertenece a este tercero.');
                }
                $this->repo->update($identId, $terceroId, $datos);
                $msg = 'Identificación actualizada.';
            } else {
                $identId = $this->repo->insert($terceroId, $datos);
                $msg = 'Identificación creada.';
            }

            if ($principal === 1) {
                $this->repo->clearPrincipalExcept($terceroId, $identId);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();

            return ['success' => false, 'message' => 'No se pudo guardar: ' . $e->getMessage()];
        }

        return [
            'success' => true,
            'message' => $msg,
            'identificaciones' => $this->obtenerIdentificaciones($terceroId),
        ];
    }

    /**
     * @return array{success: bool, message: string, identificaciones?: list<array<string, mixed>>}
     */
    public function alternarEstado(int $terceroId, int $identId): array
    {
        if ($identId <= 0) {
            return ['success' => false, 'message' => 'Identificación no especificada.'];
        }

        $estadoActual = $this->repo->findEstado($identId, $terceroId);
        if ($estadoActual === null) {
            return ['success' => false, 'message' => 'Identificación no encontrada.'];
        }

        $nuevo = ($estadoActual === 1) ? 2 : 1;
        $this->repo->setEstado($identId, $terceroId, $nuevo);

        return [
            'success' => true,
            'message' => $nuevo === 1 ? 'Identificación activada.' : 'Identificación inactivada.',
            'identificaciones' => $this->obtenerIdentificaciones($terceroId),
        ];
    }

    /**
     * @return array{success: bool, message: string, identificaciones?: list<array<string, mixed>>}
     */
    public function marcarPrincipal(int $terceroId, int $identId): array
    {
        if ($identId <= 0) {
            return ['success' => false, 'message' => 'Identificación no especificada.'];
        }

        if (!$this->repo->perteneceAlTercero($identId, $terceroId)) {
            return ['success' => false, 'message' => 'Identificación no encontrada.'];
        }

        $pdo = $this->repo->getPdo();
        $pdo->beginTransaction();
        try {
            $this->repo->clearPrincipalExcept($terceroId, $identId);
            $this->repo->setPrincipal($identId, $terceroId);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();

            return ['success' => false, 'message' => 'No se pudo asignar principal: ' . $e->getMessage()];
        }

        return [
            'success' => true,
            'message' => 'Identificación marcada como principal.',
            'identificaciones' => $this->obtenerIdentificaciones($terceroId),
        ];
    }

    /**
     * @return array{success: bool, message: string, identificaciones?: list<array<string, mixed>>}
     */
    public function eliminar(int $terceroId, int $identId): array
    {
        if ($identId <= 0) {
            return ['success' => false, 'message' => 'Identificación no especificada.'];
        }

        if ($this->repo->countReferenciasUsuario($identId) > 0) {
            return ['success' => false, 'message' => 'No se puede eliminar: hay usuarios vinculados a esta identificación.'];
        }

        if ($this->repo->countReferenciasEmpresaNit($identId) > 0) {
            return ['success' => false, 'message' => 'No se puede eliminar: hay empresas vinculadas a esta identificación (NIT).'];
        }

        if ($this->repo->countReferenciasEmpresaRepresentante($identId) > 0) {
            return ['success' => false, 'message' => 'No se puede eliminar: está asignada como representante legal de una empresa.'];
        }

        try {
            $filasAfectadas = $this->repo->delete($identId, $terceroId);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'No se pudo eliminar: ' . $e->getMessage()];
        }

        if ($filasAfectadas === 0) {
            return ['success' => false, 'message' => 'Identificación no encontrada.'];
        }

        return [
            'success' => true,
            'message' => 'Identificación eliminada.',
            'identificaciones' => $this->obtenerIdentificaciones($terceroId),
        ];
    }

    private function nullableDate(mixed $value): ?string
    {
        $v = trim((string)($value ?? ''));

        return $v === '' ? null : $v;
    }

    private function nitDvFromDigits(string $digits): string
    {
        $s = preg_replace('/\D/', '', $digits) ?? '';
        if ($s === '') {
            return '0';
        }

        $factors = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];
        $sum = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $sum += (int)$s[$len - 1 - $i] * $factors[$i % count($factors)];
        }
        $r = $sum % 11;

        return (string)($r < 2 ? $r : 11 - $r);
    }
}
