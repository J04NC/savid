<?php

/**
 * Congela contenido_publicado_json al cerrar un registro.
 * Resuelve usuario / terceroidentificacion contra maestros (sin duplicar tablas).
 */
class SgdRegistroSnapshotService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? (new Database())->connect();
    }

    /**
     * @param array<string, mixed> $datos
     * @param array<string, mixed> $esquema
     * @return array<string, mixed>
     */
    public function buildContenidoPublicado(array $datos, array $esquema): array
    {
        $out = [
            'bloques' => [],
            'campos_sueltos' => [],
            'publicado_en' => date('c'),
        ];

        $esquemaSvc = new SgdFormularioEsquemaService();
        $bloquesDatos = is_array($datos['bloques'] ?? null) ? $datos['bloques'] : [];
        foreach ($esquemaSvc->elementosActivos($esquema) as $el) {
            if (($el['tipo'] ?? '') !== 'bloque') {
                continue;
            }
            $bloque = $el;
            $codigo = (string)($bloque['seccion_codigo'] ?? '');
            if ($codigo === '') {
                continue;
            }
            $valor = $bloquesDatos[$codigo] ?? null;
            $widget = (string)($bloque['widget'] ?? 'grupo_campos');
            if ($widget === 'tabla_repetible' && is_array($valor)) {
                $out['bloques'][$codigo] = $this->resolveTablaFilas($valor);
            } elseif ($widget === 'grupo_campos' && is_array($valor)) {
                $out['bloques'][$codigo] = $this->resolveGrupoCampos($valor);
            } else {
                $out['bloques'][$codigo] = $valor;
            }
        }

        $camposIdx = [];
        foreach ($datos['campos_sueltos'] ?? [] as $campo) {
            if (is_array($campo) && !empty($campo['id'])) {
                $camposIdx[(string)$campo['id']] = $campo;
            }
        }
        foreach ($esquemaSvc->elementosActivos($esquema) as $el) {
            if (($el['tipo'] ?? '') !== 'campo') {
                continue;
            }
            $id = (string)($el['id'] ?? '');
            if ($id !== '' && isset($camposIdx[$id])) {
                $out['campos_sueltos'][] = $camposIdx[$id];
            } elseif ($id !== '') {
                $out['campos_sueltos'][] = ['id' => $id, 'valor' => ''];
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $filas
     * @return list<array<string, mixed>>
     */
    private function resolveTablaFilas(array $filas): array
    {
        $resolved = [];
        foreach ($filas as $fila) {
            if (!is_array($fila)) {
                continue;
            }
            $row = $fila;
            if (!empty($fila['usuario_id'])) {
                $row['_display'] = $this->resolveUsuarioDisplay((int)$fila['usuario_id']);
            } elseif (!empty($fila['terceroidentificacion_id'])) {
                $row['_display'] = $this->resolveTerceroIdentDisplay((int)$fila['terceroidentificacion_id']);
            } elseif (!empty($fila['responsable_usuario_id'])) {
                $row['_responsable_display'] = $this->resolveUsuarioDisplay((int)$fila['responsable_usuario_id']);
            } elseif (!empty($fila['responsable_terceroidentificacion_id'])) {
                $row['_responsable_display'] = $this->resolveTerceroIdentDisplay(
                    (int)$fila['responsable_terceroidentificacion_id']
                );
            }
            $resolved[] = $row;
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $grupo
     * @return array<string, mixed>
     */
    private function resolveGrupoCampos(array $grupo): array
    {
        $out = $grupo;
        if (!empty($grupo['terceroidentificacion_id'])) {
            $out['_tercero_display'] = $this->resolveTerceroIdentDisplay((int)$grupo['terceroidentificacion_id']);
        }
        if (!empty($grupo['usuario_id'])) {
            $out['_usuario_display'] = $this->resolveUsuarioDisplay((int)$grupo['usuario_id']);
        }

        return $out;
    }

    /**
     * @return array{nombre: string, documento: string, email: string}
     */
    private function resolveUsuarioDisplay(int $usuarioId): array
    {
        if ($usuarioId <= 0 || !$this->tableExists('usuario')) {
            return ['nombre' => '', 'documento' => '', 'email' => ''];
        }

        $joinTi = $this->tableExists('terceroidentificacion')
            ? 'LEFT JOIN terceroidentificacion ti ON ti.id = u.terceroidentificacion_id
               LEFT JOIN tercero t ON t.id = ti.tercero_id'
            : 'LEFT JOIN tercero t ON 1=0';

        $stmt = $this->pdo->prepare("
            SELECT u.username,
                   t.nombres, t.apellidos, t.razon_social, t.email,
                   ti.numero AS doc_numero
            FROM usuario u
            {$joinTi}
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->execute([$usuarioId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['nombre' => '', 'documento' => '', 'email' => ''];
        }

        $nombre = trim((string)($row['razon_social'] ?? ''));
        if ($nombre === '') {
            $nombre = trim(trim((string)($row['nombres'] ?? '')) . ' ' . trim((string)($row['apellidos'] ?? '')));
        }
        if ($nombre === '') {
            $nombre = (string)($row['username'] ?? '');
        }

        return [
            'nombre' => $nombre,
            'documento' => trim((string)($row['doc_numero'] ?? '')),
            'email' => trim((string)($row['email'] ?? '')),
        ];
    }

    /**
     * @return array{nombre: string, documento: string, tipo_doc: string}
     */
    private function resolveTerceroIdentDisplay(int $terceroidentificacionId): array
    {
        if ($terceroidentificacionId <= 0 || !$this->tableExists('terceroidentificacion')) {
            return ['nombre' => '', 'documento' => '', 'tipo_doc' => ''];
        }

        $stmt = $this->pdo->prepare('
            SELECT t.nombres, t.apellidos, t.razon_social, ti.numero, td.nombre AS tipo_doc
            FROM terceroidentificacion ti
            INNER JOIN tercero t ON t.id = ti.tercero_id
            LEFT JOIN tipodocumento td ON td.id = ti.tipodocumento_id
            WHERE ti.id = ?
            LIMIT 1
        ');
        $stmt->execute([$terceroidentificacionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['nombre' => '', 'documento' => '', 'tipo_doc' => ''];
        }

        $nombre = trim((string)($row['razon_social'] ?? ''));
        if ($nombre === '') {
            $nombre = trim(trim((string)($row['nombres'] ?? '')) . ' ' . trim((string)($row['apellidos'] ?? '')));
        }

        return [
            'nombre' => $nombre,
            'documento' => trim((string)($row['numero'] ?? '')),
            'tipo_doc' => trim((string)($row['tipo_doc'] ?? '')),
        ];
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare('
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = ?
            LIMIT 1
        ');
        $stmt->execute([$table]);

        return (bool)$stmt->fetchColumn();
    }
}
