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
                $codigoDisplay = $this->codigoService->buildForDocument($empresaId, $documento, $this->repo);
                $elaboracion = $this->repo->findDocumentoElaboracion($empresaId, $documentoId);
                $opcionesRaw = $this->decodeJsonMap($elaboracion['opciones_json'] ?? null);
                [$opciones, $numeracion] = $this->splitOpcionesNumeracion($opcionesRaw);
                $contenido = $this->decodeJsonMap($elaboracion['contenido_json'] ?? null);
                $seccionesEfectivas = $this->buildSeccionesEfectivas($empresaId, $documento, $opciones);
                $seccionesEfectivas = $this->assignSectionNumbers($seccionesEfectivas, $numeracion);
                $documentosMaestro = $this->attachCodigoDisplayToDocumentos(
                    $this->repo->listDocumentosForSelect($empresaId, $documentoId),
                    $empresaId
                );
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

        if ($post === [] && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            return [
                'success' => false,
                'message' => 'El contenido supera el límite de envío del servidor. No se guardó nada.',
            ];
        }

        $seccionesEfectivas = $this->buildSeccionesEfectivas($empresaId, $documento, $opciones);
        $existingElab = $this->repo->findDocumentoElaboracion($empresaId, $documentoId);
        $existingContenido = $this->decodeJsonMap($existingElab['contenido_json'] ?? null);
        $contenido = $this->normalizeContenido($seccionesEfectivas, $contenidoRaw, $post);
        $contenido = $this->preserveMissingElaboracionSections($existingContenido, $contenido, $seccionesEfectivas, $post);

        if ($this->allTextoPostEmpty($post) && $this->countContenidoChars($existingContenido) >= 40) {
            return [
                'success' => false,
                'message' => 'Guardado cancelado: el formulario envió todas las secciones vacías. No se modificó el documento.',
            ];
        }

        if ($this->wouldWipeElaboracionContenido($existingContenido, $contenido, $seccionesEfectivas)) {
            return [
                'success' => false,
                'message' => 'Guardado cancelado: no se recibió el texto del documento. Recargue la página (F5) y vuelva a intentar.',
            ];
        }

        $opcionesPersist = $this->mergeNumeracionIntoOpciones($opciones, $numeracionPost);

        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $this->repo->upsertDocumentoElaboracion($empresaId, $documentoId, $opcionesPersist, $contenido, $userId);

        return [
            'success' => true,
            'message' => 'Contenido de elaboración guardado.',
            'content_chars' => $this->countContenidoChars($contenido),
        ];
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $incoming
     * @param list<array<string, mixed>> $seccionesEfectivas
     * @param array<string, mixed> $post
     */
    private function preserveMissingElaboracionSections(
        array $existing,
        array $incoming,
        array $seccionesEfectivas,
        array $post
    ): array {
        $textoPost = is_array($post['texto'] ?? null) ? $post['texto'] : [];

        foreach ($seccionesEfectivas as $sec) {
            $codigo = (string)($sec['codigo'] ?? '');
            $clase = (string)($sec['clase'] ?? '');
            if ($codigo === '' || $clase === 'auto' || $clase === 'sistema' || $codigo === 'anexos' || $codigo === 'documentos_referenciados') {
                continue;
            }
            if (isset($incoming[$codigo])) {
                continue;
            }
            if (array_key_exists($codigo, $textoPost)) {
                continue;
            }
            if (isset($existing[$codigo])) {
                $incoming[$codigo] = $existing[$codigo];
            }
        }

        return $incoming;
    }

    /**
     * @param array<string, mixed> $post
     */
    private function allTextoPostEmpty(array $post): bool
    {
        $texto = $post['texto'] ?? null;
        if (!is_array($texto) || $texto === []) {
            return false;
        }

        foreach ($texto as $value) {
            if (trim(strip_tags((string)$value)) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $incoming
     * @param list<array<string, mixed>> $seccionesEfectivas
     */
    private function wouldWipeElaboracionContenido(array $existing, array $incoming, array $seccionesEfectivas): bool
    {
        $prev = $this->countContenidoChars($existing);
        $next = $this->countContenidoChars($incoming);
        if ($prev < 40 || $next > 0) {
            return false;
        }

        foreach ($seccionesEfectivas as $sec) {
            if (($sec['clase'] ?? '') !== 'contenido') {
                continue;
            }
            $codigo = (string)($sec['codigo'] ?? '');
            if ($codigo === '' || !is_string($existing[$codigo] ?? null)) {
                continue;
            }
            if (trim(strip_tags((string)$existing[$codigo])) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $contenido
     */
    private function countContenidoChars(array $contenido): int
    {
        $total = 0;
        foreach ($contenido as $value) {
            if (is_string($value)) {
                $total += mb_strlen(trim(strip_tags($value)));
                continue;
            }
            if (!is_array($value)) {
                continue;
            }
            if (isset($value['bloques']) && is_array($value['bloques'])) {
                foreach ($value['bloques'] as $bloque) {
                    if (!is_array($bloque)) {
                        continue;
                    }
                    $total += mb_strlen(trim(strip_tags((string)($bloque['cuerpo'] ?? ''))));
                }
            }
        }

        return $total;
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
     * @param array<string, mixed> $documento
     * @param array<string, mixed> $elaboracion
     * @return array{secciones: list<array<string, mixed>>, contenido: array<string, mixed>, opciones: array<string, bool>, numeracion: array<string, bool>}
     */
    public function buildPublishContext(int $empresaId, array $documento, array $elaboracion): array
    {
        $opcionesRaw = $this->decodeJsonMap($elaboracion['opciones_json'] ?? null);
        [$opciones, $numeracion] = $this->splitOpcionesNumeracion($opcionesRaw);
        $contenido = $this->decodeJsonMap($elaboracion['contenido_json'] ?? null);
        $secciones = $this->buildSeccionesEfectivas($empresaId, $documento, $opciones);
        $secciones = $this->assignSectionNumbers($secciones, $numeracion);

        return [
            'secciones' => $secciones,
            'contenido' => $contenido,
            'opciones' => $opciones,
            'numeracion' => $numeracion,
        ];
    }

    /**
     * @param array<string, mixed> $version
     * @param array{secciones: list<array<string, mixed>>, contenido: array<string, mixed>, opciones: array<string, bool>, numeracion: array<string, bool>} $context
     * @return array<string, mixed>
     */
    public function buildPublishSnapshot(int $documentoId, int $versionId, array $version, array $context): array
    {
        return [
            'generated_at' => date('c'),
            'documento_id' => $documentoId,
            'version_id' => $versionId,
            'version_numero' => (string)($version['numero'] ?? ''),
            'opciones' => $context['opciones'],
            'numeracion' => $context['numeracion'],
            'contenido' => $context['contenido'],
            'secciones' => array_map(static function (array $sec): array {
                return [
                    'codigo' => (string)($sec['codigo'] ?? ''),
                    'nombre' => (string)($sec['nombre'] ?? ''),
                    'clase' => (string)($sec['clase'] ?? ''),
                    'numero_visible' => $sec['numero_visible'] ?? null,
                ];
            }, $context['secciones']),
        ];
    }

    /**
     * @return array{valid: bool, errors: list<string>}
     */
    public function validateForPublish(int $empresaId, int $documentoId): array
    {
        $documento = $this->repo->findDocumentoById($empresaId, $documentoId);
        if ($documento === null) {
            return ['valid' => false, 'errors' => ['Documento no encontrado.']];
        }

        $documento = $this->resolveModoDocumento($empresaId, $documento);
        if (($documento['modo_efectivo'] ?? '') !== 'maestro') {
            return ['valid' => false, 'errors' => ['La publicación automática solo aplica a documentos maestro.']];
        }

        $elaboracion = $this->repo->findDocumentoElaboracion($empresaId, $documentoId);
        if ($elaboracion === null) {
            return ['valid' => false, 'errors' => ['No hay elaboración guardada. Redacte el documento antes de publicar.']];
        }

        $context = $this->buildPublishContext($empresaId, $documento, $elaboracion);
        $errors = [];

        foreach ($context['secciones'] as $sec) {
            if (empty($sec['obligatoria'])) {
                continue;
            }
            $codigo = (string)($sec['codigo'] ?? '');
            $clase = (string)($sec['clase'] ?? '');
            if ($clase !== 'contenido' && $codigo !== 'anexos') {
                continue;
            }
            if ($this->sectionContentIsEmpty($codigo, $clase, $context['contenido'][$codigo] ?? null)) {
                $errors[] = 'La sección «' . (string)($sec['nombre'] ?? $codigo) . '» es obligatoria y está vacía.';
            }
        }

        if ($errors !== []) {
            return ['valid' => false, 'errors' => $errors];
        }

        $total = $this->countContenidoChars($context['contenido']);
        if ($total < 20) {
            return ['valid' => false, 'errors' => ['El documento no tiene contenido suficiente para generar el PDF.']];
        }

        return ['valid' => true, 'errors' => []];
    }

    public function canAutoGeneratePdf(int $empresaId, int $documentoId): bool
    {
        $documento = $this->repo->findDocumentoById($empresaId, $documentoId);
        if ($documento === null) {
            return false;
        }
        $documento = $this->resolveModoDocumento($empresaId, $documento);

        return ($documento['modo_efectivo'] ?? '') === 'maestro'
            && $this->repo->findDocumentoElaboracion($empresaId, $documentoId) !== null;
    }

    private function sectionContentIsEmpty(string $codigo, string $clase, mixed $value): bool
    {
        if ($codigo === 'anexos') {
            $bloques = is_array($value) ? ($value['bloques'] ?? []) : [];
            if (!is_array($bloques) || $bloques === []) {
                return true;
            }
            foreach ($bloques as $bloque) {
                if (!is_array($bloque)) {
                    continue;
                }
                if (trim((string)($bloque['titulo'] ?? '')) !== '') {
                    return false;
                }
                if (trim(strip_tags((string)($bloque['cuerpo'] ?? ''))) !== '') {
                    return false;
                }
            }

            return true;
        }

        if ($clase === 'contenido') {
            return !is_string($value) || trim(strip_tags($value)) === '';
        }

        return true;
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

            $textoPost = $post['texto'][$codigo] ?? null;
            $texto = self::sanitizeRichHtml((string)(
                is_string($textoPost) ? $textoPost : ($contenidoRaw[$codigo] ?? '')
            ));
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

        if (stripos($html, '<') !== false && class_exists(DOMDocument::class)) {
            $domSanitized = self::sanitizeRichHtmlDom($html);
            if ($domSanitized !== null) {
                return $domSanitized;
            }
        }

        $allowed = '<p><br><strong><b><em><i><u><ul><ol><li><h1><h2><h3><h4><h5><h6><sub><sup><span><div><img><table><thead><tbody><tr><th><td>';
        $html = strip_tags($html, $allowed);
        $html = preg_replace('/\s(on\w+|style|class)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;

        return trim($html);
    }

    private static function sanitizeRichHtmlDom(string $html): ?string
    {
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML(
            '<?xml encoding="utf-8"><div id="sgd-sanitize-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$loaded) {
            return null;
        }

        $root = $doc->getElementById('sgd-sanitize-root');
        if (!$root) {
            return null;
        }

        self::sanitizeRichDomNode($root, $doc);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    private static function sanitizeRichDomNode(DOMNode $node, DOMDocument $doc): void
    {
        $allowed = [
            'p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'sub', 'sup', 'span', 'div', 'img',
            'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
        ];

        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                continue;
            }
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                $node->removeChild($child);
                continue;
            }

            $tag = strtolower($child->nodeName);
            if ($tag === 'sgd-sanitize-root') {
                self::sanitizeRichDomNode($child, $doc);
                continue;
            }

            if (!in_array($tag, $allowed, true)) {
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            $keepAttrs = [];
            if ($tag === 'table') {
                $keepAttrs['class'] = 'sgd-word-table';
            }
            if ($tag === 'ul' || $tag === 'ol') {
                $keepAttrs['class'] = 'sgd-word-list';
            }
            if ($tag === 'th' || $tag === 'td') {
                $colspan = (int)$child->getAttribute('colspan');
                $rowspan = (int)$child->getAttribute('rowspan');
                if ($colspan > 1) {
                    $keepAttrs['colspan'] = (string)min($colspan, 50);
                }
                if ($rowspan > 1) {
                    $keepAttrs['rowspan'] = (string)min($rowspan, 50);
                }
                $cellStyle = self::sanitizeCellStyle((string)$child->getAttribute('style'));
                if ($cellStyle !== '') {
                    $keepAttrs['style'] = $cellStyle;
                }
            }
            if ($tag === 'img') {
                $src = self::sanitizeImgSrc((string)$child->getAttribute('src'));
                if ($src === '') {
                    $node->removeChild($child);
                    continue;
                }
                $keepAttrs['src'] = $src;
                $keepAttrs['class'] = 'sgd-word-img';
                $alt = trim((string)$child->getAttribute('alt'));
                if ($alt !== '') {
                    $keepAttrs['alt'] = mb_substr($alt, 0, 200);
                }
                $imgClass = trim((string)$child->getAttribute('class'));
                if (preg_match('/\bis-left\b/', $imgClass)) {
                    $keepAttrs['class'] = 'sgd-word-img is-left';
                } elseif (preg_match('/\bis-right\b/', $imgClass)) {
                    $keepAttrs['class'] = 'sgd-word-img is-right';
                }
            }
            if ($tag === 'p' && $child instanceof DOMElement && $child->getElementsByTagName('img')->length > 0) {
                $pClass = trim((string)$child->getAttribute('class'));
                if ($pClass === 'sgd-word-img-wrap') {
                    $keepAttrs['class'] = 'sgd-word-img-wrap';
                }
            }

            if ($child instanceof DOMElement) {
                while ($child->attributes->length > 0) {
                    $child->removeAttribute($child->attributes->item(0)->name);
                }
                foreach ($keepAttrs as $name => $value) {
                    $child->setAttribute($name, $value);
                }
            }

            self::sanitizeRichDomNode($child, $doc);

            if ($tag === 'li' && $child instanceof DOMElement) {
                self::stripLeadingListMarkerInElement($child);
            }
        }
    }

    public static function stripLeadingListMarkerString(string $text): string
    {
        $t = trim(preg_replace('/\x{00a0}/u', ' ', $text) ?? $text);
        if ($t === '') {
            return '';
        }

        do {
            $prev = $t;
            $t = preg_replace(
                '/^\s*([\x{2022}\x{00b7}\x{25cf}\x{25cb}\x{2013}\x{2014}\-\*•\x{2713}\x{2714}\x{2611}\x{221a}\x{f0fc}\x{f0fb}\x{f0fe}\x{f0b7}\x{f0a7}]|\d+[\.\)\-])[\s\t]*/u',
                '',
                $t
            ) ?? $t;
        } while ($t !== $prev);

        return trim($t);
    }

    private static function stripLeadingListMarkerInElement(DOMElement $el): void
    {
        $strip = static function (DOMNode $node) use (&$strip): bool {
            foreach ($node->childNodes as $child) {
                if ($child->nodeType === XML_TEXT_NODE) {
                    $child->textContent = self::stripLeadingListMarkerString((string)$child->textContent);

                    return true;
                }
                if ($child->nodeType === XML_ELEMENT_NODE && $child instanceof DOMElement) {
                    if ($strip($child)) {
                        return true;
                    }
                }
            }

            return false;
        };

        $strip($el);
    }

    public static function sanitizeCellStyle(string $style): string
    {
        if (trim($style) === '') {
            return '';
        }

        $parts = [];
        if (preg_match('/background-color\s*:\s*([^;]+)/i', $style, $m)) {
            $color = self::sanitizeCssColor(trim($m[1]));
            if ($color !== '') {
                $parts[] = 'background-color:' . $color;
            }
        }
        if (preg_match('/(?:^|;)\s*color\s*:\s*([^;]+)/i', $style, $m)) {
            $color = self::sanitizeCssColor(trim($m[1]));
            if ($color !== '') {
                $parts[] = 'color:' . $color;
            }
        }
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            if (preg_match('/border-' . $side . '\s*:\s*([^;]+)/i', $style, $m)) {
                $border = self::sanitizeCssBorderSide(trim($m[1]));
                if ($border !== '') {
                    $parts[] = 'border-' . $side . ':' . $border;
                }
            }
        }
        if (preg_match('/text-align\s*:\s*(left|center|right|justify)/i', $style, $m)) {
            $parts[] = 'text-align:' . strtolower($m[1]);
        }
        if (preg_match('/vertical-align\s*:\s*(top|middle|bottom)/i', $style, $m)) {
            $parts[] = 'vertical-align:' . strtolower($m[1]);
        }

        return implode(';', $parts);
    }

    public static function sanitizeImgSrc(string $src): string
    {
        $src = trim($src);
        if ($src === '' || preg_match('#^(data:|https?:|//|file:)#i', $src)) {
            return '';
        }
        if (!preg_match('#^/uploads/sgd/(\d+)/(\d+)/media/[a-zA-Z0-9._-]+\.(jpe?g|png|webp)$#i', $src)) {
            return '';
        }

        return $src;
    }

    private static function sanitizeCssBorderSide(string $raw): string
    {
        $raw = strtolower(trim($raw));
        if ($raw === '' || $raw === 'none' || $raw === 'hidden' || $raw === '0') {
            return 'none';
        }
        if (preg_match('/^(\d+)px\s+solid\s+(.+)$/i', $raw, $m)) {
            $width = min(4, max(1, (int)$m[1]));
            $color = self::sanitizeCssColor(trim($m[2]));
            if ($color !== '') {
                return $width . 'px solid ' . $color;
            }
        }

        return '';
    }

    private static function sanitizeCssColor(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $raw)) {
            return strtolower($raw);
        }
        if (preg_match('/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/i', $raw, $m)) {
            $r = min(255, max(0, (int)$m[1]));
            $g = min(255, max(0, (int)$m[2]));
            $b = min(255, max(0, (int)$m[3]));

            return "rgb({$r},{$g},{$b})";
        }

        return '';
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
            '.sgd-word-editor ul.sgd-word-list,.sgd-word-editor ul{list-style-type:disc;margin:0 0 .65em 1.4em;padding:0 0 0 1.2em;}',
            '.sgd-word-editor ol.sgd-word-list,.sgd-word-editor ol{list-style-type:decimal;margin:0 0 .65em 1.4em;padding:0 0 0 1.2em;}',
            '.sgd-word-editor img.sgd-word-img{max-width:100%;height:auto;display:block;margin:.5em auto;}',
            '.sgd-word-editor img.sgd-word-img.is-left{margin-left:0;margin-right:auto;}',
            '.sgd-word-editor img.sgd-word-img.is-right{margin-left:auto;margin-right:0;}',
            '.sgd-word-editor p.sgd-word-img-wrap{margin:.5em 0;}',
            '.sgd-word-editor li{margin:0 0 .35em;line-height:1.45;}',
            '.sgd-word-editor li>ul,.sgd-word-editor li>ol{margin-top:.35em;margin-bottom:0;}',
            '.sgd-word-editor table.sgd-word-table{border-collapse:collapse;width:100%;max-width:100%;margin:0 0 1em;font-size:inherit;}',
            '.sgd-word-editor table.sgd-word-table th,.sgd-word-editor table.sgd-word-table td{border:1px solid #444;padding:4px 6px;vertical-align:top;text-align:left;}',
            '.sgd-word-editor table.sgd-word-table th{font-weight:700;}',
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
            !empty($nivel['subrayado']) ? 'text-decoration:underline' : 'text-decoration:none',
        ];

        if (!empty($nivel['mayusculas'])) {
            $props[] = 'text-transform:uppercase';
        } elseif (!empty($nivel['mayusculas_inicial'])) {
            $props[] = 'text-transform:capitalize';
        } else {
            $props[] = 'text-transform:none';
        }

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

    /**
     * @param list<array<string, mixed>> $documentos
     * @return list<array<string, mixed>>
     */
    private function attachCodigoDisplayToDocumentos(array $documentos, int $empresaId): array
    {
        if ($documentos === []) {
            return [];
        }

        $allById = [];
        foreach ($this->repo->listDocumentosForSelect($empresaId, null) as $row) {
            $allById[(int)$row['id']] = $row;
        }

        $this->codigoService->clearCache();
        foreach ($documentos as &$doc) {
            $doc['codigo_display'] = $this->codigoService->buildForRow($doc, $allById);
        }
        unset($doc);

        return $documentos;
    }

    /**
     * @param array<string, mixed> $files
     * @param array<string, mixed> $post
     * @param array<string, mixed> $query
     * @return array{success: bool, message: string, path?: string}
     */
    public function uploadMedia(array $files, array $post, array $query): array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        if (!$this->scope->canAccessEmpresa($empresaId, $query)) {
            return ['success' => false, 'message' => 'Sin permiso para esta empresa.'];
        }

        $documentoId = (int)($post['documento_id'] ?? $query['documento_id'] ?? 0);
        if ($documentoId <= 0) {
            return ['success' => false, 'message' => 'Documento no válido.'];
        }

        $documento = $this->repo->findDocumentoById($empresaId, $documentoId);
        if ($documento === null) {
            return ['success' => false, 'message' => 'Documento no encontrado.'];
        }

        $documento = $this->resolveModoDocumento($empresaId, $documento);
        if (($documento['modo_efectivo'] ?? '') !== 'maestro') {
            return ['success' => false, 'message' => 'Solo documentos maestro admiten imágenes en elaboración.'];
        }

        $file = $files['archivo'] ?? null;
        if (!is_array($file) || empty($file['tmp_name']) || !is_uploaded_file((string)$file['tmp_name'])) {
            return ['success' => false, 'message' => 'Archivo de imagen requerido.'];
        }

        $uploadErr = (int)($file['error'] ?? 0);
        if ($uploadErr !== UPLOAD_ERR_OK) {
            $msg = match ($uploadErr) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Imagen demasiado grande (límite del servidor).',
                UPLOAD_ERR_PARTIAL => 'La subida quedó incompleta.',
                UPLOAD_ERR_NO_FILE => 'Archivo de imagen requerido.',
                default => 'Error al subir la imagen.',
            };

            return ['success' => false, 'message' => $msg];
        }

        $tmp = (string)$file['tmp_name'];
        $mime = '';
        if (class_exists('finfo')) {
            $info = new finfo(FILEINFO_MIME_TYPE);
            $mime = $info->file($tmp) ?: '';
        }
        if ($mime === '' && function_exists('mime_content_type')) {
            $mime = mime_content_type($tmp) ?: '';
        }
        if ($mime === 'image/jpg') {
            $mime = 'image/jpeg';
        }

        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($allowed[$mime])) {
            return ['success' => false, 'message' => 'Solo JPG, PNG o WebP.'];
        }

        $ext = $allowed[$mime];
        $this->normalizeUploadedMediaTmp($tmp, $mime, 1600);

        clearstatcache(true, $tmp);
        if ((int)@filesize($tmp) > 5 * 1024 * 1024) {
            return ['success' => false, 'message' => 'Máximo 5 MB por imagen.'];
        }

        $name = 'img_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $path = StorageService::instance()->putUploadedFile(
            StorageService::ZONE_SGD_MEDIA,
            $name,
            $file,
            $empresaId,
            $documentoId
        );
        if ($path === null) {
            return ['success' => false, 'message' => 'No se pudo guardar la imagen.'];
        }

        return ['success' => true, 'message' => 'Imagen subida.', 'path' => $path];
    }

    private function normalizeUploadedMediaTmp(string $tmpPath, string $mime, int $maxSide = 1600): void
    {
        if (!function_exists('imagecreatefromstring')) {
            return;
        }

        $bytes = @file_get_contents($tmpPath);
        if ($bytes === false || $bytes === '') {
            return;
        }

        $im = @imagecreatefromstring($bytes);
        if ($im === false) {
            return;
        }

        $w = imagesx($im);
        $h = imagesy($im);
        if ($w < 1 || $h < 1) {
            imagedestroy($im);

            return;
        }

        $scale = min(1.0, $maxSide / max($w, $h));
        $work = $im;
        if ($scale < 1.0) {
            $nw = max(1, (int)round($w * $scale));
            $nh = max(1, (int)round($h * $scale));
            $resized = imagecreatetruecolor($nw, $nh);
            if ($resized) {
                if ($mime === 'image/png' || $mime === 'image/webp') {
                    imagealphablending($resized, false);
                    imagesavealpha($resized, true);
                }
                imagecopyresampled($resized, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagedestroy($im);
                $work = $resized;
            }
        }

        if ($mime === 'image/png' && function_exists('imagepng')) {
            imagepng($work, $tmpPath, 6);
        } elseif ($mime === 'image/webp' && function_exists('imagewebp')) {
            imagewebp($work, $tmpPath, 82);
        } elseif (function_exists('imagejpeg')) {
            $flat = imagecreatetruecolor(imagesx($work), imagesy($work));
            if ($flat) {
                $white = imagecolorallocate($flat, 255, 255, 255);
                imagefill($flat, 0, 0, $white);
                imagecopy($flat, $work, 0, 0, 0, 0, imagesx($work), imagesy($work));
                imagejpeg($flat, $tmpPath, 85);
                imagedestroy($flat);
            }
        }

        imagedestroy($work);
    }
}
