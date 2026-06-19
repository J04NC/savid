<?php

use Dompdf\Adapter\CPDF;
use Dompdf\Dompdf;
use Dompdf\Options;

class SgdPdfGenerationService
{
    private const HDR_LOGO_FRAC = 0.26;
    private const HDR_CENTER_FRAC = 0.48;
    private const HDR_META_PAD_X_PT = 4.5;
    private const HDR_META_PAD_Y_PT = 2.25;
    private const HDR_PAGE_ROW_CM = 0.62;
    private const HDR_PAGE_FONT_PT = 8.0;
    private const HDR_PAGE_LABEL = 'Página ';
    /** Mínimo h3: subtítulos en índice como en elaboración (outlineMinIndex=1). */
    private const TOC_OUTLINE_MIN_H = 3;

    private SgdRepository $repo;
    private SgdElaboracionService $elabService;
    private SgdDocumentoCodigoService $codigoService;

    /** @var array<string, mixed>|null */
    private ?array $pageNumberOverlay = null;

    /** @var array<int, mixed>|null Parámetros del último buildHtml para segundo pase TOC. */
    private ?array $pdfBuildArgs = null;

    /** @var array<string, int>|null Páginas por ancla (sec-codigo) resueltas en primer pase. */
    private ?array $tocPageNumbers = null;

    private ?string $lastPdfBinary = null;

    private bool $needsTocPass = false;

    public function __construct()
    {
        $this->repo = new SgdRepository();
        $this->elabService = new SgdElaboracionService();
        $this->codigoService = new SgdDocumentoCodigoService();
    }

    /**
     * Vista previa PDF (no persiste archivo ni versión).
     *
     * @return array{success: bool, message: string, binary?: string}
     */
    public function generatePreview(int $empresaId, int $documentoId, ?int $versionId = null): array
    {
        if (!class_exists(Dompdf::class)) {
            return [
                'success' => false,
                'message' => 'Motor PDF no disponible. Ejecute composer install en el servidor.',
            ];
        }

        $prepared = $this->prepareGeneration($empresaId, $documentoId);
        if (!$prepared['success']) {
            return $prepared;
        }

        $version = $this->resolveVersionMeta($empresaId, $documentoId, $versionId);
        $this->tocPageNumbers = null;
        $this->needsTocPass = false;
        $html = $this->buildHtml(
            $empresaId,
            $prepared['documento'],
            $prepared['codigoDisplay'],
            $version,
            $prepared['context'],
            $prepared['formatoPdf'],
            $prepared['empresa'],
            $prepared['versiones'],
            true
        );

        $binary = $this->renderPdfBinary($html, $prepared['formatoPdf']);
        if ($binary === null || $binary === '') {
            return ['success' => false, 'message' => 'No se pudo generar la vista previa del PDF.'];
        }

        return [
            'success' => true,
            'message' => 'Vista previa generada.',
            'binary' => $binary,
        ];
    }

    /**
     * @param array<string, mixed> $version
     * @return array{success: bool, message: string, path?: string, snapshot?: array<string, mixed>}
     */
    public function generateForVersion(int $empresaId, int $documentoId, int $versionId, array $version): array
    {
        if (!class_exists(Dompdf::class)) {
            return [
                'success' => false,
                'message' => 'Motor PDF no disponible. Ejecute composer install en el servidor.',
            ];
        }

        $prepared = $this->prepareGeneration($empresaId, $documentoId);
        if (!$prepared['success']) {
            return $prepared;
        }

        $this->tocPageNumbers = null;
        $this->needsTocPass = false;
        $html = $this->buildHtml(
            $empresaId,
            $prepared['documento'],
            $prepared['codigoDisplay'],
            $version,
            $prepared['context'],
            $prepared['formatoPdf'],
            $prepared['empresa'],
            $prepared['versiones'],
            false
        );

        $filename = 'v' . $versionId . '_' . bin2hex(random_bytes(8)) . '.pdf';
        $storage = StorageService::instance();

        if (!$this->writePdf($html, $prepared['formatoPdf'])) {
            return ['success' => false, 'message' => 'No se pudo generar el archivo PDF.'];
        }

        $pdfBinary = $this->lastPdfBinary ?? '';
        if ($pdfBinary === '') {
            return ['success' => false, 'message' => 'No se pudo generar el archivo PDF.'];
        }

        try {
            $path = $storage->putContents(
                $storage->key(StorageService::ZONE_SGD, $filename, $empresaId, $documentoId),
                $pdfBinary
            );
        } catch (RuntimeException) {
            return ['success' => false, 'message' => 'No se pudo guardar el archivo PDF.'];
        }

        $snapshot = $this->elabService->buildPublishSnapshot(
            $documentoId,
            $versionId,
            $version,
            $prepared['context']
        );

        return [
            'success' => true,
            'message' => 'PDF generado.',
            'path' => $path,
            'snapshot' => $snapshot,
        ];
    }

    /**
     * @return array{
     *   success: bool,
     *   message?: string,
     *   documento?: array<string, mixed>,
     *   codigoDisplay?: string,
     *   context?: array<string, mixed>,
     *   formatoPdf?: array<string, mixed>,
     *   empresa?: array<string, mixed>|null,
     *   versiones?: list<array<string, mixed>>
     * }
     */
    private function prepareGeneration(int $empresaId, int $documentoId): array
    {
        $documento = $this->repo->findDocumentoById($empresaId, $documentoId);
        if ($documento === null) {
            return ['success' => false, 'message' => 'Documento no encontrado.'];
        }

        $documento = $this->elabService->resolveModoDocumento($empresaId, $documento);
        if (($documento['modo_efectivo'] ?? '') !== 'maestro') {
            return ['success' => false, 'message' => 'La generación automática solo aplica a documentos maestro.'];
        }

        $elaboracion = $this->repo->findDocumentoElaboracion($empresaId, $documentoId);
        if ($elaboracion === null) {
            return ['success' => false, 'message' => 'No hay elaboración guardada para este documento.'];
        }

        $context = $this->elabService->buildPublishContext($empresaId, $documento, $elaboracion);
        $config = $this->repo->findConfigByEmpresaId($empresaId);
        $configExtra = SgdConfigService::parseConfigJson($config);
        $formatoPdf = $configExtra['formato_pdf'] ?? SgdSeccionService::defaultFormatoPdf();

        return [
            'success' => true,
            'documento' => $documento,
            'codigoDisplay' => $this->codigoService->buildForDocument($empresaId, $documento, $this->repo),
            'context' => $context,
            'formatoPdf' => $formatoPdf,
            'empresa' => $this->repo->findEmpresaBranding($empresaId),
            'versiones' => $this->repo->listDocumentoVersiones($empresaId, $documentoId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveVersionMeta(int $empresaId, int $documentoId, ?int $versionId): array
    {
        if ($versionId !== null && $versionId > 0) {
            $version = $this->repo->findDocumentoVersionById($empresaId, $versionId);
            if ($version !== null) {
                return $version;
            }
        }

        $documento = $this->repo->findDocumentoById($empresaId, $documentoId);
        $numero = trim((string)($documento['version_actual'] ?? ''));
        if ($numero === '') {
            $numero = $this->repo->suggestNextVersionNumero($empresaId, $documentoId);
        }

        return [
            'numero' => $numero,
            'fecha_aprobacion' => date('Y-m-d'),
            'notas' => '',
        ];
    }

    /**
     * @param array<string, mixed> $documento
     * @param array<string, mixed> $version
     * @param array<string, mixed> $context
     * @param array<string, mixed> $formatoPdf
     * @param array<string, mixed>|null $empresa
     * @param list<array<string, mixed>> $versiones
     */
    private function buildHtml(
        int $empresaId,
        array $documento,
        string $codigoDisplay,
        array $version,
        array $context,
        array $formatoPdf,
        ?array $empresa,
        array $versiones,
        bool $isPreview = false
    ): string {
        $margenes = $formatoPdf['margenes'] ?? [];
        $mt = (float)($margenes['superior'] ?? 3);
        $mb = (float)($margenes['inferior'] ?? 2);
        $ml = (float)($margenes['izquierdo'] ?? 3);
        $mr = (float)($margenes['derecho'] ?? 2);
        $headerHeightCm = 2.5;
        $contentGapCm = 0.65;

        $secciones = $context['secciones'] ?? [];
        $contenido = $context['contenido'] ?? [];
        $codigos = array_map(static fn($s) => (string)($s['codigo'] ?? ''), $secciones);
        $hasPortada = in_array('portada', $codigos, true);
        $hasToc = in_array('tabla_contenido', $codigos, true);
        $hasEncabezado = in_array('encabezado', $codigos, true);

        $docNombreRaw = trim((string)($documento['nombre'] ?? ''));
        $procesoNombreRaw = trim((string)($documento['proceso_nombre'] ?? ''));
        $titulosConfig = SgdSeccionService::normalizeTitulosConfig($formatoPdf['titulos'] ?? null);
        $docNombre = htmlspecialchars($this->formatPdfHeaderTitle($docNombreRaw, $titulosConfig['niveles'][0] ?? null), ENT_QUOTES, 'UTF-8');
        $procesoNombre = htmlspecialchars($this->formatPdfHeaderTitle($procesoNombreRaw, $titulosConfig['niveles'][0] ?? null), ENT_QUOTES, 'UTF-8');
        $codigoEsc = htmlspecialchars($codigoDisplay, ENT_QUOTES, 'UTF-8');
        $versionNum = htmlspecialchars((string)($version['numero'] ?? ''), ENT_QUOTES, 'UTF-8');
        $empresaNombreCover = htmlspecialchars(
            $this->formatPdfHeaderTitle((string)($empresa['razon_social'] ?? ''), $titulosConfig['niveles'][0] ?? null),
            ENT_QUOTES,
            'UTF-8'
        );
        $vigenteDisplay = htmlspecialchars($this->formatVigenteDate($version, $documento), ENT_QUOTES, 'UTF-8');
        $pieLegalRaw = trim((string)($formatoPdf['pie_pagina'] ?? ''));

        $titulosCss = str_replace(
            ['.sgd-word-page', '.sgd-word-editor', '.sgd-word-sec-head .sgd-word-sec-label', '.sgd-word-page-name'],
            ['body', '.sgd-pdf-body', '.sgd-pdf-sec-title, .sgd-pdf-toc-entry-title', '.sgd-pdf-doc-title'],
            SgdElaboracionService::buildTitulosCss($formatoPdf)
        );
        foreach (['h2' => 0, 'h3' => 1, 'h4' => 2, 'h5' => 3, 'h6' => 4] as $tag => $idx) {
            $titulosCss = str_replace(
                '.sgd-word-editor ' . $tag,
                '.sgd-pdf-body ' . $tag . ', .sgd-pdf-toc-l' . $idx,
                $titulosCss
            );
        }

        $logoHtml = '';
        $logoPath = trim((string)($empresa['logo'] ?? ''));
        if ($logoPath !== '') {
            $abs = $this->publicPathToFilesystem($logoPath);
            if ($abs !== null) {
                $logoHtml = '<img src="' . htmlspecialchars($abs, ENT_QUOTES, 'UTF-8') . '" class="sgd-pdf-logo" alt="">';
            }
        }

        $this->pdfBuildArgs = [
            $empresaId,
            $documento,
            $codigoDisplay,
            $version,
            $context,
            $formatoPdf,
            $empresa,
            $versiones,
            $isPreview,
        ];

        $body = '';

        if ($hasPortada) {
            $body .= $this->renderCoverPage($docNombre, $empresaNombreCover, $pieLegalRaw, $vigenteDisplay);
        }

        $tocEntries = $this->buildTocEntries($secciones, $contenido);
        $this->needsTocPass = $hasToc && $tocEntries !== [];
        if ($this->needsTocPass) {
            $body .= $this->renderTocPage(
                $tocEntries,
                $this->findTocHeading($secciones),
                $this->tocPageNumbers
            );
        }

        $body .= '<div class="sgd-pdf-main">';
        foreach ($secciones as $sec) {
            $codigo = (string)($sec['codigo'] ?? '');
            $clase = (string)($sec['clase'] ?? '');
            if (in_array($codigo, ['portada', 'tabla_contenido', 'encabezado'], true)) {
                continue;
            }
            if ($codigo === 'aprobacion' && $hasPortada) {
                continue;
            }

            $sectionHtml = match ($codigo) {
                'documentos_referenciados' => $this->renderReferenciados($empresaId, $sec, $contenido[$codigo] ?? []),
                'control_cambios' => $this->renderControlCambios($sec, $versiones, $version),
                'aprobacion' => $this->renderAprobacion($sec, $vigenteDisplay),
                'anexos' => $this->renderAnexos($sec, $contenido[$codigo] ?? []),
                default => $clase === 'contenido'
                    ? $this->renderContenido($sec, $contenido[$codigo] ?? '')
                    : '',
            };

            if ($sectionHtml === '') {
                continue;
            }

            $anchor = 'sec-' . $codigo;
            $body .= '<section id="' . htmlspecialchars($anchor, ENT_QUOTES, 'UTF-8') . '" class="sgd-pdf-section">';
            $body .= '<h2 class="sgd-pdf-sec-title">' . htmlspecialchars($this->sectionTitle($sec), ENT_QUOTES, 'UTF-8') . '</h2>';
            $body .= '<div class="sgd-pdf-body">' . $sectionHtml . '</div>';
            $body .= '</section>';
        }
        $body .= '</div>';

        $pageHeaderHtml = '';
        $this->pageNumberOverlay = null;
        if ($hasEncabezado) {
            $pageHeaderHtml = $this->buildPageHeaderHtml(
                $logoHtml,
                $procesoNombre,
                $docNombre,
                $codigoEsc,
                $versionNum,
                $vigenteDisplay
            );
            $this->pageNumberOverlay = [
                'mt' => $mt,
                'headerHeightCm' => $headerHeightCm,
            ];
        }

        $previewBanner = '';
        if ($isPreview) {
            $previewBanner = '<div class="sgd-pdf-preview-banner">VISTA PREVIA — Documento no oficial. Revise el contenido antes de publicar.</div>';
        }

        $pageFooterHtml = $pieLegalRaw !== ''
            ? $this->buildPageFooterHtml($pieLegalRaw)
            : '';

        $bodyPadTopCm = $hasEncabezado ? ($headerHeightCm + $contentGapCm) : 0;
        $pageMarginTopCm = $hasEncabezado ? ($mt + $headerHeightCm + $contentGapCm) : $mt;
        // Encabezado fijo en el margen superior (Dompdf: posición = top + margin-top de @page).
        $headerTopCm = $hasEncabezado ? -$bodyPadTopCm : 0;

        return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><style>'
            . '@page{margin:' . $pageMarginTopCm . 'cm ' . $mr . 'cm ' . $mb . 'cm ' . $ml . 'cm;}'
            . 'body{font-family:Arial,Helvetica,sans-serif;color:#555;font-size:11pt;line-height:1.45;text-align:justify;margin:0;padding:0;}'
            . '.sgd-pdf-page-footer-wrap{position:fixed;bottom:-' . $mb . 'cm;left:' . $ml . 'cm;right:' . $mr . 'cm;'
            . 'font-size:7pt;font-style:italic;color:#666;text-align:center;line-height:1.35;}'
            . '.sgd-pdf-page-footer{margin:0;padding:0;}'
            . '.sgd-pdf-preview-banner{position:fixed;bottom:' . ($pieLegalRaw !== '' ? '0.85' : '0.35') . 'cm;left:' . $ml . 'cm;right:' . $mr . 'cm;z-index:50;'
            . 'background:#fff3cd;border:1px solid #e0c060;color:#664d03;padding:5px 8px;font-size:7.5pt;text-align:center;font-weight:700;}'
            . '.sgd-pdf-page-break{page-break-after:always;height:0;margin:0;padding:0;line-height:0;font-size:0;}'
            . '.sgd-pdf-cover{page-break-after:always;text-align:center;}'
            . '.sgd-pdf-cover-body{padding-top:0.25cm;padding-bottom:0.35cm;}'
            . '.sgd-pdf-cover-title{font-size:14pt;font-weight:700;color:#111;margin:0 0 0.45em;text-transform:uppercase;text-align:center;}'
            . '.sgd-pdf-cover-subtitle{font-size:12pt;font-weight:700;color:#111;margin:0 0 0.6em;text-transform:uppercase;text-align:center;}'
            . '.sgd-pdf-cover-legal{font-size:10pt;font-style:italic;color:#444;margin:1.2em auto 0;max-width:88%;line-height:1.35;text-align:center;}'
            . '.sgd-pdf-cover-footer{margin-top:2.5cm;}'
            . '.sgd-pdf-approval{width:100%;border-collapse:collapse;font-size:8pt;color:#444;}'
            . '.sgd-pdf-approval th{background:#f5e6df;border:1px solid #888;padding:5px 6px;text-align:center;font-weight:700;}'
            . '.sgd-pdf-approval td{border:1px solid #888;padding:5px 6px;text-align:center;vertical-align:middle;}'
            . '.sgd-pdf-toc-page{margin-bottom:0.5em;}'
            . '.sgd-pdf-toc-rows{margin-top:0.45em;}'
            . '.sgd-pdf-toc-row{width:100%;border-collapse:collapse;margin:0 0 0.35em;table-layout:fixed;}'
            . '.sgd-pdf-toc-row td{vertical-align:bottom;padding:0;line-height:1.3;}'
            . '.sgd-pdf-toc-entry-title{width:72%;text-align:left;word-wrap:break-word;overflow-wrap:break-word;padding-right:0.35em;}'
            . '.sgd-pdf-toc-leader{border-bottom:1px dotted #888;line-height:1;padding-bottom:0.1em;}'
            . '.sgd-pdf-toc-page-num{width:2.2em;text-align:right;white-space:nowrap;padding-left:0.25em;}'
            . '.sgd-pdf-section{margin:0 0 1.2em;page-break-inside:avoid;}'
            . '.sgd-pdf-sec-title{margin:0 0 .5em;text-align:left;color:#555;}'
            . '.sgd-pdf-body p{margin:0 0 .65em;}'
            . '.sgd-pdf-body table{border-collapse:collapse;width:100%;margin:0 0 1em;font-size:10pt;}'
            . '.sgd-pdf-body th,.sgd-pdf-body td{border:1px solid #444;padding:4px 6px;vertical-align:top;}'
            . '.sgd-pdf-body th{font-weight:700;background:#f3f3f3;}'
            . '.sgd-pdf-body ul,.sgd-pdf-body ol{margin:0 0 .65em 1.4em;padding:0 0 0 1.2em;}'
            . '.sgd-pdf-body img{max-width:100%;height:auto;}'
            . '.sgd-pdf-page-header-wrap{position:fixed;top:' . $headerTopCm . 'cm;left:0;right:0;height:' . $headerHeightCm . 'cm;}'
            . '.sgd-pdf-page-header{width:100%;height:100%;border-collapse:collapse;table-layout:fixed;}'
            . '.sgd-pdf-page-header td{border:1px solid #888;vertical-align:middle;padding:3px 6px;font-size:8.5pt;color:#555;line-height:1.25;}'
            . '.sgd-pdf-hdr-logo{width:26%;text-align:center;}'
            . '.sgd-pdf-hdr-logo img.sgd-pdf-logo{max-height:1.85cm;max-width:95%;width:auto;height:auto;}'
            . '.sgd-pdf-hdr-process,.sgd-pdf-hdr-title{width:48%;text-align:center;font-size:9pt;}'
            . '.sgd-pdf-hdr-meta{width:26%;padding:0;vertical-align:top;}'
            . '.sgd-pdf-hdr-meta-inner{width:100%;height:100%;border-collapse:collapse;table-layout:fixed;}'
            . '.sgd-pdf-hdr-meta-inner td{border:none;border-bottom:1px solid #888;padding:3px 6px;font-size:8pt;text-align:left;vertical-align:middle;line-height:1.2;}'
            . '.sgd-pdf-hdr-meta-inner tr:last-child td{border-bottom:none;padding-bottom:2px;}'
            . '.sgd-pdf-hdr-page-cell{height:0.62cm;vertical-align:middle;}'
            . $titulosCss
            . '</style></head><body>'
            . $pageHeaderHtml
            . $pageFooterHtml
            . $body
            . $previewBanner
            . '</body></html>';
    }

    /**
     * Encabezado M4: logo | proceso + título | metadatos (código, versión, vigente, página).
     */
    private function buildPageHeaderHtml(
        string $logoHtml,
        string $procesoNombre,
        string $docNombre,
        string $codigoEsc,
        string $versionNum,
        string $vigenteDisplay
    ): string {
        $logoCell = $logoHtml !== ''
            ? $logoHtml
            : '&nbsp;';

        return '<div class="sgd-pdf-page-header-wrap"><table class="sgd-pdf-page-header" role="presentation">'
            . '<tr>'
            . '<td class="sgd-pdf-hdr-logo" rowspan="2">' . $logoCell . '</td>'
            . '<td class="sgd-pdf-hdr-process">' . ($procesoNombre !== '' ? $procesoNombre : '&nbsp;') . '</td>'
            . '<td class="sgd-pdf-hdr-meta" rowspan="2">'
            . '<table class="sgd-pdf-hdr-meta-inner" role="presentation">'
            . '<tr><td>Código: ' . $codigoEsc . '</td></tr>'
            . '<tr><td>Versión: ' . $versionNum . '</td></tr>'
            . '<tr><td>Vigente: ' . ($vigenteDisplay !== '' ? $vigenteDisplay : '&nbsp;') . '</td></tr>'
            . '<tr><td class="sgd-pdf-hdr-page-cell"><span class="sgd-pdf-hdr-page-label">Página </span></td></tr>'
            . '</table></td>'
            . '</tr>'
            . '<tr><td class="sgd-pdf-hdr-title">' . ($docNombre !== '' ? $docNombre : '&nbsp;') . '</td></tr>'
            . '</table></div>';
    }

    /**
     * Pie de página fijo en margen inferior (todas las páginas).
     */
    private function buildPageFooterHtml(string $pieLegal): string
    {
        return '<div class="sgd-pdf-page-footer-wrap"><p class="sgd-pdf-page-footer">'
            . htmlspecialchars($pieLegal, ENT_QUOTES, 'UTF-8')
            . '</p></div>';
    }

    /**
     * @param list<array<string, mixed>> $secciones
     */
    private function findTocHeading(array $secciones): string
    {
        foreach ($secciones as $sec) {
            if ((string)($sec['codigo'] ?? '') === 'tabla_contenido') {
                $nombre = trim((string)($sec['nombre'] ?? ''));

                return $nombre !== '' ? $nombre : 'Tabla de contenido';
            }
        }

        return 'Tabla de contenido';
    }

    /**
     * @param list<array<string, mixed>> $secciones
     * @param array<string, mixed> $contenido
     * @return list<array{title: string, anchor: string, level: int, tocClass?: string}>
     */
    private function buildTocEntries(array $secciones, array $contenido): array
    {
        $entries = [];
        foreach ($secciones as $sec) {
            $codigo = (string)($sec['codigo'] ?? '');
            if (in_array($codigo, ['portada', 'tabla_contenido', 'encabezado', 'aprobacion'], true)) {
                continue;
            }
            $titulo = $this->sectionTitle($sec);
            if ($titulo === '') {
                continue;
            }
            $entries[] = [
                'title' => $titulo,
                'anchor' => 'sec-' . $codigo,
                'level' => 1,
            ];

            if ($codigo === 'anexos') {
                $entries = array_merge($entries, $this->buildAnexosTocEntries($contenido[$codigo] ?? []));
                continue;
            }

            $raw = $contenido[$codigo] ?? '';
            if (is_string($raw) && trim(strip_tags($raw)) !== '') {
                $entries = array_merge(
                    $entries,
                    $this->extractContentHeadingEntries(
                        SgdElaboracionService::sanitizeRichHtml($raw),
                        $codigo
                    )
                );
            }
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array{title: string, anchor: string, level: int, tocClass: string}>
     */
    private function buildAnexosTocEntries(array $data): array
    {
        $bloques = $data['bloques'] ?? [];
        if (!is_array($bloques) || $bloques === []) {
            return [];
        }

        $entries = [];
        $n = 0;
        foreach ($bloques as $bloque) {
            if (!is_array($bloque)) {
                continue;
            }
            $titulo = trim((string)($bloque['titulo'] ?? ''));
            $cuerpo = SgdElaboracionService::sanitizeRichHtml((string)($bloque['cuerpo'] ?? ''));
            if ($titulo === '' && trim(strip_tags($cuerpo)) === '') {
                continue;
            }
            if ($titulo === '') {
                $titulo = 'Anexo ' . ($n + 1);
            }
            $entries[] = [
                'title' => $titulo,
                'anchor' => 'sec-anexos-h-' . $n,
                'level' => 2,
                'tocClass' => 'sgd-pdf-toc-l0',
            ];
            $entries = array_merge(
                $entries,
                $this->extractContentHeadingEntries($cuerpo, 'anexos-b' . $n)
            );
            $n++;
        }

        return $entries;
    }

    /**
     * @return list<array{title: string, anchor: string, level: int, tocClass: string}>
     */
    private function extractContentHeadingEntries(string $html, string $sectionCod): array
    {
        $entries = [];
        $index = 0;
        if (!preg_match_all('/<h([2-6])\b[^>]*>(.*?)<\/h\1>/is', $html, $matches, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as $match) {
            $tagNum = (int)$match[1];
            if ($tagNum < self::TOC_OUTLINE_MIN_H) {
                $index++;
                continue;
            }
            $text = trim(preg_replace('/\s+/u', ' ', strip_tags($match[2])));
            if ($text === '') {
                $index++;
                continue;
            }
            $entries[] = [
                'title' => $text,
                'anchor' => 'sec-' . $sectionCod . '-h-' . $index,
                'level' => $tagNum - 1,
                'tocClass' => 'sgd-pdf-toc-l' . ($tagNum - self::TOC_OUTLINE_MIN_H),
            ];
            $index++;
        }

        return $entries;
    }

    /**
     * Asigna ids estables a h2–h6 para anclas del índice (mismo orden que extractContentHeadingEntries).
     */
    private function injectContentHeadingIds(string $html, string $sectionCod): string
    {
        if ($html === '' || stripos($html, '<h') === false) {
            return $html;
        }

        $index = 0;

        return (string)preg_replace_callback(
            '/<h([2-6])\b([^>]*)>(.*?)<\/h\1>/is',
            static function (array $match) use ($sectionCod, &$index): string {
                $tag = $match[1];
                $attrs = $match[2];
                $inner = $match[3];
                if (preg_match('/\bid\s*=\s*["\'][^"\']*["\']/i', $attrs)) {
                    $index++;

                    return $match[0];
                }
                $id = 'sec-' . $sectionCod . '-h-' . $index;
                $index++;
                $attrs = trim($attrs);
                $attrStr = $attrs !== '' ? ' ' . $attrs : '';

                return '<h' . $tag . ' id="' . $id . '"' . $attrStr . '>' . $inner . '</h' . $tag . '>';
            },
            $html
        );
    }

    /**
     * @param list<array{title: string, anchor: string, level: int, tocClass?: string}> $entries
     * @param array<string, int>|null $pageNumbers
     */
    private function renderTocPage(array $entries, string $heading, ?array $pageNumbers): string
    {
        $rows = '';
        foreach ($entries as $entry) {
            $pageNum = $pageNumbers[$entry['anchor']] ?? null;
            $pageCell = $pageNum !== null ? (string)$pageNum : '';
            $level = (int)($entry['level'] ?? 1);
            $indentEm = max(0, $level - 1) * 0.75;
            $titleClass = 'sgd-pdf-toc-entry-title';
            if ($level > 1 && !empty($entry['tocClass'])) {
                $titleClass .= ' ' . $entry['tocClass'];
            }
            $indentAttr = $indentEm > 0
                ? ' style="padding-left:' . round($indentEm, 2) . 'em"'
                : '';
            $rows .= '<table class="sgd-pdf-toc-row" role="presentation"><tr>'
                . '<td class="' . $titleClass . '"' . $indentAttr . '>'
                . htmlspecialchars($entry['title'], ENT_QUOTES, 'UTF-8')
                . '</td>'
                . '<td class="sgd-pdf-toc-leader"></td>'
                . '<td class="sgd-pdf-toc-page-num">'
                . htmlspecialchars($pageCell, ENT_QUOTES, 'UTF-8')
                . '</td>'
                . '</tr></table>';
        }

        return '<div class="sgd-pdf-toc-page">'
            . '<h2 class="sgd-pdf-sec-title">' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</h2>'
            . '<div class="sgd-pdf-toc-rows">' . $rows . '</div>'
            . '</div>'
            . '<div class="sgd-pdf-page-break"></div>';
    }

    /**
     * Portada M4: encabezado en flujo + título + empresa + aviso legal + aprobación.
     */
    private function renderCoverPage(
        string $docNombre,
        string $empresaNombre,
        string $pieLegal,
        string $vigenteDisplay
    ): string {
        $legal = $pieLegal !== ''
            ? '<p class="sgd-pdf-cover-legal"><em>' . htmlspecialchars($pieLegal, ENT_QUOTES, 'UTF-8') . '</em></p>'
            : '';

        return '<div class="sgd-pdf-cover">'
            . '<div class="sgd-pdf-cover-body">'
            . '<h1 class="sgd-pdf-cover-title">' . ($docNombre !== '' ? $docNombre : '&nbsp;') . '</h1>'
            . ($empresaNombre !== '' ? '<p class="sgd-pdf-cover-subtitle">' . $empresaNombre . '</p>' : '')
            . $legal
            . '</div>'
            . '<div class="sgd-pdf-cover-footer">' . $this->renderAprobacionCover($vigenteDisplay) . '</div>'
            . '</div>';
    }

    /**
     * Tabla de aprobación en portada (estructura M4; datos de firmantes en F5).
     */
    private function renderAprobacionCover(string $vigenteDisplay): string
    {
        $fechaApr = $vigenteDisplay !== '' ? 'FECHA: ' . $vigenteDisplay : 'FECHA:';

        return '<table class="sgd-pdf-approval" role="presentation">'
            . '<tr>'
            . '<th>ELABORADO POR:</th><th>REVISADO POR:</th><th>APROBADO POR:</th>'
            . '</tr>'
            . '<tr><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr>'
            . '<tr><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr>'
            . '<tr><td>FECHA:</td><td>FECHA:</td><td>' . htmlspecialchars($fechaApr, ENT_QUOTES, 'UTF-8') . '</td></tr>'
            . '</table>';
    }

    /**
     * Dibuja «N de M» junto a la etiqueta «Página» de la celda de metadatos del encabezado M4.
     */
    private function applyPageNumberOverlay(Dompdf $dompdf): void
    {
        if ($this->pageNumberOverlay === null) {
            return;
        }

        $mtCm = (float)$this->pageNumberOverlay['mt'];
        $headerHeightCm = (float)$this->pageNumberOverlay['headerHeightCm'];

        $canvas = $dompdf->getCanvas();
        $fontMetrics = $dompdf->getFontMetrics();
        $pageW = $canvas->get_width();
        $cmPt = 28.3465;

        $mtPt = $mtCm * $cmPt;
        $headerHeightPt = $headerHeightCm * $cmPt;
        $rowHeightPt = self::HDR_PAGE_ROW_CM * $cmPt;
        $rowTopPt = $mtPt + $headerHeightPt - $rowHeightPt;

        $font = $fontMetrics->getFont('helvetica', 'normal');
        $labelWidth = $fontMetrics->getTextWidth(self::HDR_PAGE_LABEL, $font, self::HDR_PAGE_FONT_PT);
        $metaColX = $pageW * (self::HDR_LOGO_FRAC + self::HDR_CENTER_FRAC);
        $pageNumX = $metaColX + self::HDR_META_PAD_X_PT + $labelWidth;

        $fontHeightPt = $fontMetrics->getFontHeight($font, self::HDR_PAGE_FONT_PT);
        $innerHeightPt = $rowHeightPt - (2 * self::HDR_META_PAD_Y_PT);
        $pageLineY = $rowTopPt + self::HDR_META_PAD_Y_PT + (($innerHeightPt - $fontHeightPt) / 2) - 0.5;
        $color = [0.35, 0.35, 0.35];

        $canvas->page_script(
            static function (
                int $pageNumber,
                int $pageCount,
                $canvas,
                $fontMetrics
            ) use (
                $pageNumX,
                $pageLineY,
                $color
            ): void {
                $font = $fontMetrics->getFont('helvetica', 'normal');
                $canvas->text(
                    $pageNumX,
                    $pageLineY,
                    $pageNumber . ' de ' . $pageCount,
                    $font,
                    SgdPdfGenerationService::HDR_PAGE_FONT_PT,
                    $color
                );
            }
        );
    }

    /**
     * @param array{nombre?: string, ejemplo?: string, mayusculas?: bool, mayusculas_inicial?: bool}|null $nivel
     */
    private function formatPdfHeaderTitle(string $text, ?array $nivel): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        if ($nivel !== null && !empty($nivel['mayusculas'])) {
            return mb_strtoupper($text, 'UTF-8');
        }
        if ($nivel !== null && !empty($nivel['mayusculas_inicial'])) {
            return mb_convert_case($text, MB_CASE_TITLE, 'UTF-8');
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $version
     * @param array<string, mixed> $documento
     */
    private function formatVigenteDate(array $version, array $documento): string
    {
        $raw = trim((string)($version['fecha_aprobacion'] ?? ''));
        if ($raw === '') {
            $raw = trim((string)($documento['fecha_ultima_aprobacion'] ?? ''));
        }
        if ($raw === '') {
            return '';
        }

        $dt = DateTime::createFromFormat('Y-m-d', $raw);

        return $dt && $dt->format('Y-m-d') === $raw ? $dt->format('Y/m/d') : $raw;
    }

    /**
     * @param array<string, mixed> $sec
     */
    private function sectionTitle(array $sec): string
    {
        $nombre = trim((string)($sec['nombre'] ?? ''));
        $num = $sec['numero_visible'] ?? null;
        if ($num !== null && (int)$num > 0) {
            return (int)$num . '. ' . $nombre;
        }

        return $nombre;
    }

    /**
     * @param array<string, mixed> $sec
     */
    private function renderContenido(array $sec, mixed $raw): string
    {
        $html = is_string($raw) ? $raw : '';
        $html = SgdElaboracionService::sanitizeRichHtml($html);
        if (trim(strip_tags($html)) === '') {
            return '';
        }

        return $this->absolutizeImagePaths(
            $this->injectContentHeadingIds($html, (string)($sec['codigo'] ?? ''))
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderReferenciados(int $empresaId, array $sec, array $data): string
    {
        $ids = $data['documento_ids'] ?? [];
        if (!is_array($ids) || $ids === []) {
            return '<p>—</p>';
        }

        $rows = [];
        $allById = [];
        foreach ($this->repo->listDocumentosForSelect($empresaId) as $doc) {
            $allById[(int)$doc['id']] = $doc;
        }
        foreach ($allById as $doc) {
            $id = (int)($doc['id'] ?? 0);
            if (!in_array($id, array_map('intval', $ids), true)) {
                continue;
            }
            $codigo = $this->codigoService->buildForRow($doc, $allById);
            $rows[] = '<tr><td>' . htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8')
                . '</td><td>' . htmlspecialchars((string)($doc['nombre'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }

        if ($rows === []) {
            return '<p>—</p>';
        }

        return '<table><thead><tr><th>Código</th><th>Título</th></tr></thead><tbody>' . implode('', $rows) . '</tbody></table>';
    }

    /**
     * @param list<array<string, mixed>> $versiones
     * @param array<string, mixed> $versionActual
     */
    private function renderControlCambios(array $sec, array $versiones, array $versionActual): string
    {
        if ($versiones === []) {
            return '<p>Sin historial de versiones.</p>';
        }

        $rows = [];
        foreach ($versiones as $ver) {
            $rows[] = '<tr><td>' . htmlspecialchars((string)($ver['numero'] ?? ''), ENT_QUOTES, 'UTF-8')
                . '</td><td>' . htmlspecialchars((string)($ver['fecha_aprobacion'] ?? ''), ENT_QUOTES, 'UTF-8')
                . '</td><td>' . htmlspecialchars((string)($ver['estado_nombre'] ?? ''), ENT_QUOTES, 'UTF-8')
                . '</td><td>' . htmlspecialchars((string)($ver['notas'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }

        return '<table><thead><tr><th>Versión</th><th>Aprobación</th><th>Estado</th><th>Notas</th></tr></thead><tbody>'
            . implode('', $rows) . '</tbody></table>';
    }

    /**
     * @param array<string, mixed> $sec
     */
    private function renderAprobacion(array $sec, string $fechaApr): string
    {
        return '<table><thead><tr><th>Rol</th><th>Nombre</th><th>Fecha</th></tr></thead><tbody>'
            . '<tr><td>Elaboró</td><td></td><td></td></tr>'
            . '<tr><td>Revisó</td><td></td><td></td></tr>'
            . '<tr><td>Aprobó</td><td></td><td>' . $fechaApr . '</td></tr>'
            . '</tbody></table>'
            . '<p class="sgd-pdf-note">Las firmas electrónicas se habilitarán en una fase posterior (F5).</p>';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderAnexos(array $sec, array $data): string
    {
        $bloques = $data['bloques'] ?? [];
        if (!is_array($bloques) || $bloques === []) {
            return '';
        }

        $html = '';
        $n = 0;
        foreach ($bloques as $bloque) {
            if (!is_array($bloque)) {
                continue;
            }
            $titulo = trim((string)($bloque['titulo'] ?? ''));
            $cuerpo = SgdElaboracionService::sanitizeRichHtml((string)($bloque['cuerpo'] ?? ''));
            if ($titulo === '' && trim(strip_tags($cuerpo)) === '') {
                continue;
            }
            $idx = $n;
            $n++;
            if ($titulo === '') {
                $titulo = 'Anexo ' . $n;
            }
            $html .= '<h3 id="sec-anexos-h-' . $idx . '">'
                . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '</h3>';
            $html .= $this->absolutizeImagePaths(
                $this->injectContentHeadingIds($cuerpo, 'anexos-b' . $idx)
            );
        }

        return $html;
    }

    private function absolutizeImagePaths(string $html): string
    {
        return (string)preg_replace_callback(
            '/\bsrc=(["\'])(\/uploads\/[^"\']+)\1/i',
            function (array $m): string {
                $abs = $this->publicPathToFilesystem($m[2]);

                return $abs !== null ? 'src="' . $abs . '"' : $m[0];
            },
            $html
        );
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
            if ($this->needsTocPass && $this->tocPageNumbers === null) {
                $probe = $this->createDompdf($html, $formatoPdf);
                $probe->render();
                $this->tocPageNumbers = $this->resolveAnchorPageNumbers($probe);
                if ($this->pdfBuildArgs !== null) {
                    $html = $this->buildHtml(...$this->pdfBuildArgs);
                }
            }

            $dompdf = $this->createDompdf($html, $formatoPdf);
            $dompdf->render();
            $this->applyPageNumberOverlay($dompdf);

            return $dompdf->output();
        } catch (Throwable $e) {
            error_log('SgdPdfGenerationService: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * @param array<string, mixed> $formatoPdf
     */
    private function createDompdf(string $html, array $formatoPdf): Dompdf
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isPhpEnabled', true);
        $options->set('chroot', BASE_PATH . '/public');
        $options->set('defaultFont', trim((string)($formatoPdf['fuente_cuerpo'] ?? 'Arial')) ?: 'Arial');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');

        return $dompdf;
    }

    /**
     * @return array<string, int> anchor id => número de página (1-based)
     */
    private function resolveAnchorPageNumbers(Dompdf $dompdf): array
    {
        $canvas = $dompdf->getCanvas();
        if (!$canvas instanceof CPDF) {
            return [];
        }

        $cpdf = $canvas->get_cpdf();
        $pageIdToNum = [];
        foreach ($cpdf->objects as $id => $obj) {
            if (!is_array($obj) || ($obj['t'] ?? '') !== 'page') {
                continue;
            }
            $pageNum = $obj['info']['pageNum'] ?? null;
            if ($pageNum !== null) {
                $pageIdToNum[(int)$id] = (int)$pageNum;
            }
        }

        $result = [];
        foreach ($cpdf->destinations as $label => $destObjId) {
            $destObj = $cpdf->objects[$destObjId] ?? null;
            if (!is_array($destObj)) {
                continue;
            }
            $pageObjId = $destObj['info']['page'] ?? null;
            if ($pageObjId === null || !isset($pageIdToNum[(int)$pageObjId])) {
                continue;
            }
            $result[(string)$label] = $pageIdToNum[(int)$pageObjId];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $formatoPdf
     */
    private function writePdf(string $html, array $formatoPdf): bool
    {
        $this->lastPdfBinary = $this->renderPdfBinary($html, $formatoPdf);
        if ($this->lastPdfBinary === null || $this->lastPdfBinary === '') {
            $this->lastPdfBinary = null;

            return false;
        }

        return true;
    }
}
