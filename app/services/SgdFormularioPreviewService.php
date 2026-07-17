<?php

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Vista previa PDF/HTML de plantillas operativas (formatos F/R).
 */
class SgdFormularioPreviewService
{
    private SgdRepository $repo;
    private SgdDocumentoCodigoService $codigoService;
    private SgdFormularioService $formularioService;

    public function __construct()
    {
        $this->repo = new SgdRepository();
        $this->codigoService = new SgdDocumentoCodigoService();
        $this->formularioService = new SgdFormularioService();
    }

    /**
     * @return array{success: bool, message: string, binary?: string}
     */
    public function generatePreview(int $empresaId, int $documentoId, ?int $formularioVersionId = null): array
    {
        if (!class_exists(Dompdf::class)) {
            return [
                'success' => false,
                'message' => 'Motor PDF no disponible. Ejecute composer install en el servidor.',
            ];
        }

        $prepared = $this->preparePreview($empresaId, $documentoId, $formularioVersionId);
        if (!$prepared['success']) {
            return $prepared;
        }

        $html = $this->buildHtml(
            $prepared['documento'],
            $prepared['codigoDisplay'],
            $prepared['version'],
            $prepared['esquema'],
            $prepared['formatoPdf'],
            $prepared['empresa'],
            true
        );

        $binary = $this->renderPdfBinary($html, $prepared['formatoPdf']);
        if ($binary === null || $binary === '') {
            return ['success' => false, 'message' => 'No se pudo generar la vista previa del formato.'];
        }

        return [
            'success' => true,
            'message' => 'Vista previa generada.',
            'binary' => $binary,
        ];
    }

    /**
     * Genera y almacena el PDF esqueleto (plantilla vacía) en la versión documental.
     *
     * @return array{success: bool, message: string, path?: string}
     */
    public function saveSkeletonPdf(
        int $empresaId,
        int $documentoId,
        int $documentoVersionId,
        int $formularioVersionId
    ): array {
        if (!class_exists(Dompdf::class)) {
            return [
                'success' => false,
                'message' => 'Motor PDF no disponible. Ejecute composer install en el servidor.',
            ];
        }

        $prepared = $this->preparePreview($empresaId, $documentoId, $formularioVersionId);
        if (!$prepared['success']) {
            return ['success' => false, 'message' => (string)($prepared['message'] ?? 'No se pudo preparar la plantilla.')];
        }

        $html = $this->buildHtml(
            $prepared['documento'],
            $prepared['codigoDisplay'],
            $prepared['version'],
            $prepared['esquema'],
            $prepared['formatoPdf'],
            $prepared['empresa'],
            false
        );

        $binary = $this->renderPdfBinary($html, $prepared['formatoPdf']);
        if ($binary === null || $binary === '') {
            return ['success' => false, 'message' => 'No se pudo generar el PDF del esqueleto.'];
        }

        $filename = 'esq_v' . $documentoVersionId . '_' . bin2hex(random_bytes(8)) . '.pdf';
        $storage = StorageService::instance();

        try {
            $path = $storage->putContents(
                $storage->key(StorageService::ZONE_SGD, $filename, $empresaId, $documentoId),
                $binary
            );
        } catch (RuntimeException) {
            return ['success' => false, 'message' => 'No se pudo guardar el PDF del esqueleto.'];
        }

        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $this->repo->updateDocumentoVersionArchivo($empresaId, $documentoVersionId, $path, $userId, 'pdf');

        return [
            'success' => true,
            'message' => 'PDF esqueleto generado.',
            'path' => $path,
        ];
    }

    /**
     * @return array{success: bool, message?: string, documento?: array, codigoDisplay?: string, version?: array, esquema?: array, formatoPdf?: array, empresa?: array|null}
     */
    public function preparePreview(int $empresaId, int $documentoId, ?int $formularioVersionId = null): array
    {
        $documento = $this->repo->findDocumentoById($empresaId, $documentoId);
        if ($documento === null) {
            return ['success' => false, 'message' => 'Documento no encontrado.'];
        }

        $elab = new SgdElaboracionService();
        $documento = $elab->resolveModoDocumento($empresaId, $documento);
        $modo = (string)($documento['modo_efectivo'] ?? '');
        if ($modo === 'maestro') {
            return ['success' => false, 'message' => 'Use la vista previa de elaboración para documentos maestro.'];
        }

        $proposito = $modo === 'maestro' ? 'elaboracion' : 'operativo';
        $formulario = $this->repo->findFormularioByDocumento($empresaId, $documentoId, $proposito);
        if ($formulario === null) {
            return ['success' => false, 'message' => 'Este formato aún no tiene plantilla diseñada.'];
        }

        $version = $this->resolveFormularioVersion($empresaId, (int)$formulario['id'], $formularioVersionId);
        if ($version === null) {
            return ['success' => false, 'message' => 'No hay versión de plantilla para previsualizar.'];
        }

        $esquema = $this->formularioService->decodeEsquemaJson($version['esquema_json'] ?? null);
        if (!$this->formularioService->esquemaTieneContenido($esquema)) {
            return [
                'success' => false,
                'message' => 'La plantilla está vacía. Cargue un arquetipo o agregue campos en el diseñador.',
            ];
        }

        $config = $this->repo->findConfigByEmpresaId($empresaId);
        $configExtra = SgdConfigService::parseConfigJson($config);
        $formatoPdf = $configExtra['formato_pdf'] ?? SgdSeccionService::defaultFormatoPdf();

        return [
            'success' => true,
            'documento' => $documento,
            'codigoDisplay' => $this->codigoService->buildForDocument($empresaId, $documento, $this->repo),
            'version' => $version,
            'esquema' => $esquema,
            'formatoPdf' => $formatoPdf,
            'empresa' => $this->repo->findEmpresaBranding($empresaId),
        ];
    }

    /**
     * @param array<string, mixed> $documento
     * @param array<string, mixed> $version
     * @param array<string, mixed> $esquema
     * @param array<string, mixed> $formatoPdf
     * @param array<string, mixed>|null $empresa
     */
    public function buildHtml(
        array $documento,
        string $codigoDisplay,
        array $version,
        array $esquema,
        array $formatoPdf,
        ?array $empresa,
        bool $isPreview = true
    ): string {
        $margenes = $formatoPdf['margenes'] ?? [];
        $mt = (float)($margenes['superior'] ?? 3);
        $mb = (float)($margenes['inferior'] ?? 2);
        $ml = (float)($margenes['izquierdo'] ?? 3);
        $mr = (float)($margenes['derecho'] ?? 2);
        $headerHeightCm = 2.5;
        $contentGapCm = 0.65;
        $pageMarginTopCm = $mt + $headerHeightCm + $contentGapCm;
        $headerTopCm = -($headerHeightCm + $contentGapCm);
        $pieLegalRaw = trim((string)($formatoPdf['pie_pagina'] ?? ''));

        $docNombre = htmlspecialchars(trim((string)($documento['nombre'] ?? '')), ENT_QUOTES, 'UTF-8');
        $procesoNombre = htmlspecialchars(trim((string)($documento['proceso_nombre'] ?? '')), ENT_QUOTES, 'UTF-8');
        $codigoEsc = htmlspecialchars($codigoDisplay, ENT_QUOTES, 'UTF-8');
        $versionNum = htmlspecialchars((string)($version['numero'] ?? '1'), ENT_QUOTES, 'UTF-8');
        $arquetipo = htmlspecialchars((string)($esquema['arquetipo'] ?? ''), ENT_QUOTES, 'UTF-8');

        $logoHtml = $this->buildLogoHtml($empresa);
        $pageHeaderHtml = $this->buildPageHeaderHtml(
            $logoHtml,
            $procesoNombre,
            $docNombre,
            $codigoEsc,
            $versionNum,
            'Plantilla'
        );

        $body = '<div class="sgd-fmt-main">';
        if ($arquetipo !== '') {
            $body .= '<p class="sgd-fmt-arquetipo">Arquetipo: ' . $arquetipo . '</p>';
        }
        $body .= $this->renderEsquemaBody($esquema);
        $body .= '</div>';

        $previewBanner = $isPreview
            ? '<div class="sgd-pdf-preview-banner">VISTA PREVIA — Plantilla de formato. Campos vacíos para diligenciamiento.</div>'
            : '';

        $pageFooterHtml = $pieLegalRaw !== ''
            ? '<div class="sgd-pdf-page-footer-wrap"><p class="sgd-pdf-page-footer">'
                . htmlspecialchars($pieLegalRaw, ENT_QUOTES, 'UTF-8') . '</p></div>'
            : '';

        return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><style>'
            . '@page{margin:' . $pageMarginTopCm . 'cm ' . $mr . 'cm ' . $mb . 'cm ' . $ml . 'cm;}'
            . 'body{font-family:Arial,Helvetica,sans-serif;color:#333;font-size:10.5pt;line-height:1.4;margin:0;padding:0;}'
            . '.sgd-pdf-page-header-wrap{position:fixed;top:' . $headerTopCm . 'cm;left:0;right:0;height:' . $headerHeightCm . 'cm;}'
            . '.sgd-pdf-page-header{width:100%;height:100%;border-collapse:collapse;table-layout:fixed;}'
            . '.sgd-pdf-page-header td{border:1px solid #888;vertical-align:middle;padding:3px 6px;font-size:8.5pt;color:#555;line-height:1.25;}'
            . '.sgd-pdf-hdr-logo{width:26%;text-align:center;}'
            . '.sgd-pdf-hdr-logo img.sgd-pdf-logo{max-height:1.85cm;max-width:95%;width:auto;height:auto;}'
            . '.sgd-pdf-hdr-process,.sgd-pdf-hdr-title{width:48%;text-align:center;font-size:9pt;}'
            . '.sgd-pdf-hdr-meta{width:26%;padding:0;vertical-align:top;}'
            . '.sgd-pdf-hdr-meta-inner{width:100%;height:100%;border-collapse:collapse;table-layout:fixed;}'
            . '.sgd-pdf-hdr-meta-inner td{border:none;border-bottom:1px solid #888;padding:3px 6px;font-size:8pt;text-align:left;vertical-align:middle;line-height:1.2;}'
            . '.sgd-pdf-hdr-meta-inner tr:last-child td{border-bottom:none;}'
            . '.sgd-pdf-page-footer-wrap{position:fixed;bottom:-' . $mb . 'cm;left:' . $ml . 'cm;right:' . $mr . 'cm;'
            . 'font-size:7pt;font-style:italic;color:#666;text-align:center;line-height:1.35;}'
            . '.sgd-pdf-preview-banner{position:fixed;bottom:' . ($pieLegalRaw !== '' ? '0.85' : '0.35') . 'cm;left:' . $ml . 'cm;right:' . $mr . 'cm;z-index:50;'
            . 'background:#fff3cd;border:1px solid #e0c060;color:#664d03;padding:5px 8px;font-size:7.5pt;text-align:center;font-weight:700;}'
            . '.sgd-fmt-main{padding-top:0.2cm;}'
            . '.sgd-fmt-arquetipo{font-size:8.5pt;color:#666;margin:0 0 0.8em;font-style:italic;}'
            . '.sgd-fmt-bloque{margin:0 0 1.1em;page-break-inside:avoid;}'
            . '.sgd-fmt-bloque-title{font-size:11pt;font-weight:700;color:#444;margin:0 0 0.45em;text-transform:uppercase;}'
            . '.sgd-fmt-field{margin:0 0 0.55em;}'
            . '.sgd-fmt-label{font-size:9pt;font-weight:600;display:block;margin:0 0 0.15em;}'
            . '.sgd-fmt-ayuda{font-size:8.5pt;color:#555;margin:0 0 0.5em;font-style:italic;}'
            . '.sgd-fmt-default{font-size:8pt;font-weight:400;color:#777;}'
            . '.sgd-fmt-line{border-bottom:1px solid #444;min-height:1.1em;margin:0;}'
            . '.sgd-fmt-box{border:1px solid #888;min-height:2.2cm;padding:4px 6px;margin:0;}'
            . '.sgd-fmt-table{width:100%;border-collapse:collapse;margin:0.3em 0 0;font-size:9pt;}'
            . '.sgd-fmt-table th,.sgd-fmt-table td{border:1px solid #444;padding:5px 6px;vertical-align:top;}'
            . '.sgd-fmt-table th{background:#f3f3f3;font-weight:700;text-align:left;}'
            . '.sgd-fmt-table .sgd-fmt-empty-row{height:1.4em;}'
            . '.sgd-fmt-list{margin:0.3em 0 0;padding:0 0 0 1.2em;}'
            . '.sgd-fmt-list li{margin:0 0 0.35em;}'
            . '.sgd-fmt-firma-row td{height:1.6cm;}'
            . '</style></head><body>'
            . $pageHeaderHtml
            . $pageFooterHtml
            . $body
            . $previewBanner
            . '</body></html>';
    }

    /**
     * @param array<string, mixed> $esquema
     */
    private function renderEsquemaBody(array $esquema): string
    {
        $esquemaSvc = new SgdFormularioEsquemaService();
        $html = '';
        foreach ($esquemaSvc->elementosActivos($esquema) as $el) {
            if (($el['tipo'] ?? '') === 'bloque') {
                $html .= $this->renderBloque($el);
            } elseif (($el['tipo'] ?? '') === 'campo') {
                $html .= $this->renderCampoLibre([
                    'id' => $el['id'] ?? '',
                    'tipo' => $el['tipo_campo'] ?? 'texto',
                    'label' => $el['label'] ?? '',
                    'requerido' => !empty($el['requerido']),
                    'opciones' => $el['opciones'] ?? [],
                ]);
            }
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $bloque
     */
    private function renderBloque(array $bloque): string
    {
        $catalogDef = SgdArquetipoOperativoService::findBloqueDef((string)($bloque['seccion_codigo'] ?? ''));
        $titulo = htmlspecialchars(SgdBloqueConfigService::resolveTitulo($bloque), ENT_QUOTES, 'UTF-8');
        $ayuda = SgdBloqueConfigService::resolveAyuda($bloque, is_array($catalogDef) ? $catalogDef : null);
        $widget = (string)($bloque['widget'] ?? 'grupo_campos');
        $def = SgdBloqueConfigService::resolveDefinicion($bloque);
        $secCodigo = (string)($bloque['seccion_codigo'] ?? '');

        if (SgdChecklistConfigService::isChecklistBlock($secCodigo)) {
            $inner = $this->renderChecklist($bloque);
        } else {
            $inner = match ($widget) {
                'tabla_repetible' => $this->renderTablaRepetible($def['columnas'] ?? []),
                'lista_repetible' => $this->renderListaRepetible($def['item'] ?? []),
                'texto_enriquecido' => '<div class="sgd-fmt-box"></div>',
                'bloque_firmas' => $this->renderBloqueFirmas($def['roles'] ?? []),
                default => $this->renderGrupoCampos($def['campos'] ?? []),
            };
        }

        $ayudaHtml = $ayuda !== ''
            ? '<p class="sgd-fmt-ayuda">' . htmlspecialchars($ayuda, ENT_QUOTES, 'UTF-8') . '</p>'
            : '';

        return '<section class="sgd-fmt-bloque"><h2 class="sgd-fmt-bloque-title">' . $titulo . '</h2>' . $ayudaHtml . $inner . '</section>';
    }

    /**
     * @param list<array<string, mixed>> $campos
     */
    private function renderGrupoCampos(array $campos): string
    {
        $html = '';
        foreach ($campos as $campo) {
            if (!is_array($campo)) {
                continue;
            }
            $html .= $this->renderCampoLibre($campo);
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $campo
     */
    private function renderCampoLibre(array $campo): string
    {
        $label = htmlspecialchars((string)($campo['label'] ?? $campo['id'] ?? ''), ENT_QUOTES, 'UTF-8');
        $tipo = (string)($campo['tipo'] ?? 'texto');
        $req = !empty($campo['requerido']) ? ' *' : '';
        $default = trim((string)($campo['default'] ?? ''));
        $defaultHint = $default !== ''
            ? ' <span class="sgd-fmt-default">(' . htmlspecialchars($default, ENT_QUOTES, 'UTF-8') . ')</span>'
            : '';

        if ($tipo === 'textarea' || $tipo === 'firma') {
            $inner = '<div class="sgd-fmt-box"></div>';
        } elseif ($tipo === 'lista') {
            $opts = is_array($campo['opciones'] ?? null) ? implode(' / ', $campo['opciones']) : '—';
            $inner = '<p class="sgd-fmt-line">' . htmlspecialchars($opts, ENT_QUOTES, 'UTF-8') . '</p>';
        } else {
            $inner = '<p class="sgd-fmt-line">&nbsp;</p>';
        }

        return '<div class="sgd-fmt-field"><span class="sgd-fmt-label">' . $label . $req . $defaultHint . '</span>' . $inner . '</div>';
    }

    /**
     * @param array<string, mixed> $bloque
     */
    private function renderChecklist(array $bloque): string
    {
        $columnas = SgdChecklistConfigService::resolveColumnas($bloque);
        if ($columnas === []) {
            return '<div class="sgd-fmt-box"></div>';
        }

        $modo = SgdChecklistConfigService::resolveModo($bloque);
        $html = '<table class="sgd-fmt-table"><thead><tr>';
        if ($modo === 'filas_fijas') {
            $html .= '<th>N°</th>';
        }
        foreach ($columnas as $col) {
            $html .= '<th>' . htmlspecialchars((string)($col['label'] ?? $col['id'] ?? ''), ENT_QUOTES, 'UTF-8') . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        if ($modo === 'filas_fijas') {
            $filas = SgdChecklistConfigService::resolveFilasPlantilla($bloque);
            if ($filas === []) {
                $html .= '<tr><td colspan="' . (count($columnas) + 1) . '"><em>Sin filas en plantilla</em></td></tr>';
            }
            foreach ($filas as $fila) {
                $celdas = is_array($fila['celdas'] ?? null) ? $fila['celdas'] : [];
                $html .= '<tr>';
                $html .= '<td>' . htmlspecialchars((string)($fila['numero'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
                foreach ($columnas as $col) {
                    $id = (string)($col['id'] ?? '');
                    if (($col['rol'] ?? '') === 'contenido') {
                        $val = htmlspecialchars((string)($celdas[$id] ?? ''), ENT_QUOTES, 'UTF-8');
                        $html .= '<td class="sgd-fmt-checklist-contenido">' . ($val !== '' ? nl2br($val) : '&nbsp;') . '</td>';
                    } else {
                        $html .= '<td>&nbsp;</td>';
                    }
                }
                $html .= '</tr>';
            }
        } else {
            for ($r = 0; $r < 3; $r++) {
                $html .= '<tr class="sgd-fmt-empty-row">';
                foreach ($columnas as $col) {
                    $html .= '<td>&nbsp;</td>';
                }
                $html .= '</tr>';
            }
        }

        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * @param list<array<string, mixed>> $columnas
     */
    private function renderTablaRepetible(array $columnas): string
    {
        if ($columnas === []) {
            return '<div class="sgd-fmt-box"></div>';
        }

        $html = '<table class="sgd-fmt-table"><thead><tr>';
        foreach ($columnas as $col) {
            if (!is_array($col)) {
                continue;
            }
            $html .= '<th>' . htmlspecialchars((string)($col['label'] ?? $col['id'] ?? ''), ENT_QUOTES, 'UTF-8') . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        for ($r = 0; $r < 3; $r++) {
            $html .= '<tr class="sgd-fmt-empty-row">';
            foreach ($columnas as $col) {
                if (!is_array($col)) {
                    continue;
                }
                $html .= '<td>&nbsp;</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function renderListaRepetible(array $item): string
    {
        $label = htmlspecialchars((string)($item['label'] ?? 'Punto'), ENT_QUOTES, 'UTF-8');
        $html = '<ol class="sgd-fmt-list">';
        for ($i = 1; $i <= 3; $i++) {
            $html .= '<li><span class="sgd-fmt-label">' . $label . '</span><div class="sgd-fmt-line">&nbsp;</div></li>';
        }
        $html .= '</ol>';

        return $html;
    }

    /**
     * @param list<array<string, mixed>> $roles
     */
    private function renderBloqueFirmas(array $roles): string
    {
        if ($roles === []) {
            return '<div class="sgd-fmt-box"></div>';
        }

        $html = '<table class="sgd-fmt-table"><thead><tr><th>Rol</th><th>Nombre</th><th>Firma</th></tr></thead><tbody>';
        foreach ($roles as $rol) {
            if (!is_array($rol)) {
                continue;
            }
            $html .= '<tr class="sgd-fmt-firma-row"><td>'
                . htmlspecialchars((string)($rol['label'] ?? $rol['id'] ?? ''), ENT_QUOTES, 'UTF-8')
                . '</td><td>&nbsp;</td><td>&nbsp;</td></tr>';
        }
        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * @param array<string, mixed>|null $empresa
     */
    private function buildLogoHtml(?array $empresa): string
    {
        $logoPath = trim((string)($empresa['logo'] ?? ''));
        if ($logoPath === '') {
            return '';
        }
        $abs = $this->publicPathToFilesystem($logoPath);
        if ($abs === null) {
            return '';
        }

        return '<img src="' . htmlspecialchars($abs, ENT_QUOTES, 'UTF-8') . '" class="sgd-pdf-logo" alt="">';
    }

    private function buildPageHeaderHtml(
        string $logoHtml,
        string $procesoNombre,
        string $docNombre,
        string $codigoEsc,
        string $versionNum,
        string $vigenteLabel
    ): string {
        $logoCell = $logoHtml !== '' ? $logoHtml : '&nbsp;';

        return '<div class="sgd-pdf-page-header-wrap"><table class="sgd-pdf-page-header" role="presentation">'
            . '<tr>'
            . '<td class="sgd-pdf-hdr-logo" rowspan="2">' . $logoCell . '</td>'
            . '<td class="sgd-pdf-hdr-process">' . ($procesoNombre !== '' ? $procesoNombre : '&nbsp;') . '</td>'
            . '<td class="sgd-pdf-hdr-meta" rowspan="2">'
            . '<table class="sgd-pdf-hdr-meta-inner" role="presentation">'
            . '<tr><td>Código: ' . $codigoEsc . '</td></tr>'
            . '<tr><td>Versión plantilla: ' . $versionNum . '</td></tr>'
            . '<tr><td>Estado: ' . htmlspecialchars($vigenteLabel, ENT_QUOTES, 'UTF-8') . '</td></tr>'
            . '</table></td>'
            . '</tr>'
            . '<tr><td class="sgd-pdf-hdr-title">' . ($docNombre !== '' ? $docNombre : '&nbsp;') . '</td></tr>'
            . '</table></div>';
    }

    private function resolveFormularioVersion(int $empresaId, int $formularioId, ?int $versionId): ?array
    {
        if ($versionId !== null && $versionId > 0) {
            $version = $this->repo->findFormularioVersionById($empresaId, $versionId);
            if ($version !== null && (int)$version['formulario_id'] === $formularioId) {
                return $version;
            }
        }

        $borrador = $this->repo->findFormularioBorradorVersion($empresaId, $formularioId);
        if ($borrador !== null) {
            return $borrador;
        }

        foreach ($this->repo->listFormularioVersiones($empresaId, $formularioId) as $ver) {
            if ((int)($ver['es_vigente'] ?? 0) === 1) {
                return $this->repo->findFormularioVersionById($empresaId, (int)$ver['id']);
            }
        }

        $versiones = $this->repo->listFormularioVersiones($empresaId, $formularioId);
        if ($versiones === []) {
            return null;
        }

        return $this->repo->findFormularioVersionById($empresaId, (int)$versiones[array_key_last($versiones)]['id']);
    }

    private function publicPathToFilesystem(string $publicPath): ?string
    {
        $publicPath = '/' . ltrim($publicPath, '/');
        if (!str_starts_with($publicPath, '/uploads/')) {
            return null;
        }

        $abs = StorageService::instance()->localPath($publicPath);

        return $abs !== null && is_readable($abs) ? $abs : null;
    }

    /**
     * @param array<string, mixed> $formatoPdf
     */
    private function renderPdfBinary(string $html, array $formatoPdf): ?string
    {
        try {
            $options = new Options();
            $options->set('isRemoteEnabled', false);
            $options->set('isHtml5ParserEnabled', true);
            $options->set('isPhpEnabled', true);
            $options->set('chroot', BASE_PATH . '/public');
            $options->set('defaultFont', trim((string)($formatoPdf['fuente_cuerpo'] ?? 'Arial')) ?: 'Arial');

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            return $dompdf->output();
        } catch (Throwable $e) {
            error_log('SgdFormularioPreviewService: ' . $e->getMessage());

            return null;
        }
    }
}
