<?php

class SgdSeccionService
{
    private SgdRepository $repo;
    private SgdScopeService $scope;

    public function __construct()
    {
        $this->repo = new SgdRepository();
        $this->scope = new SgdScopeService();
    }

    /**
     * @return array<string, mixed>
     */
    public function getPageData(array $query): array
    {
        $scope = $this->scope->buildScope($query);
        $empresaId = $scope['empresaId'] ?? null;
        $editId = isset($query['id']) && ctype_digit((string)$query['id']) ? (int)$query['id'] : 0;

        $secciones = [];
        $edit = null;

        if ($empresaId) {
            $secciones = $this->repo->listSeccionesByEmpresa($empresaId);
            if ($editId > 0) {
                $edit = $this->repo->findSeccionById($empresaId, $editId);
            }
            if ($edit === null) {
                $edit = [
                    'id' => '',
                    'codigo' => '',
                    'nombre' => '',
                    'clase' => 'contenido',
                    'orden' => ($this->repo->countSecciones($empresaId) + 1) * 10,
                    'ayuda' => '',
                ];
            }
        }

        return [
            'scope' => $scope,
            'empresaId' => $empresaId,
            'secciones' => $secciones,
            'edit' => $edit,
            'clases' => [
                'auto' => 'Automática (PDF)',
                'contenido' => 'Contenido (redacción)',
                'sistema' => 'Sistema (datos SAVID)',
                'operativo' => 'Operativo (formulario F/R)',
            ],
        ];
    }

    /**
     * @return array{success: bool, message: string, id?: int}
     */
    public function save(array $post, array $query): array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        if (!$this->scope->canAccessEmpresa($empresaId, $query)) {
            return ['success' => false, 'message' => 'Sin permiso para esta empresa.'];
        }

        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

        try {
            $id = $this->repo->upsertSeccion($empresaId, [
                'id' => (int)($post['id'] ?? 0),
                'codigo' => $post['codigo'] ?? '',
                'nombre' => $post['nombre'] ?? '',
                'clase' => $post['clase'] ?? 'contenido',
                'orden' => (int)($post['orden'] ?? 0),
                'ayuda' => $post['ayuda'] ?? '',
            ], $userId);
        } catch (InvalidArgumentException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return ['success' => true, 'message' => 'Sección guardada.', 'id' => $id];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function delete(array $post, array $query): array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $id = (int)($post['id'] ?? 0);
        if ($id <= 0) {
            return ['success' => false, 'message' => 'Sección no válida.'];
        }

        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $this->repo->softDeleteSeccion($empresaId, $id, $userId);

        return ['success' => true, 'message' => 'Sección eliminada.'];
    }

    /**
     * @return array{success: bool, message: string, inserted?: int, perfiles?: int}
     */
    public function importPlantillaM4(array $query, bool $applyPerfiles = true): array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        if (!$this->scope->canAccessEmpresa($empresaId, $query)) {
            return ['success' => false, 'message' => 'Sin permiso para esta empresa.'];
        }

        $path = BASE_PATH . '/config/sgd_secciones_m4_plantilla.json';
        if (!is_readable($path)) {
            $path = BASE_PATH . '/config.example/sgd_secciones_m4_plantilla.json';
        }
        if (!is_readable($path)) {
            return ['success' => false, 'message' => 'Plantilla de secciones no encontrada.'];
        }

        $data = json_decode((string)file_get_contents($path), true);
        if (!is_array($data) || empty($data['secciones'])) {
            return ['success' => false, 'message' => 'Plantilla de secciones inválida.'];
        }

        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $inserted = 0;

        foreach ($data['secciones'] as $sec) {
            if (empty($sec['codigo']) || empty($sec['nombre'])) {
                continue;
            }
            $existing = $this->repo->findSeccionByCodigo($empresaId, (string)$sec['codigo']);
            if ($existing) {
                $this->repo->upsertSeccion($empresaId, [
                    'id' => (int)$existing['id'],
                    'codigo' => $sec['codigo'],
                    'nombre' => $sec['nombre'],
                    'clase' => $sec['clase'] ?? 'contenido',
                    'orden' => (int)($sec['orden'] ?? 0),
                    'ayuda' => $sec['ayuda'] ?? '',
                ], $userId);
            } else {
                $this->repo->upsertSeccion($empresaId, [
                    'id' => 0,
                    'codigo' => $sec['codigo'],
                    'nombre' => $sec['nombre'],
                    'clase' => $sec['clase'] ?? 'contenido',
                    'orden' => (int)($sec['orden'] ?? 0),
                    'ayuda' => $sec['ayuda'] ?? '',
                ], $userId);
                $inserted++;
            }
        }

        $perfilesApplied = 0;
        if ($applyPerfiles && !empty($data['perfiles']) && is_array($data['perfiles'])) {
            $tipoSeccionService = new SgdTipoDocumentalSeccionService();
            $secciones = $this->repo->listSeccionesByEmpresa($empresaId);
            $codigoToId = [];
            foreach ($secciones as $s) {
                $codigoToId[(string)$s['codigo']] = (int)$s['id'];
            }

            foreach ($this->repo->listTiposByEmpresa($empresaId) as $tipo) {
                if (($tipo['modo'] ?? '') !== 'maestro') {
                    continue;
                }
                $codigoTipo = strtoupper(trim((string)$tipo['codigo']));
                $perfil = $data['perfiles'][$codigoTipo] ?? null;
                if (!is_array($perfil)) {
                    continue;
                }
                $map = [];
                foreach ($perfil as $secCodigo => $estado) {
                    $sid = $codigoToId[$secCodigo] ?? 0;
                    if ($sid > 0) {
                        $map[$sid] = $estado;
                    }
                }
                $tipoSeccionService->saveProfileMap($empresaId, (int)$tipo['id'], $map, $userId);
                $perfilesApplied++;
            }
        }

        $msg = "Estructura documental importada ({$inserted} secciones nuevas).";
        if ($perfilesApplied > 0) {
            $msg .= " Perfiles aplicados a {$perfilesApplied} tipo(s) maestro.";
        }

        return [
            'success' => true,
            'message' => $msg,
            'inserted' => $inserted,
            'perfiles' => $perfilesApplied,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function defaultFormatoPdf(): array
    {
        $out = [
            'margenes' => ['superior' => 3.0, 'inferior' => 2.0, 'izquierdo' => 3.0, 'derecho' => 2.0],
            'fuente_cuerpo' => 'Arial',
            'tamano_cuerpo' => 11,
            'titulos' => ['niveles' => []],
            'pie_pagina' => '',
        ];

        $path = BASE_PATH . '/config.example/sgd_secciones_m4_plantilla.json';
        if (is_readable($path)) {
            $data = json_decode((string)file_get_contents($path), true);
            if (is_array($data['formato_pdf'] ?? null)) {
                $fp = $data['formato_pdf'];
                if (isset($fp['margenes']) && is_array($fp['margenes'])) {
                    $out['margenes'] = array_merge($out['margenes'], $fp['margenes']);
                }
                if (!empty($fp['fuente_cuerpo'])) {
                    $out['fuente_cuerpo'] = (string)$fp['fuente_cuerpo'];
                }
                if (isset($fp['tamano_cuerpo'])) {
                    $out['tamano_cuerpo'] = (int)$fp['tamano_cuerpo'];
                }
                if (isset($fp['pie_pagina'])) {
                    $out['pie_pagina'] = (string)$fp['pie_pagina'];
                }
            }
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    public static function tituloFlagKeys(): array
    {
        return [
            'mayusculas' => 'Mayúscula sostenida',
            'mayusculas_inicial' => 'Mayúscula inicial',
            'negrilla' => 'Negrilla',
            'subrayado' => 'Subrayado',
            'vinetas' => 'Viñeta',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{nombre: string, ejemplo: string, mayusculas: bool, mayusculas_inicial: bool, negrilla: bool, subrayado: bool, vinetas: bool}
     */
    public static function normalizeTituloNivelRow(array $row): array
    {
        return [
            'nombre' => trim((string)($row['nombre'] ?? '')),
            'ejemplo' => trim((string)($row['ejemplo'] ?? '')),
            'mayusculas' => !empty($row['mayusculas']),
            'mayusculas_inicial' => !empty($row['mayusculas_inicial']),
            'negrilla' => !empty($row['negrilla']),
            'subrayado' => !empty($row['subrayado']),
            'vinetas' => !empty($row['vinetas']),
        ];
    }

    /**
     * @param array{nombre?: string, ejemplo?: string, mayusculas?: bool, mayusculas_inicial?: bool, negrilla?: bool, subrayado?: bool, vinetas?: bool} $row
     */
    public static function isTituloNivelVacio(array $row): bool
    {
        return trim((string)($row['nombre'] ?? '')) === ''
            && trim((string)($row['ejemplo'] ?? '')) === '';
    }

    /**
     * @param list<array{nombre: string, ejemplo: string, mayusculas: bool, mayusculas_inicial: bool, negrilla: bool, subrayado: bool, vinetas: bool}> $niveles
     * @return list<array{nombre: string, ejemplo: string, mayusculas: bool, mayusculas_inicial: bool, negrilla: bool, subrayado: bool, vinetas: bool}>
     */
    public static function filterTitulosNiveles(array $niveles): array
    {
        $out = [];
        foreach ($niveles as $row) {
            if (!self::isTituloNivelVacio($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param mixed $titulos
     * @return array{niveles: list<array{nombre: string, ejemplo: string, mayusculas: bool, mayusculas_inicial: bool, negrilla: bool, subrayado: bool, vinetas: bool}>}
     */
    public static function normalizeTitulosConfig(mixed $titulos): array
    {
        if (!is_array($titulos)) {
            return ['niveles' => []];
        }

        if (!array_key_exists('niveles', $titulos) || !is_array($titulos['niveles'])) {
            return ['niveles' => []];
        }

        $niveles = [];
        foreach ($titulos['niveles'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $niveles[] = self::normalizeTituloNivelRow($row);
        }

        return ['niveles' => self::filterTitulosNiveles($niveles)];
    }

    /**
     * Profundidad del patrón numérico en el ejemplo de nivel (1 → 1, 1.1 → 2, 1.1.1.1 → 4).
     */
    public static function tituloEjemploDepth(string $ejemplo): int
    {
        $ejemplo = trim($ejemplo);
        if ($ejemplo === '' || !preg_match('/^(\d+(?:\.\d+)*)/u', $ejemplo, $m)) {
            return 0;
        }

        return count(explode('.', $m[1]));
    }

    /**
     * Profundidad del prefijo numérico al inicio de un párrafo importado.
     */
    public static function extractLeadingNumberingDepth(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }
        if (preg_match('/^(\d+(?:\.\d+)+)/u', $text, $m)) {
            return count(explode('.', $m[1]));
        }
        if (preg_match('/^\d+[\.\)\-]\s+\S/u', $text)) {
            return 1;
        }
        if (preg_match('/^\d+\.\p{L}/u', $text)) {
            return 1;
        }
        if (preg_match('/^\d+\s+\S/u', $text)) {
            return 1;
        }

        return 0;
    }

    /**
     * Etiqueta HTML (h2–h6) según profundidad numérica y niveles configurados en SGD.
     *
     * @param array{niveles?: list<array{ejemplo?: string}>} $titulosConfig
     */
    public static function headingTagForNumberingDepth(int $depth, array $titulosConfig): string
    {
        if ($depth < 1) {
            return '';
        }

        $niveles = self::normalizeTitulosConfig($titulosConfig)['niveles'];
        $tags = ['h2', 'h3', 'h4', 'h5', 'h6'];
        $map = [];
        foreach ($niveles as $i => $nivel) {
            $ejemploDepth = self::tituloEjemploDepth((string)($nivel['ejemplo'] ?? ''));
            if ($ejemploDepth > 0 && isset($tags[$i])) {
                $map[] = ['depth' => $ejemploDepth, 'tag' => $tags[$i]];
            }
        }

        if ($map === []) {
            $idx = min($depth - 1, count($tags) - 1);

            return $tags[$idx] ?? '';
        }

        foreach ($map as $entry) {
            if ($entry['depth'] === $depth) {
                return $entry['tag'];
            }
        }

        $best = null;
        foreach ($map as $entry) {
            if ($entry['depth'] <= $depth && ($best === null || $entry['depth'] > $best['depth'])) {
                $best = $entry;
            }
        }

        return $best['tag'] ?? $map[0]['tag'];
    }
}
