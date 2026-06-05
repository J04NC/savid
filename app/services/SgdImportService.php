<?php

class SgdImportService
{
    private SgdRepository $repo;
    private SgdCodigoParserService $parser;

    public function __construct()
    {
        $this->repo = new SgdRepository();
        $this->parser = new SgdCodigoParserService();
    }

    /**
     * @return array{success: bool, message: string, stats?: array<string, int>}
     */
    public function importCcd(int $empresaId, string $filePath, ?string $vigencia = null, ?int $anio = null): array
    {
        $read = SgdSpreadsheetReader::read($filePath);
        if (!$read['ok']) {
            return ['success' => false, 'message' => $read['error'] ?? 'Error leyendo Excel'];
        }

        $rows = $read['rows'];
        $headerRow = $this->findCcdHeaderRow($rows);
        if ($headerRow === null) {
            return ['success' => false, 'message' => 'No se encontró fila de encabezado CCD (columna CODIGO).'];
        }

        $stats = [
            'dependencias' => 0,
            'series' => 0,
            'subseries' => 0,
            'entradas' => 0,
            'omitidas' => 0,
            'omitidas_sin_calidad' => 0,
            'sin_documento_maestro' => 0,
        ];

        $dependenciaNombre = '';
        $dependenciaCodigo = null;
        $currentSerieId = null;
        $currentSerieCodigo = null;
        $currentSerieNombre = '';
        $currentSubserieId = null;
        $lastCodigoCarpeta = '';

        foreach ($rows as $rowNum => $cols) {
            $rowNum = (int)$rowNum;
            if ($rowNum <= $headerRow) {
                if ($this->cell($cols, 'B') !== '' && stripos($this->cell($cols, 'B'), 'SECCION') !== false) {
                    $dependenciaNombre = trim($this->cell($cols, 'D'));
                }
                if (stripos($this->cell($cols, 'C'), 'Vigente') !== false || stripos($this->cell($cols, 'E'), 'Vigente') !== false) {
                    $v = $this->cell($cols, 'E') ?: $this->cell($cols, 'C');
                    if (preg_match('/Vigente:\s*(.+)/i', $v, $m)) {
                        $vigencia = trim($m[1]);
                    }
                }

                continue;
            }

            $codigoB = trim($this->cell($cols, 'B'));
            $soporte = trim($this->cell($cols, 'C'));
            $textoD = trim($this->cell($cols, 'D'));
            $codigoCalidad = trim($this->cell($cols, 'E'));

            if ($codigoB !== '' && stripos($codigoB, 'SECCION') !== false) {
                $dependenciaNombre = trim($this->cell($cols, 'D'));

                continue;
            }

            if (preg_match('/^\d+(\.\d+)*$/', $codigoB)) {
                [$depCode, $serieCode, $subserieCode] = $this->parser->parseCodigoCarpeta($codigoB);
                if ($depCode === null) {
                    continue;
                }

                if ($dependenciaCodigo === null || $dependenciaCodigo !== $depCode) {
                    $dependenciaCodigo = $depCode;
                    if ($dependenciaNombre === '') {
                        $dependenciaNombre = 'Dependencia ' . $depCode;
                    }
                }

                $depId = $this->ensureDependencia($empresaId, $depCode, $dependenciaNombre, $stats);
                $lastCodigoCarpeta = $codigoB;

                if ($subserieCode !== null) {
                    $currentSerieCodigo = $serieCode;
                    $currentSerieNombre = $textoD !== '' ? $textoD : 'Serie ' . $serieCode;
                    $currentSerieId = $this->ensureSerie(
                        $empresaId,
                        $depId,
                        $serieCode,
                        $this->serieNombreFromText($textoD, $serieCode),
                        $stats
                    );
                    $currentSubserieId = $this->ensureSubserie(
                        $empresaId,
                        $currentSerieId,
                        $subserieCode,
                        $textoD,
                        $stats
                    );
                    $this->insertCcdIfNew(
                        $empresaId,
                        $depId,
                        $currentSerieId,
                        $currentSubserieId,
                        $codigoB,
                        $currentSerieNombre,
                        $textoD,
                        $soporte,
                        $codigoCalidad,
                        $vigencia,
                        $anio,
                        $stats
                    );
                } elseif ($serieCode !== null) {
                    $currentSerieCodigo = $serieCode;
                    $currentSerieNombre = $textoD !== '' ? $textoD : 'Serie ' . $serieCode;
                    $currentSerieId = $this->ensureSerie(
                        $empresaId,
                        $depId,
                        $serieCode,
                        $currentSerieNombre,
                        $stats
                    );
                    $currentSubserieId = null;
                    $this->insertCcdIfNew(
                        $empresaId,
                        $depId,
                        $currentSerieId,
                        null,
                        $codigoB,
                        $currentSerieNombre,
                        null,
                        $soporte,
                        $codigoCalidad,
                        $vigencia,
                        $anio,
                        $stats
                    );
                }

                continue;
            }

            if ($codigoCalidad !== '' && $textoD !== '' && $lastCodigoCarpeta !== '' && $dependenciaCodigo !== null) {
                $depId = $this->ensureDependencia(
                    $empresaId,
                    $dependenciaCodigo,
                    $dependenciaNombre,
                    $stats
                );
                $this->insertCcdIfNew(
                    $empresaId,
                    $depId,
                    $currentSerieId,
                    $currentSubserieId,
                    $lastCodigoCarpeta,
                    $currentSerieNombre,
                    $textoD,
                    $soporte,
                    $codigoCalidad,
                    $vigencia,
                    $anio,
                    $stats
                );
            }
        }

        $this->repo->linkAllDocumentosCcd($empresaId);
        $this->repo->insertImportLog($empresaId, 'ccd', basename($filePath), $stats);

        return [
            'success' => true,
            'message' => 'Importación CCD finalizada.',
            'stats' => $stats,
        ];
    }

    /**
     * @return array{success: bool, message: string, stats?: array<string, int>}
     */
    public function importMaestro(int $empresaId, string $filePath): array
    {
        $tipos = $this->repo->listTiposByEmpresa($empresaId);
        if ($tipos === []) {
            return [
                'success' => false,
                'message' => 'Defina tipos documentales antes de importar el listado maestro (Configuración SGD).',
            ];
        }

        $tipoCodigos = array_map(static fn($t) => (string)$t['codigo'], $tipos);
        $tipoMap = $this->repo->mapTiposByCodigo($empresaId);

        $read = SgdSpreadsheetReader::read($filePath);
        if (!$read['ok']) {
            return ['success' => false, 'message' => $read['error'] ?? 'Error leyendo Excel'];
        }

        $headerRow = $this->findMaestroHeaderRow($read['rows']);
        if ($headerRow === null) {
            return ['success' => false, 'message' => 'No se encontró encabezado del listado maestro (columna CODIGO).'];
        }

        $stats = ['procesos' => 0, 'documentos' => 0, 'actualizados' => 0, 'omitidos' => 0];
        $tipoProcesoActual = '';
        $procesoNombreActual = '';
        $procesoCodigoActual = '';

        foreach ($read['rows'] as $rowNum => $cols) {
            if ((int)$rowNum <= $headerRow) {
                continue;
            }

            $codigo = strtoupper(trim($this->cell($cols, 'F') ?: $this->cell($cols, 'E')));
            if ($codigo === '' || $codigo === 'CODIGO') {
                continue;
            }

            $tipoProcesoCol = trim($this->cell($cols, 'C'));
            $procesoCol = trim($this->cell($cols, 'D'));
            if ($tipoProcesoCol !== '') {
                $tipoProcesoActual = $tipoProcesoCol;
            }
            if ($procesoCol !== '') {
                $procesoNombreActual = preg_replace('/\s+/', ' ', $procesoCol);
            }

            $nombre = trim($this->cell($cols, 'G'));
            if ($nombre === '' || strcasecmp($nombre, $codigo) === 0) {
                $nombre = $codigo;
            }

            $parsed = $this->parser->parse($codigo, $tipoCodigos);
            $procesoCodigo = $parsed['proceso'] !== '' ? $parsed['proceso'] : $procesoCodigoActual;
            if ($parsed['proceso'] !== '') {
                $procesoCodigoActual = $parsed['proceso'];
            }

            $procesoId = $this->ensureProceso(
                $empresaId,
                $procesoCodigo,
                $procesoNombreActual !== '' ? $procesoNombreActual : $procesoCodigo,
                $tipoProcesoActual,
                $stats
            );

            $tipoId = null;
            $modo = null;
            if ($parsed['tipo'] !== null && isset($tipoMap[$parsed['tipo']])) {
                $tipoId = (int)$tipoMap[$parsed['tipo']]['id'];
                $modo = $tipoMap[$parsed['tipo']]['modo'] ?? null;
            }

            $version = trim((string)($this->cell($cols, 'I') ?: $this->cell($cols, 'H')));
            $fechaPrimera = $this->parser->excelSerialToDate($this->cell($cols, 'H'));
            $fechaUltima = $this->parser->excelSerialToDate($this->cell($cols, 'J'));

            $before = $this->repo->findDocumentoId($empresaId, $codigo);
            $this->repo->upsertDocumento($empresaId, [
                'codigo' => $codigo,
                'nombre' => $nombre,
                'proceso_id' => $procesoId,
                'tipo_documental_id' => $tipoId,
                'version_actual' => $version !== '' ? $version : null,
                'fecha_primera_aprobacion' => $fechaPrimera,
                'fecha_ultima_aprobacion' => $fechaUltima,
            ]);

            if ($before) {
                $stats['actualizados']++;
            } else {
                $stats['documentos']++;
            }

            $this->repo->linkDocumentoToCcdByCodigoCalidad($empresaId, $codigo);
        }

        $this->repo->insertImportLog($empresaId, 'maestro', basename($filePath), $stats);

        return [
            'success' => true,
            'message' => 'Listado maestro importado.',
            'stats' => $stats,
        ];
    }

    /**
     * Importa series y subseries desde la hoja CCD1 del libro general (GI-PD1-F11).
     * Columnas: B=serie, C=nombre serie, D=código subserie, E=nombre subserie.
     *
     * @return array{success: bool, message: string, stats?: array<string, int>}
     */
    public function importSubseriesCcd1(int $empresaId, string $filePath): array
    {
        $seriesCatalog = $this->readSeriesCatalogFromCcdSheet($filePath);

        $read = SgdSpreadsheetReader::read($filePath, 'CCD1');
        if (!$read['ok']) {
            return ['success' => false, 'message' => $read['error'] ?? 'Error leyendo hoja CCD1'];
        }

        $stats = ['series' => 0, 'subseries' => 0, 'actualizadas' => 0, 'omitidas' => 0];
        $currentSerieCodigo = '';
        $currentSerieNombre = '';

        foreach ($read['rows'] as $cols) {
            $serieCodigo = trim($this->cell($cols, 'B'));
            $serieNombre = trim($this->cell($cols, 'C'));
            $subCodigo = trim($this->cell($cols, 'D'));
            $subNombre = trim($this->cell($cols, 'E'));

            if ($serieCodigo !== '' && preg_match('/^\d+$/', $serieCodigo)) {
                $currentSerieCodigo = $serieCodigo;
            }
            if ($serieNombre !== '') {
                $currentSerieNombre = $serieNombre;
            }

            if ($subCodigo === '' || $subNombre === '' || $currentSerieCodigo === '') {
                continue;
            }

            $nombreSerie = $currentSerieNombre !== ''
                ? $currentSerieNombre
                : ($seriesCatalog[$currentSerieCodigo] ?? 'Serie ' . $currentSerieCodigo);

            $serieId = $this->ensureSerieByEmpresa($empresaId, $currentSerieCodigo, $nombreSerie, $stats);
            $this->ensureSubserie($empresaId, $serieId, $subCodigo, $subNombre, $stats);
        }

        $this->repo->insertImportLog($empresaId, 'ccd1_subseries', basename($filePath), $stats);

        return [
            'success' => true,
            'message' => 'Subseries CCD1 importadas.',
            'stats' => $stats,
        ];
    }

    /**
     * @return array<string, string> codigo => nombre
     */
    private function readSeriesCatalogFromCcdSheet(string $filePath): array
    {
        $read = SgdSpreadsheetReader::read($filePath, 'CCD');
        if (!$read['ok']) {
            return [];
        }

        $catalog = [];
        foreach ($read['rows'] as $cols) {
            $codigo = trim($this->cell($cols, 'B'));
            $nombre = trim($this->cell($cols, 'C'));
            if ($codigo !== '' && preg_match('/^\d+$/', $codigo) && $nombre !== '') {
                $catalog[$codigo] = $nombre;
            }
        }

        return $catalog;
    }

    /**
     * @param array<string, int> $stats
     */
    private function ensureSerieByEmpresa(int $empresaId, string $codigo, string $nombre, array &$stats): int
    {
        $id = $this->repo->findSerieId($empresaId, $codigo);
        if ($id) {
            $this->repo->updateSerieNombre($id, $nombre);

            return $id;
        }
        $stats['series']++;

        return $this->repo->insertSerie($empresaId, $codigo, $nombre);
    }

    private function findCcdHeaderRow(array $rows): ?int
    {
        foreach ($rows as $rowNum => $cols) {
            $b = strtoupper(trim($this->cell($cols, 'B')));
            if ($b === 'CODIGO' || str_starts_with($b, 'CODIGO')) {
                return (int)$rowNum;
            }
        }

        return null;
    }

    private function findMaestroHeaderRow(array $rows): ?int
    {
        foreach ($rows as $rowNum => $cols) {
            foreach (['F', 'E', 'G'] as $col) {
                if (strtoupper(trim($this->cell($cols, $col))) === 'CODIGO') {
                    return (int)$rowNum;
                }
            }
        }

        return null;
    }

    private function cell(array $cols, string $letter): string
    {
        return trim((string)($cols[$letter] ?? ''));
    }

    private function serieNombreFromText(string $texto, string $serieCode): string
    {
        if ($texto === '') {
            return 'Serie ' . $serieCode;
        }
        $lines = preg_split('/\r?\n/', $texto);

        return trim($lines[0] ?? $texto);
    }

    /**
     * @param array<string, int> $stats
     */
    private function ensureDependencia(int $empresaId, string $codigo, string $nombre, array &$stats): int
    {
        $id = $this->repo->findDependenciaId($empresaId, $codigo);
        if ($id) {
            return $id;
        }
        $stats['dependencias']++;

        return $this->repo->insertDependencia($empresaId, $codigo, $nombre);
    }

    /**
     * @param array<string, int> $stats
     */
    private function ensureSerie(int $empresaId, int $depId, string $codigo, string $nombre, array &$stats): int
    {
        $id = $this->repo->findSerieId($empresaId, $codigo);
        if ($id) {
            return $id;
        }
        $stats['series']++;

        return $this->repo->insertSerie($empresaId, $codigo, $nombre);
    }

    /**
     * @param array<string, int> $stats
     */
    private function ensureSubserie(int $empresaId, int $serieId, string $codigo, string $nombre, array &$stats): int
    {
        $id = $this->repo->findSubserieId($serieId, $codigo);
        if ($id) {
            $this->repo->updateSubserieNombre($id, $nombre);

            return $id;
        }
        $stats['subseries']++;

        return $this->repo->insertSubserie($empresaId, $serieId, $codigo, $nombre);
    }

    /**
     * @param array<string, int> $stats
     */
    private function ensureProceso(
        int $empresaId,
        string $codigo,
        string $nombre,
        ?string $tipoProcesoLabel,
        array &$stats
    ): int {
        $id = $this->repo->findProcesoId($empresaId, $codigo);
        if ($id) {
            return $id;
        }
        $stats['procesos']++;
        $tipoId = $this->repo->findTipoprocesoIdByLabel($empresaId, $tipoProcesoLabel);

        return $this->repo->insertProceso($empresaId, $codigo, $nombre, $tipoId);
    }

    /**
     * @param array<string, int> $stats
     */
    private function insertCcdIfNew(
        int $empresaId,
        int $depId,
        ?int $serieId,
        ?int $subserieId,
        string $codigoCarpeta,
        ?string $nombreSerie,
        ?string $nombreSubserie,
        ?string $soporte,
        ?string $codigoCalidad,
        ?string $vigencia,
        ?int $anio,
        array &$stats
    ): void {
        $codigoCalidad = trim((string)$codigoCalidad);
        if ($codigoCalidad === '') {
            $stats['omitidas_sin_calidad']++;

            return;
        }

        $docId = $this->repo->findDocumentoIdByCodigoCalidad($empresaId, $codigoCalidad);
        if ($docId === null) {
            $stats['sin_documento_maestro']++;

            return;
        }

        if ($this->repo->ccdEntradaExists($empresaId, $codigoCarpeta, $codigoCalidad, $nombreSubserie)
            || $this->repo->ccdEntradaDuplicate($empresaId, $depId, $serieId ?? 0, $subserieId, $docId, null)) {
            $stats['omitidas']++;

            return;
        }

        $this->repo->insertCcdEntrada($empresaId, [
            'dependencia_id' => $depId,
            'serie_id' => $serieId,
            'subserie_id' => $subserieId,
            'codigo_carpeta' => $codigoCarpeta,
            'nombre_serie' => $nombreSerie,
            'nombre_subserie' => $nombreSubserie,
            'soporte_formato' => $soporte,
            'codigo_calidad' => $codigoCalidad,
            'documento_id' => $docId,
            'ccd_vigencia' => $vigencia,
            'ccd_anio' => $anio,
        ]);
        $stats['entradas']++;
    }
}
