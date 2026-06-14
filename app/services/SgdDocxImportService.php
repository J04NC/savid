<?php

/**
 * Convierte .docx (OOXML) a HTML para el editor de elaboración.
 * Sin dependencias externas: lee word/document.xml dentro del ZIP.
 */
class SgdDocxImportService
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const R_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const A_NS = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    private SgdScopeService $scope;
    private SgdRepository $repo;

    /** @var array<int, bool> numId => ordered */
    private array $numIdOrdered = [];

    /** @var array<int, int> numId => abstractNumId */
    private array $numIdToAbstract = [];

    /**
     * @var array<int, array<int, array{lvlText: string, start: int, numFmt: string}>>
     */
    private array $abstractLevels = [];

    /** @var array<string, int> */
    private array $listCounters = [];

    /** @var array<string, string> rId => target path in zip */
    private array $rels = [];

    private int $empresaId = 0;
    private int $documentoId = 0;
    private int $lastOutlineChapter = 0;

    /** @var array{niveles: list<array<string, mixed>>} */
    private array $titulosConfig = ['niveles' => []];

    public function __construct()
    {
        $this->scope = new SgdScopeService();
        $this->repo = new SgdRepository();
    }

    /**
     * @param array<string, mixed> $files
     * @param array<string, mixed> $post
     * @param array<string, mixed> $query
     * @return array{success: bool, message: string, html?: string, plain?: string, stats?: array<string, int>, messages?: list<string>}
     */
    public function import(array $files, array $post, array $query): array
    {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');

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

        $file = $files['archivo'] ?? null;
        if (!is_array($file) || empty($file['tmp_name']) || !is_uploaded_file((string)$file['tmp_name'])) {
            return ['success' => false, 'message' => 'Seleccione un archivo Word (.docx).'];
        }

        if ((int)($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Error al subir el archivo Word.'];
        }

        $name = (string)($file['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== 'docx') {
            return ['success' => false, 'message' => 'Solo archivos .docx (Word 2007 o superior).'];
        }

        $tmp = (string)$file['tmp_name'];
        $archive = DocxArchiveReader::open($tmp);
        if ($archive === null) {
            return [
                'success' => false,
                'message' => 'No se pudo leer el Word. Active la extensión PHP zip o permita el comando unzip en el servidor.',
            ];
        }

        $documentXml = $archive->getFromName('word/document.xml');
        if ($documentXml === false || trim($documentXml) === '') {
            $archive->close();

            return ['success' => false, 'message' => 'El Word no contiene documento legible.'];
        }

        $this->loadRelationships($archive);
        $this->loadNumbering($archive);
        $this->listCounters = [];
        $this->lastOutlineChapter = 0;

        $this->empresaId = $empresaId;
        $this->documentoId = $documentoId;

        $config = $this->repo->findConfigByEmpresaId($empresaId);
        $configExtra = SgdConfigService::parseConfigJson($config);
        $formatoPdf = $configExtra['formato_pdf'] ?? SgdSeccionService::defaultFormatoPdf();
        $this->titulosConfig = SgdSeccionService::normalizeTitulosConfig($formatoPdf['titulos'] ?? null);

        $mediaDir = BASE_PATH . '/public/uploads/sgd/' . $empresaId . '/' . $documentoId . '/media';
        if (!is_dir($mediaDir) && !@mkdir($mediaDir, 0755, true) && !is_dir($mediaDir)) {
            $archive->close();

            return ['success' => false, 'message' => 'No se pudo crear carpeta de medios.'];
        }

        $stats = ['paragraphs' => 0, 'tables' => 0, 'lists' => 0, 'images' => 0, 'headings' => 0];
        $messages = [];

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $xmlFlags = defined('LIBXML_PARSEHUGE') ? LIBXML_PARSEHUGE : 0;
        if (@$dom->loadXML($documentXml, $xmlFlags) === false) {
            $archive->close();

            return ['success' => false, 'message' => 'XML del Word inválido.'];
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::W_NS);
        $xpath->registerNamespace('r', self::R_NS);
        $xpath->registerNamespace('a', self::A_NS);

        $body = $xpath->query('//w:body')->item(0);
        if (!$body instanceof DOMElement) {
            $archive->close();

            return ['success' => false, 'message' => 'Estructura Word no reconocida.'];
        }

        $htmlParts = [];
        $plainParts = [];
        $listBuffer = [];

        $flushList = function () use (&$listBuffer, &$htmlParts, &$stats): void {
            if ($listBuffer === []) {
                return;
            }
            $htmlParts[] = $this->renderListBuffer($listBuffer);
            $stats['lists'] += 1;
            $listBuffer = [];
        };

        foreach ($body->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $local = $child->localName ?: $child->nodeName;

            if ($local === 'p') {
                $numPr = $this->xpathFirst($xpath, 'w:pPr/w:numPr', $child);
                if ($numPr instanceof DOMElement) {
                    $numBlock = $this->paragraphWithNumberingToBlock(
                        $xpath,
                        $child,
                        $numPr,
                        $archive,
                        $mediaDir,
                        $stats,
                        $messages
                    );
                    if ($numBlock !== null) {
                        if ($numBlock['is_section_title'] ?? false) {
                            $flushList();
                            $htmlParts[] = $numBlock['html'];
                            $plainParts[] = $numBlock['plain'];
                            $stats['headings'] += 1;
                            $this->trackOutlineChapterFromPlain($numBlock['plain']);
                            continue;
                        }
                        if ($numBlock['is_outline_title'] ?? false) {
                            $flushList();
                            $htmlParts[] = $numBlock['html'];
                            $plainParts[] = $numBlock['plain'];
                            $stats['headings'] += 1;
                            $this->trackOutlineChapterFromPlain($numBlock['plain']);
                            continue;
                        }
                        $item = $numBlock['list_item'] ?? null;
                        if ($item !== null) {
                            $listBuffer[] = $item;
                            $plainParts[] = $item['text'];
                            continue;
                        }
                    }
                }
                $flushList();

                $block = $this->paragraphToHtml($xpath, $child, $archive, $mediaDir, $stats, $messages);
                if ($block['html'] !== '') {
                    $htmlParts[] = $block['html'];
                    $plainParts[] = $block['plain'];
                    $this->trackOutlineChapterFromPlain($block['plain']);
                    if ($block['is_heading']) {
                        $stats['headings'] += 1;
                    } else {
                        $stats['paragraphs'] += 1;
                    }
                }
                continue;
            }

            if ($local === 'tbl') {
                $flushList();
                $tableHtml = $this->tableToHtml($xpath, $child);
                if ($tableHtml !== '') {
                    $htmlParts[] = $tableHtml;
                    $stats['tables'] += 1;
                }
                continue;
            }
        }
        $flushList();

        $archive->close();

        $html = implode('', $htmlParts);
        $plain = trim(implode("\n", array_filter($plainParts, static fn ($p) => trim((string)$p) !== '')));

        if ($html === '' && $plain === '') {
            return ['success' => false, 'message' => 'El Word no tiene texto extraíble.'];
        }

        if ($html === '' && $plain !== '') {
            $html = '<p>' . htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }

        $html = SgdWordImportStaging::sanitizeUtf8($html);
        $plain = SgdWordImportStaging::sanitizeUtf8($plain);

        $converter = class_exists(ZipArchive::class, false) ? 'php-zip' : 'unzip';
        array_unshift(
            $messages,
            $converter === 'php-zip'
                ? 'Convertido en servidor con PHP Zip (lectura directa del .docx).'
                : 'Convertido en servidor con unzip del sistema.'
        );

        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        $staged = false;
        $importToken = null;
        $inlineHtml = $html;
        $stageThreshold = 25000;
        $htmlBytes = strlen($html);

        if ($htmlBytes >= $stageThreshold) {
            if ($userId <= 0) {
                return [
                    'success' => false,
                    'message' => 'Sesión expirada. Vuelva a iniciar sesión e intente importar de nuevo.',
                ];
            }
            try {
                $importToken = SgdWordImportStaging::store($empresaId, $documentoId, $userId, $html, [
                    'plain' => mb_substr($plain, 0, 800),
                    'stats' => $stats,
                    'messages' => $messages,
                    'converter' => $converter,
                ]);
                $staged = true;
                $inlineHtml = '';
                $messages[] = 'Documento grande: el contenido se descargará en un segundo paso.';
            } catch (RuntimeException $e) {
                error_log('SgdWordImportStaging: ' . $e->getMessage());

                return [
                    'success' => false,
                    'message' => 'No se pudo guardar el documento convertido. Verifique permisos en uploads/sgd: ' . $e->getMessage(),
                ];
            }
        }

        return [
            'success' => true,
            'message' => 'Word convertido correctamente.',
            'html' => $inlineHtml,
            'plain' => $plain,
            'stats' => $stats,
            'messages' => $messages,
            'converter' => $converter,
            'staged' => $staged,
            'import_token' => $importToken,
            'content_chars' => mb_strlen(strip_tags($plain)),
        ];
    }

    private function loadRelationships(DocxArchiveReader $archive): void
    {
        $this->rels = [];
        $relsXml = $archive->getFromName('word/_rels/document.xml.rels');
        if ($relsXml === false) {
            return;
        }
        $dom = new DOMDocument();
        if (@$dom->loadXML($relsXml) === false) {
            return;
        }
        foreach ($dom->getElementsByTagName('Relationship') as $rel) {
            if (!$rel instanceof DOMElement) {
                continue;
            }
            $id = $rel->getAttribute('Id');
            $target = $rel->getAttribute('Target');
            if ($id !== '' && $target !== '') {
                $this->rels[$id] = str_starts_with($target, 'word/') ? $target : 'word/' . ltrim($target, '/');
            }
        }
    }

    private function loadNumbering(DocxArchiveReader $archive): void
    {
        $this->numIdOrdered = [];
        $this->numIdToAbstract = [];
        $this->abstractLevels = [];
        $numXml = $archive->getFromName('word/numbering.xml');
        if ($numXml === false) {
            return;
        }
        $dom = new DOMDocument();
        if (@$dom->loadXML($numXml) === false) {
            return;
        }
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::W_NS);

        $abstractFmt = [];
        foreach ($xpath->query('//w:abstractNum') as $abstract) {
            if (!$abstract instanceof DOMElement) {
                continue;
            }
            $absId = (int)$abstract->getAttributeNS(self::W_NS, 'abstractNumId');
            $lvl0 = $xpath->query('w:lvl[@w:ilvl="0"]', $abstract)->item(0);
            if (!$lvl0 instanceof DOMElement) {
                continue;
            }
            $fmtNode = $xpath->query('w:numFmt', $lvl0)->item(0);
            $fmt = $fmtNode instanceof DOMElement ? $fmtNode->getAttributeNS(self::W_NS, 'val') : 'bullet';
            $abstractFmt[$absId] = $fmt !== 'bullet' && $fmt !== '';

            foreach ($xpath->query('w:lvl', $abstract) as $lvl) {
                if (!$lvl instanceof DOMElement) {
                    continue;
                }
                $ilvl = (int)$lvl->getAttributeNS(self::W_NS, 'ilvl');
                $lvlTextNode = $xpath->query('w:lvlText', $lvl)->item(0);
                $startNode = $xpath->query('w:start', $lvl)->item(0);
                $lvlFmtNode = $xpath->query('w:numFmt', $lvl)->item(0);
                $this->abstractLevels[$absId][$ilvl] = [
                    'lvlText' => $lvlTextNode instanceof DOMElement
                        ? (string)$lvlTextNode->getAttributeNS(self::W_NS, 'val')
                        : '',
                    'start' => $startNode instanceof DOMElement
                        ? max(1, (int)$startNode->getAttributeNS(self::W_NS, 'val'))
                        : 1,
                    'numFmt' => $lvlFmtNode instanceof DOMElement
                        ? (string)$lvlFmtNode->getAttributeNS(self::W_NS, 'val')
                        : 'decimal',
                ];
            }
        }

        foreach ($xpath->query('//w:num') as $num) {
            if (!$num instanceof DOMElement) {
                continue;
            }
            $numId = (int)$num->getAttributeNS(self::W_NS, 'numId');
            $absNode = $xpath->query('w:abstractNumId', $num)->item(0);
            $absId = $absNode instanceof DOMElement ? (int)$absNode->getAttributeNS(self::W_NS, 'val') : 0;
            $this->numIdOrdered[$numId] = $abstractFmt[$absId] ?? false;
            $this->numIdToAbstract[$numId] = $absId;
        }
    }

    private function trackOutlineChapterFromPlain(string $plain): void
    {
        $plain = trim($plain);
        if ($plain === '') {
            return;
        }
        if (preg_match('/^(\d+)\.\d+/u', $plain, $m)) {
            $this->lastOutlineChapter = (int)$m[1];

            return;
        }
        if (preg_match('/^(\d+)[\.\)\-]\s+\S/u', $plain, $m)) {
            $this->lastOutlineChapter = (int)$m[1];
        }
    }

    private function advanceAndFormatListLabel(int $numId, int $ilvl): string
    {
        if ($numId <= 0) {
            return '';
        }

        $abstractId = $this->numIdToAbstract[$numId] ?? 0;
        $lvlDef = $this->abstractLevels[$abstractId][$ilvl] ?? null;
        if ($lvlDef === null) {
            return '';
        }

        if ($ilvl > 0 && !isset($this->listCounters[$numId . ':0']) && $this->lastOutlineChapter > 0) {
            $this->listCounters[$numId . ':0'] = $this->lastOutlineChapter;
        }

        $key = $numId . ':' . $ilvl;
        if (!isset($this->listCounters[$key])) {
            $this->listCounters[$key] = $lvlDef['start'];
        } else {
            $this->listCounters[$key]++;
        }

        for ($d = $ilvl + 1; $d < 9; $d++) {
            unset($this->listCounters[$numId . ':' . $d]);
        }

        $lvlText = trim($lvlDef['lvlText']);
        if ($lvlText === '') {
            return (string)$this->listCounters[$key];
        }

        $formatted = preg_replace_callback(
            '/%(\d+)/',
            function (array $m) use ($numId, $abstractId): string {
                $level = max(0, (int)$m[1] - 1);
                $counterKey = $numId . ':' . $level;
                if (!isset($this->listCounters[$counterKey])) {
                    $start = $this->abstractLevels[$abstractId][$level]['start'] ?? 1;
                    if ($level === 0 && $this->lastOutlineChapter > 0) {
                        $start = $this->lastOutlineChapter;
                    }
                    $this->listCounters[$counterKey] = $start;
                }

                return (string)$this->listCounters[$counterKey];
            },
            $lvlText
        ) ?? '';

        return rtrim(trim($formatted), '.');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function prependListLabel(string $label, string $plain, string $inline): array
    {
        $label = trim($label);
        if ($label === '' || $plain === '') {
            return [$plain, $inline];
        }
        if (preg_match('/^' . preg_quote($label, '/') . '(?:[\.\)\-]|\s)/u', $plain)) {
            return [$plain, $inline];
        }

        $fullPlain = trim($label . ' ' . $plain);
        $labelHtml = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $fullInline = '<strong>' . $labelHtml . '</strong> ' . $inline;

        return [$fullPlain, $fullInline];
    }

    /**
     * @return array{html: string, plain: string, is_heading: bool}|null
     */
    private function blockFromNumberedTitle(string $plain, string $inline): array
    {
        $numberedTag = $this->numberedTitleHeadingTag($plain, $inline);
        if ($numberedTag !== '') {
            return [
                'html' => '<' . $numberedTag . '>' . $inline . '</' . $numberedTag . '>',
                'plain' => $plain,
                'is_heading' => true,
            ];
        }

        $depth = SgdSeccionService::extractLeadingNumberingDepth($plain);
        if ($depth >= 2) {
            $tag = SgdSeccionService::headingTagForNumberingDepth($depth, $this->titulosConfig);
            if ($tag !== '') {
                return [
                    'html' => '<' . $tag . '>' . $inline . '</' . $tag . '>',
                    'plain' => $plain,
                    'is_heading' => true,
                ];
            }
        }

        return [
            'html' => '<p>' . $inline . '</p>',
            'plain' => $plain,
            'is_heading' => false,
        ];
    }

    private function isBulletNumbering(int $numId, int $ilvl): bool
    {
        if ($this->numIdOrdered[$numId] ?? false) {
            return false;
        }

        $abstractId = $this->numIdToAbstract[$numId] ?? 0;
        $lvlDef = $this->abstractLevels[$abstractId][$ilvl] ?? null;
        if ($lvlDef === null) {
            return true;
        }

        $fmt = strtolower((string)($lvlDef['numFmt'] ?? 'bullet'));

        return in_array($fmt, ['bullet', 'none', ''], true);
    }

    private function paragraphIsOutlineTitle(string $fullPlain, string $inline, int $numId, int $ilvl): bool
    {
        if ($fullPlain === '' || mb_strlen($fullPlain) > 130) {
            return false;
        }
        if ($this->isBulletNumbering($numId, $ilvl)) {
            return false;
        }
        if (!($this->numIdOrdered[$numId] ?? false)) {
            return false;
        }

        if ($ilvl < 1) {
            return false;
        }

        return SgdSeccionService::extractLeadingNumberingDepth($fullPlain) >= 2
            && $this->inlineLooksEmphasized($inline, $fullPlain);
    }

    private function inlineLooksEmphasized(string $inline, string $plain): bool
    {
        if (preg_match('/<(?:strong|b)\b/i', $inline)) {
            return true;
        }

        $letters = preg_replace('/[^\p{L}]/u', '', $plain) ?? '';

        return $letters !== '' && mb_strtoupper($letters, 'UTF-8') === $letters;
    }

    /**
     * @return array{is_outline_title: bool, html?: string, plain?: string, list_item?: array{level: int, text: string, ordered: bool}}|null
     */
    private function paragraphWithNumberingToBlock(
        DOMXPath $xpath,
        DOMElement $p,
        DOMElement $numPr,
        DocxArchiveReader $archive,
        string $mediaDir,
        array &$stats,
        array &$messages
    ): ?array {
        $ilvlNode = $this->xpathFirst($xpath, 'w:ilvl', $numPr);
        $numIdNode = $this->xpathFirst($xpath, 'w:numId', $numPr);
        $ilvl = $ilvlNode instanceof DOMElement ? (int)$ilvlNode->getAttributeNS(self::W_NS, 'val') : 0;
        $numId = $numIdNode instanceof DOMElement ? (int)$numIdNode->getAttributeNS(self::W_NS, 'val') : 0;
        if ($numId <= 0) {
            return null;
        }

        $inline = $this->runsToHtml($xpath, $p, $archive, $mediaDir, $stats, $messages);
        $plain = trim(strip_tags($inline));
        if ($plain === '') {
            return null;
        }

        if ($this->isBulletNumbering($numId, $ilvl)) {
            return [
                'is_outline_title' => false,
                'is_section_title' => false,
                'list_item' => [
                    'level' => min(1, max(0, $ilvl)),
                    'text' => $plain,
                    'html' => $inline,
                    'ordered' => false,
                ],
            ];
        }

        if ($this->paragraphLooksLikeManualSectionTitle($plain, $inline)) {
            return [
                'is_outline_title' => false,
                'is_section_title' => true,
                'html' => '<p class="sgd-word-section-title">' . $inline . '</p>',
                'plain' => $plain,
            ];
        }

        $label = $this->advanceAndFormatListLabel($numId, $ilvl);
        [$fullPlain, $fullInline] = $this->prependListLabel($label, $plain, $inline);

        if ($this->paragraphLooksLikeManualSectionTitle($fullPlain, $fullInline)) {
            return [
                'is_outline_title' => false,
                'is_section_title' => true,
                'html' => '<p class="sgd-word-section-title">' . $fullInline . '</p>',
                'plain' => $fullPlain,
            ];
        }

        if ($this->paragraphIsOutlineTitle($fullPlain, $fullInline, $numId, $ilvl)) {
            $block = $this->blockFromNumberedTitle($fullPlain, $fullInline);

            return [
                'is_outline_title' => true,
                'is_section_title' => false,
                'html' => $block['html'],
                'plain' => $block['plain'],
            ];
        }

        return [
            'is_outline_title' => false,
            'is_section_title' => false,
            'list_item' => [
                'level' => min(1, max(0, $ilvl)),
                'text' => $plain,
                'html' => $inline,
                'ordered' => $this->numIdOrdered[$numId] ?? false,
            ],
        ];
    }

    /**
     * @return array{html: string, plain: string, is_heading: bool}
     */
    private function paragraphToHtml(
        DOMXPath $xpath,
        DOMElement $p,
        DocxArchiveReader $archive,
        string $mediaDir,
        array &$stats,
        array &$messages
    ): array {
        $styleNode = $this->xpathFirst($xpath, 'w:pPr/w:pStyle', $p);
        $styleVal = $styleNode instanceof DOMElement ? strtolower($styleNode->getAttributeNS(self::W_NS, 'val')) : '';
        $tag = 'p';
        $isHeading = false;
        if (preg_match('/^heading([1-6])$/', $styleVal, $m)) {
            $level = min(6, max(2, (int)$m[1] + 1));
            $tag = 'h' . $level;
            $isHeading = true;
        } elseif (preg_match('/^t[ií]tulo([1-6])$/u', $styleVal, $m)) {
            $level = min(6, max(2, (int)$m[1] + 1));
            $tag = 'h' . $level;
            $isHeading = true;
        }

        $inline = $this->runsToHtml($xpath, $p, $archive, $mediaDir, $stats, $messages);
        $plain = trim(strip_tags($inline));
        if ($plain === '' && strpos($inline, '<img') === false) {
            return ['html' => '', 'plain' => '', 'is_heading' => false];
        }

        if (!$isHeading) {
            $numberedTag = $this->numberedTitleHeadingTag($plain, $inline);
            if ($numberedTag !== '') {
                return [
                    'html' => '<' . $numberedTag . '>' . $inline . '</' . $numberedTag . '>',
                    'plain' => $plain,
                    'is_heading' => true,
                ];
            }
        }

        if (!$isHeading && $this->paragraphLooksLikeManualSectionTitle($plain, $inline)) {
            return [
                'html' => '<p class="sgd-word-section-title">' . $inline . '</p>',
                'plain' => $plain,
                'is_heading' => true,
            ];
        }

        return [
            'html' => '<' . $tag . '>' . $inline . '</' . $tag . '>',
            'plain' => $plain,
            'is_heading' => $isHeading,
        ];
    }

    /** @var list<string> */
    private const IMPORT_HEADING_SKIP_PATTERNS = [
        '/^manual de\b/u',
        '/^procedimiento\b/u',
        '/^plan de\b/u',
        '/^programa de\b/u',
        '/^(elaborado|revisado|aprobado)\s+por$/u',
        '/^tabla de contenido$/u',
        '/^capitulo\s+[ivxlcdm\d]+$/iu',
        '/^indice$/u',
        '/^laboratorio\b/u',
        '/^sistema de gestion\b/u',
        '/^version\s+\d/u',
        '/^codigo\s+/u',
        '/^pagina\s+\d/u',
    ];

    private function normalizeHeadingMatch(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $norm = mb_strtolower($text, 'UTF-8');
        $norm = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $norm) ?: $norm;
        $norm = preg_replace('/^\d+(?:\.\d+)*[\.\)\-]?\s*/u', '', $norm) ?? $norm;
        $norm = preg_replace('/^(?:seccion|capitulo)\s+\d+(?:\.\d+)*[\.\)\-]?\s*/iu', '', $norm) ?? $norm;
        $norm = preg_replace('/[^a-z0-9\s]/u', '', $norm) ?? $norm;
        $norm = preg_replace('/\s+/u', ' ', $norm) ?? $norm;

        return trim($norm);
    }

    private function shouldSkipImportedHeading(string $plain): bool
    {
        $norm = $this->normalizeHeadingMatch($plain);
        if ($norm === '') {
            return true;
        }
        foreach (self::IMPORT_HEADING_SKIP_PATTERNS as $pattern) {
            if (preg_match($pattern, $norm)) {
                return true;
            }
        }

        return false;
    }

    private function numberedTitleHeadingTag(string $plain, string $inline): string
    {
        if ($plain === '') {
            return '';
        }

        $depth = SgdSeccionService::extractLeadingNumberingDepth($plain);
        if ($depth < 2 || !$this->inlineLooksEmphasized($inline, $plain)) {
            return '';
        }

        return SgdSeccionService::headingTagForNumberingDepth($depth, $this->titulosConfig);
    }

    private function paragraphLooksLikeManualSectionTitle(string $plain, string $inline): bool
    {
        if ($plain === '' || mb_strlen($plain) > 130) {
            return false;
        }
        if ($this->shouldSkipImportedHeading($plain)) {
            return false;
        }
        if ($this->numberedTitleHeadingTag($plain, $inline) !== '') {
            return false;
        }
        if (!preg_match('/<(?:strong|b)\b/i', $inline)) {
            return false;
        }
        if (preg_match('/^(?!\d+(?:\.\d+)*[\.\)\-]?\s*\S).+:\s*\S/u', $plain)) {
            return false;
        }
        if (preg_match('/^\d+(?:\.\d+)+[\.\)\-]?\s*\S/u', $plain)) {
            return false;
        }
        if (preg_match('/^\d+(?:\.\d+)*[\.\)\-]?\s*\S/u', $plain)) {
            return true;
        }
        $withoutInline = trim(preg_replace('/<\/?(?:strong|b|em|i|u)\b[^>]*>/i', '', $inline) ?? $inline);
        if (strip_tags($withoutInline) !== $plain) {
            return false;
        }
        $letters = preg_replace('/[^\p{L}]/u', '', $plain) ?? '';
        if ($letters !== '' && mb_strtoupper($letters, 'UTF-8') === $letters) {
            return true;
        }

        return false;
    }

    private function runsToHtml(
        DOMXPath $xpath,
        DOMElement $container,
        ?DocxArchiveReader $archive,
        string $mediaDir,
        array &$stats,
        array &$messages
    ): string {
        $html = '';
        foreach ($container->childNodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $local = $node->localName ?: $node->nodeName;
            if ($local === 'r') {
                $html .= $this->runToHtml($xpath, $node, $archive, $mediaDir, $stats, $messages);
                continue;
            }
            if ($local === 'hyperlink') {
                $html .= $this->runsToHtml($xpath, $node, $archive, $mediaDir, $stats, $messages);
                continue;
            }
            if ($local === 'drawing' || $local === 'pict') {
                $img = $this->extractImageHtml($xpath, $node, $archive, $mediaDir, $stats, $messages);
                if ($img !== '') {
                    $html .= $img;
                }
            }
        }

        return $html;
    }

    private function runToHtml(
        DOMXPath $xpath,
        DOMElement $run,
        ?DocxArchiveReader $archive,
        string $mediaDir,
        array &$stats,
        array &$messages
    ): string {
        $text = '';
        foreach ($run->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $local = $child->localName ?: $child->nodeName;
            if ($local === 't') {
                $text .= htmlspecialchars($child->textContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            } elseif ($local === 'tab') {
                $text .= "\t";
            } elseif ($local === 'br') {
                $text .= '<br>';
            } elseif ($local === 'drawing' || $local === 'pict') {
                $text .= $this->extractImageHtml($xpath, $child, $archive, $mediaDir, $stats, $messages);
            }
        }
        if ($text === '') {
            return '';
        }

        $rPr = $this->xpathFirst($xpath, 'w:rPr', $run);
        $bold = $rPr && $this->xpathFirst($xpath, 'w:b', $rPr) instanceof DOMElement;
        $italic = $rPr && $this->xpathFirst($xpath, 'w:i', $rPr) instanceof DOMElement;
        $underline = $rPr && $this->xpathFirst($xpath, 'w:u', $rPr) instanceof DOMElement;

        if ($underline) {
            $text = '<u>' . $text . '</u>';
        }
        if ($italic) {
            $text = '<em>' . $text . '</em>';
        }
        if ($bold) {
            $text = '<strong>' . $text . '</strong>';
        }

        return $text;
    }

    private function extractImageHtml(
        DOMXPath $xpath,
        DOMElement $drawing,
        ?DocxArchiveReader $archive,
        string $mediaDir,
        array &$stats,
        array &$messages
    ): string {
        if (!$archive instanceof DocxArchiveReader) {
            return '';
        }
        $blip = $xpath->query('.//a:blip', $drawing)->item(0);
        if (!$blip instanceof DOMElement) {
            return '';
        }
        $embed = $blip->getAttributeNS(self::R_NS, 'embed');
        if ($embed === '' || !isset($this->rels[$embed])) {
            return '';
        }
        $target = $this->rels[$embed];
        $binary = $archive->getFromName($target);
        if ($binary === false || $binary === '') {
            return '';
        }

        $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
        $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
        if (!isset($allowed[$ext])) {
            $messages[] = 'Imagen omitida (formato no admitido): ' . $ext;

            return '';
        }

        $filename = 'word_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $fullPath = $mediaDir . '/' . $filename;
        if (@file_put_contents($fullPath, $binary) === false) {
            return '';
        }

        $stats['images'] += 1;
        $webPath = '/uploads/sgd/' . $this->empresaId . '/' . $this->documentoId . '/media/' . $filename;

        return '<p class="sgd-word-img-wrap"><img src="' . htmlspecialchars($webPath, ENT_QUOTES, 'UTF-8') . '" alt="" class="sgd-word-img"></p>';
    }

    /**
     * @return array{level: int, text: string, ordered: bool}|null
     */
    private function paragraphToListItem(DOMXPath $xpath, DOMElement $p, DOMElement $numPr): ?array
    {
        $ilvlNode = $this->xpathFirst($xpath, 'w:ilvl', $numPr);
        $numIdNode = $this->xpathFirst($xpath, 'w:numId', $numPr);
        $ilvl = $ilvlNode instanceof DOMElement ? (int)$ilvlNode->getAttributeNS(self::W_NS, 'val') : 0;
        $numId = $numIdNode instanceof DOMElement ? (int)$numIdNode->getAttributeNS(self::W_NS, 'val') : 0;
        if ($numId <= 0) {
            return null;
        }

        $plain = trim(preg_replace('/\s+/u', ' ', $p->textContent ?? '') ?? '');
        if ($plain === '') {
            return null;
        }

        return [
            'level' => min(1, max(0, $ilvl)),
            'text' => $plain,
            'ordered' => $this->numIdOrdered[$numId] ?? false,
        ];
    }

    /**
     * @param list<array{level: int, text: string, ordered: bool}> $items
     */
    private function renderListItemHtml(array $item): string
    {
        $html = trim((string)($item['html'] ?? ''));
        if ($html !== '') {
            return $html;
        }

        return htmlspecialchars((string)($item['text'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function renderListBuffer(array $items): string
    {
        if (count($items) < 1) {
            return '';
        }

        $ordered = !empty($items[0]['ordered']);
        $tag = $ordered ? 'ol' : 'ul';
        $html = '<' . $tag . ' class="sgd-word-list">';
        foreach ($items as $item) {
            $html .= '<li>' . $this->renderListItemHtml($item) . '</li>';
        }
        $html .= '</' . $tag . '>';

        return $html;
    }

    private function tableToHtml(DOMXPath $xpath, DOMElement $tbl): string
    {
        $rows = $xpath->query('w:tr', $tbl);
        if ($rows === false || $rows->length === 0) {
            return '';
        }

        $parsed = $this->parseWordTableRows($xpath, $rows);
        if ($parsed === []) {
            return '';
        }

        $html = '<table class="sgd-word-table no-datatable"><tbody>';
        foreach ($parsed as $rowIndex => $rowCells) {
            $html .= '<tr>';
            foreach ($rowCells as $cell) {
                if (($cell['type'] ?? '') !== 'cell') {
                    continue;
                }
                $cellTag = ($rowIndex === 0) ? 'th' : 'td';
                $colspan = max(1, (int)($cell['colspan'] ?? 1));
                $rowspan = max(1, (int)($cell['rowspan'] ?? 1));
                $attrs = '';
                if ($colspan > 1) {
                    $attrs .= ' colspan="' . $colspan . '"';
                }
                if ($rowspan > 1) {
                    $attrs .= ' rowspan="' . $rowspan . '"';
                }
                $inner = (string)($cell['inner'] ?? '');
                $html .= '<' . $cellTag . $attrs . '>' . ($inner !== '' ? $inner : '&nbsp;') . '</' . $cellTag . '>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * @return list<list<array<string, mixed>>>
     */
    private function parseWordTableRows(DOMXPath $xpath, DOMNodeList $rows): array
    {
        $parsed = [];
        foreach ($rows as $tr) {
            if (!$tr instanceof DOMElement) {
                continue;
            }
            $parsedRow = [];
            $col = 0;
            $cells = $xpath->query('w:tc', $tr);
            if ($cells === false) {
                $parsed[] = $parsedRow;
                continue;
            }
            foreach ($cells as $tc) {
                if (!$tc instanceof DOMElement) {
                    continue;
                }
                $colspan = 1;
                $vMergeType = null;
                $tcPr = $this->xpathFirst($xpath, 'w:tcPr', $tc);
                if ($tcPr instanceof DOMElement) {
                    $gridSpan = $this->xpathFirst($xpath, 'w:gridSpan', $tcPr);
                    if ($gridSpan instanceof DOMElement) {
                        $colspan = max(1, (int)$gridSpan->getAttributeNS(self::W_NS, 'val'));
                    }
                    $vMerge = $this->xpathFirst($xpath, 'w:vMerge', $tcPr);
                    if ($vMerge instanceof DOMElement) {
                        $vMergeType = $vMerge->getAttributeNS(self::W_NS, 'val') === 'restart'
                            ? 'restart'
                            : 'continue';
                    }
                }

                if ($vMergeType === 'continue') {
                    $parsedRow[] = [
                        'type' => 'continue',
                        'col' => $col,
                        'colspan' => $colspan,
                    ];
                    $col += $colspan;
                    continue;
                }

                $inner = '';
                foreach ($xpath->query('w:p', $tc) as $p) {
                    if (!$p instanceof DOMElement) {
                        continue;
                    }
                    $part = trim(preg_replace('/\s+/u', ' ', $p->textContent ?? '') ?? '');
                    if ($part !== '') {
                        $inner .= htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '<br>';
                    }
                }
                $inner = rtrim($inner, '<br>');

                $parsedRow[] = [
                    'type' => 'cell',
                    'col' => $col,
                    'colspan' => $colspan,
                    'rowspan' => 1,
                    'vmerge' => $vMergeType,
                    'inner' => $inner,
                ];
                $col += $colspan;
            }
            $parsed[] = $parsedRow;
        }

        foreach ($parsed as $rowIndex => $rowCells) {
            foreach ($rowCells as $cellIndex => $cell) {
                if (($cell['type'] ?? '') !== 'cell' || ($cell['vmerge'] ?? null) !== 'restart') {
                    continue;
                }
                $parsed[$rowIndex][$cellIndex]['rowspan'] = $this->countWordVerticalMergeSpan(
                    $parsed,
                    $rowIndex,
                    (int)$cell['col']
                );
            }
        }

        return $parsed;
    }

    /**
     * @param list<list<array<string, mixed>>> $parsed
     */
    private function countWordVerticalMergeSpan(array $parsed, int $startRow, int $col): int
    {
        $rowspan = 1;
        for ($r = $startRow + 1, $max = count($parsed); $r < $max; $r += 1) {
            $found = false;
            foreach ($parsed[$r] as $item) {
                if (($item['type'] ?? '') === 'continue' && (int)($item['col'] ?? -1) === $col) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                break;
            }
            $rowspan += 1;
        }

        return $rowspan;
    }

    private function xpathFirst(DOMXPath $xpath, string $query, DOMElement $context): ?DOMElement
    {
        $node = $xpath->query($query, $context)->item(0);

        return $node instanceof DOMElement ? $node : null;
    }
}
