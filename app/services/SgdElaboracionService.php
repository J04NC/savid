<?php

class SgdElaboracionService
{
    private SgdRepository $repo;
    private SgdScopeService $scope;
    private SgdDocumentoCodigoService $codigoService;

    public function __construct()
    {
        $this->repo = new SgdRepository();
        $this->scope = new SgdScopeService();
        $this->codigoService = new SgdDocumentoCodigoService();
    }

    /**
     * @return array<string, mixed>
     */
    public function getPageData(array $query): array
    {
        $scope = $this->scope->buildScope($query);
        $empresaId = $scope['empresaId'] ?? null;
        $documentoId = isset($query['documento_id']) && ctype_digit((string)$query['documento_id'])
            ? (int)$query['documento_id']
            : 0;

        $documento = null;
        $codigoDisplay = '';
        $seccionesEfectivas = [];
        $opciones = [];
        $numeracion = [];
        $contenido = [];
        $documentosMaestro = [];
        $formatoPdf = SgdSeccionService::defaultFormatoPdf();

        if ($empresaId && $documentoId > 0) {
            $documento = $this->repo->findDocumentoById($empresaId, $documentoId);
            if ($documento) {
                $documento = $this->resolveModoDocumento($empresaId, $documento);
                $codigoDisplay = $this->codigoService->buildForRow($documento, [$documentoId => $documento]);
                $elaboracion = $this->repo->findDocumentoElaboracion($empresaId, $documentoId);
                $opcionesRaw = $this->decodeJsonMap($elaboracion['opciones_json'] ?? null);
                [$opciones, $numeracion] = $this->splitOpcionesNumeracion($opcionesRaw);
                $contenido = $this->decodeJsonMap($elaboracion['contenido_json'] ?? null);
                $seccionesEfectivas = $this->buildSeccionesEfectivas($empresaId, $documento, $opciones);
                $seccionesEfectivas = $this->assignSectionNumbers($seccionesEfectivas, $numeracion);
                $documentosMaestro = $this->repo->listDocumentosForSelect($empresaId, $documentoId);
            }
            $config = $this->repo->findConfigByEmpresaId($empresaId);
            $configExtra = SgdConfigService::parseConfigJson($config);
            $formatoPdf = $configExtra['formato_pdf'] ?? SgdSeccionService::defaultFormatoPdf();
        }

        $opcionalesPerfil = [];
        if ($empresaId && $documento && !empty($documento['tipo_documental_id'])) {
            $perfilMap = $this->repo->listTipoSeccionEstadosMap($empresaId, (int)$documento['tipo_documental_id']);
            foreach ($this->repo->listSeccionesByEmpresa($empresaId) as $s) {
                if (($perfilMap[(int)$s['id']] ?? '') === 'opcional') {
                    $opcionalesPerfil[] = $s;
                }
            }
        }

        if ($empresaId && $documentoId <= 0) {
            $config = $this->repo->findConfigByEmpresaId($empresaId);
            $configExtra = SgdConfigService::parseConfigJson($config);
            $formatoPdf = $configExtra['formato_pdf'] ?? SgdSeccionService::defaultFormatoPdf();
        }

        return [
            'scope' => $scope,
            'empresaId' => $empresaId,
            'documentoId' => $documentoId,
            'documento' => $documento,
            'codigoDisplay' => $codigoDisplay,
            'secciones' => $seccionesEfectivas,
            'opcionalesPerfil' => $opcionalesPerfil,
            'opciones' => $opciones,
            'numeracion' => $numeracion ?? [],
            'contenido' => $contenido,
            'documentosMaestro' => $documentosMaestro,
            'formatoPdf' => $formatoPdf,
        ];
    }

    /**
     * @return array{success: bool, message: string}
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

        $documentoId = (int)($post['documento_id'] ?? 0);
        if ($documentoId <= 0) {
            return ['success' => false, 'message' => 'Documento no válido.'];
        }

        $documento = $this->repo->findDocumentoById($empresaId, $documentoId);
        if ($documento === null) {
            return ['success' => false, 'message' => 'Documento no encontrado.'];
        }

        $documento = $this->resolveModoDocumento($empresaId, $documento);
        if (($documento['modo_efectivo'] ?? '') !== 'maestro') {
            return ['success' => false, 'message' => 'La elaboración de contenido solo aplica a documentos maestro.'];
        }

        $opcionesRaw = $post['opciones'] ?? [];
        $contenidoRaw = $post['contenido'] ?? [];
        if (!is_array($opcionesRaw)) {
            $opcionesRaw = [];
        }
        if (!is_array($contenidoRaw)) {
            $contenidoRaw = [];
        }

        $opciones = [];
        foreach ($opcionesRaw as $codigo => $val) {
            $codigo = preg_replace('/[^a-z0-9_]+/', '_', strtolower((string)$codigo)) ?? '';
            if ($codigo !== '' && !str_starts_with($codigo, 'num_')) {
                $opciones[$codigo] = !empty($val);
            }
        }

        $numeracionPost = [];
        foreach (($post['numeracion'] ?? []) as $codigo => $val) {
            $codigo = preg_replace('/[^a-z0-9_]+/', '_', strtolower((string)$codigo)) ?? '';
            if ($codigo !== '') {
                $numeracionPost[$codigo] = !empty($val) && $val !== '0';
            }
        }

        $seccionesEfectivas = $this->buildSeccionesEfectivas($empresaId, $documento, $opciones);
        $contenido = $this->normalizeContenido($seccionesEfectivas, $contenidoRaw, $post);
        $opcionesPersist = $this->mergeNumeracionIntoOpciones($opciones, $numeracionPost);

        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $this->repo->upsertDocumentoElaboracion($empresaId, $documentoId, $opcionesPersist, $contenido, $userId);

        return ['success' => true, 'message' => 'Contenido de elaboración guardado.'];
    }

    /**
     * @param array<string, mixed> $documento
     * @param array<string, bool> $opciones
     * @return list<array<string, mixed>>
     */
    public function buildSeccionesEfectivas(int $empresaId, array $documento, array $opciones = []): array
    {
        $tipoId = (int)($documento['tipo_documental_id'] ?? 0);
        if ($tipoId <= 0) {
            return [];
        }

        $perfil = $this->repo->listTipoSeccionEstadosMap($empresaId, $tipoId);
        if ($perfil === []) {
            return [];
        }

        $out = [];
        foreach ($this->repo->listSeccionesByEmpresa($empresaId) as $sec) {
            $sid = (int)$sec['id'];
            $estadoPerfil = $perfil[$sid] ?? 'no_aplica';
            if ($estadoPerfil === 'no_aplica') {
                continue;
            }

            $codigo = (string)$sec['codigo'];
            $activa = true;
            $editableOpcion = false;
            if ($estadoPerfil === 'opcional') {
                $editableOpcion = true;
                $activa = !empty($opciones[$codigo]);
            }

            if (!$activa) {
                continue;
            }

            $out[] = [
                'id' => $sid,
                'codigo' => $codigo,
                'nombre' => $sec['nombre'],
                'clase' => $sec['clase'],
                'orden' => (int)$sec['orden'],
                'ayuda' => $sec['ayuda'] ?? '',
                'estado_perfil' => $estadoPerfil,
                'editable_opcion' => $editableOpcion,
                'obligatoria' => $estadoPerfil === 'aplica',
            ];
        }

        usort($out, static fn($a, $b) => ($a['orden'] ?? 0) <=> ($b['orden'] ?? 0));

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $secciones
     * @param array<string, bool> $numeracionPrefs codigo => incluir en numeración
     * @return list<array<string, mixed>>
     */
    public function assignSectionNumbers(array $secciones, array $numeracionPrefs = []): array
    {
        $num = 0;
        foreach ($secciones as &$sec) {
            if (!$this->isNumerableSection($sec)) {
                $sec['numerable'] = false;
                $sec['numerar'] = false;
                $sec['numero_visible'] = null;
                continue;
            }

            $codigo = (string)($sec['codigo'] ?? '');
            $numerar = array_key_exists($codigo, $numeracionPrefs)
                ? (bool)$numeracionPrefs[$codigo]
                : $this->defaultNumeracionForCodigo($codigo);

            $sec['numerable'] = true;
            $sec['numerar'] = $numerar;
            if ($numerar) {
                $num++;
                $sec['numero_visible'] = $num;
            } else {
                $sec['numero_visible'] = null;
            }
        }
        unset($sec);

        return $secciones;
    }

    /**
     * @param array<string, mixed> $sec
     */
    public function isNumerableSection(array $sec): bool
    {
        $clase = (string)($sec['clase'] ?? '');
        $codigo = (string)($sec['codigo'] ?? '');

        return $clase === 'contenido'
            || in_array($codigo, ['documentos_referenciados', 'anexos'], true);
    }

    public function defaultNumeracionForCodigo(string $codigo): bool
    {
        return $codigo !== 'introduccion';
    }

    /**
     * @param array<string, mixed> $opcionesRaw
     * @return array{0: array<string, bool>, 1: array<string, bool>}
     */
    public function splitOpcionesNumeracion(array $opcionesRaw): array
    {
        $opciones = [];
        $numeracion = [];
        foreach ($opcionesRaw as $key => $val) {
            $key = (string)$key;
            if (str_starts_with($key, 'num_')) {
                $cod = substr($key, 4);
                if ($cod !== '') {
                    $numeracion[$cod] = !empty($val);
                }
                continue;
            }
            $opciones[$key] = !empty($val);
        }

        return [$opciones, $numeracion];
    }

    /**
     * @param array<string, bool> $opciones
     * @param array<string, bool> $numeracion
     * @return array<string, mixed>
     */
    public function mergeNumeracionIntoOpciones(array $opciones, array $numeracion): array
    {
        $merged = $opciones;
        foreach ($numeracion as $codigo => $flag) {
            $merged['num_' . $codigo] = $flag ? 1 : 0;
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $documento
     * @return array<string, mixed>
     */
    public function resolveModoDocumento(int $empresaId, array $documento): array
    {
        $modo = trim((string)($documento['modo'] ?? ''));
        if ($modo === '' && !empty($documento['tipo_documental_id'])) {
            $tipo = $this->repo->findTipoDocumentalById($empresaId, (int)$documento['tipo_documental_id']);
            $modo = trim((string)($tipo['modo'] ?? 'dinamico'));
        }
        $documento['modo_efectivo'] = $modo !== '' ? $modo : 'dinamico';

        return $documento;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContenidoSnapshot(int $empresaId, int $documentoId): array
    {
        $elaboracion = $this->repo->findDocumentoElaboracion($empresaId, $documentoId);

        return $this->decodeJsonMap($elaboracion['contenido_json'] ?? null);
    }

    /**
     * @param list<array<string, mixed>> $seccionesEfectivas
     * @param array<string, mixed> $contenidoRaw
     * @return array<string, mixed>
     */
    private function normalizeContenido(array $seccionesEfectivas, array $contenidoRaw, array $post): array
    {
        $out = [];
        foreach ($seccionesEfectivas as $sec) {
            $codigo = (string)$sec['codigo'];
            $clase = (string)$sec['clase'];

            if ($clase === 'auto') {
                continue;
            }

            if ($codigo === 'documentos_referenciados') {
                $ids = $post['referenciados'] ?? ($contenidoRaw[$codigo]['documento_ids'] ?? []);
                if (!is_array($ids)) {
                    $ids = [];
                }
                $out[$codigo] = [
                    'documento_ids' => array_values(array_unique(array_filter(array_map('intval', $ids)))),
                ];
                continue;
            }

            if ($codigo === 'anexos') {
                $bloques = $post['anexos'] ?? ($contenidoRaw[$codigo]['bloques'] ?? []);
                if (!is_array($bloques)) {
                    $bloques = [];
                }
                $normal = [];
                foreach ($bloques as $bloque) {
                    if (!is_array($bloque)) {
                        continue;
                    }
                    $titulo = trim((string)($bloque['titulo'] ?? ''));
                    $cuerpo = self::sanitizeRichHtml((string)($bloque['cuerpo'] ?? ''));
                    if ($titulo === '' && $cuerpo === '') {
                        continue;
                    }
                    $normal[] = ['titulo' => $titulo, 'cuerpo' => $cuerpo];
                }
                $out[$codigo] = ['bloques' => $normal];
                continue;
            }

            if ($clase === 'sistema') {
                continue;
            }

            $texto = self::sanitizeRichHtml((string)($contenidoRaw[$codigo] ?? $post['texto'][$codigo] ?? ''));
            if ($texto !== '') {
                $out[$codigo] = $texto;
            }
        }

        return $out;
    }

    public static function sanitizeRichHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $allowed = '<p><br><strong><b><em><i><u><ul><ol><li><h1><h2><h3><h4><h5><h6><sub><sup><span><div><table><thead><tbody><tr><th><td>';
        $html = strip_tags($html, $allowed);
        $html = preg_replace('/\s(on\w+|style|class)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;

        return trim($html);
    }

    public static function htmlForEditor(mixed $raw): string
    {
        $text = trim((string)$raw);
        if ($text === '') {
            return '';
        }
        if (strpos($text, '<') !== false) {
            return self::sanitizeRichHtml($text);
        }
        $parts = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $html = '';
        $buf = '';
        foreach ($parts as $line) {
            $line = trim($line);
            if ($line === '') {
                if ($buf !== '') {
                    $html .= '<p>' . htmlspecialchars($buf, ENT_QUOTES, 'UTF-8') . '</p>';
                    $buf = '';
                }
                continue;
            }
            $buf = $buf === '' ? $line : $buf . ' ' . $line;
        }
        if ($buf !== '') {
            $html .= '<p>' . htmlspecialchars($buf, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        return $html;
    }

    /**
     * CSS en vivo para la elaboración según formato_pdf de la empresa.
     */
    public static function buildTitulosCss(array $formatoPdf): string
    {
        $fuente = self::escapeCssFontFamily(trim((string)($formatoPdf['fuente_cuerpo'] ?? 'Arial')));
        $tamano = max(8, min(24, (int)($formatoPdf['tamano_cuerpo'] ?? 11)));
        $niveles = SgdSeccionService::normalizeTitulosConfig($formatoPdf['titulos'] ?? null)['niveles'];
        $tags = ['h2', 'h3', 'h4', 'h5', 'h6'];

        $lines = [
            ".sgd-word-page,.sgd-word-sec-num-btn,.sgd-word-sec-num-add{font-family:{$fuente};}",
            ".sgd-word-page{font-size:{$tamano}pt;}",
            ".sgd-word-editor{font-family:inherit;font-size:inherit;line-height:1.45;}",
            ".sgd-word-sec-head{font-size:{$tamano}pt;font-weight:400;}",
            '.sgd-word-sec-head .sgd-word-sec-label{text-transform:none;text-decoration:none;}',
            ".sgd-word-page-name{font-size:{$tamano}pt;font-weight:400;text-transform:none;text-decoration:none;}",
            '.sgd-word-editor p{margin:0 0 .65em;}',
        ];

        if (!empty($niveles[0])) {
            $lines[] = self::cssRulesForNivel('.sgd-word-sec-head .sgd-word-sec-label', $niveles[0], $tamano);
            $lines[] = self::cssRulesForNivel('.sgd-word-page-name', $niveles[0], $tamano);
        }

        foreach ($niveles as $i => $nivel) {
            $tag = $tags[$i] ?? null;
            if ($tag === null) {
                break;
            }
            $lines[] = self::cssRulesForNivel('.sgd-word-editor ' . $tag, $nivel, $tamano);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array{nombre?: string, ejemplo?: string, mayusculas?: bool, mayusculas_inicial?: bool, negrilla?: bool, subrayado?: bool, vinetas?: bool} $nivel
     */
    private static function cssRulesForNivel(string $selector, array $nivel, int $tamano): string
    {
        $props = [
            "font-size:{$tamano}pt",
            'text-align:left',
            !empty($nivel['negrilla']) ? 'font-weight:700' : 'font-weight:400',
            !empty($nivel['mayusculas']) ? 'text-transform:uppercase' : 'text-transform:none',
            !empty($nivel['subrayado']) ? 'text-decoration:underline' : 'text-decoration:none',
        ];

        if (!empty($nivel['vinetas'])) {
            $props[] = 'display:list-item';
            $props[] = 'list-style-type:disc';
            $props[] = 'margin:1em 0 .5em 1.4em';
        } else {
            $props[] = 'margin:1em 0 .5em';
        }

        return $selector . '{' . implode(';', $props) . '}';
    }

    private static function escapeCssFontFamily(string $name): string
    {
        $safe = preg_replace('/[<>"\';()\\\\]/', '', $name) ?? $name;
        if ($safe === '') {
            $safe = 'Arial';
        }

        return '"' . $safe . '",Arial,Helvetica,sans-serif';
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonMap(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        if (is_array($raw)) {
            return $raw;
        }

        return [];
    }
}
