<?php

/**
 * Inspecciona archivos Word (.docx) para detectar restricción de edición (sin recuperar contraseñas).
 */
class SgdWordInspectionService
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * @return array{
     *   allowed: bool,
     *   message?: string,
     *   warning?: string
     * }
     */
    public function validateUpload(string $tmpPath, string $originalName, bool $proteccionObligatoria): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, ['docx', 'doc'], true)) {
            return ['allowed' => true];
        }

        if ($ext === 'doc') {
            if ($proteccionObligatoria) {
                return [
                    'allowed' => false,
                    'message' => 'No es posible verificar la protección contra escritura en archivos Word .doc. '
                        . 'Convierta el documento a .docx y aplique en Word: Revisar → Restringir edición.',
                ];
            }

            return [
                'allowed' => true,
                'warning' => 'Advertencia: el documento Word (.doc) que está cargando no permite verificar '
                    . 'si tiene protección contra escritura.',
            ];
        }

        $inspection = $this->inspectDocx($tmpPath);

        if ($inspection['encrypted']) {
            $base = 'El documento Word está cifrado con contraseña para abrirlo; no se puede comprobar la restricción de edición.';
            if ($proteccionObligatoria) {
                return [
                    'allowed' => false,
                    'message' => $base . ' Quite la contraseña de apertura o use un archivo sin cifrar de apertura.',
                ];
            }

            return [
                'allowed' => true,
                'warning' => 'Advertencia: ' . lcfirst($base) . ' Se aceptó la subida sin verificar protección.',
            ];
        }

        if (!$inspection['inspectable']) {
            if ($proteccionObligatoria) {
                return [
                    'allowed' => false,
                    'message' => 'No se pudo leer el archivo Word para verificar la protección contra escritura.',
                ];
            }

            return [
                'allowed' => true,
                'warning' => 'Advertencia: no se pudo verificar la protección del documento Word que está cargando.',
            ];
        }

        if ($inspection['write_protected']) {
            return ['allowed' => true];
        }

        if ($proteccionObligatoria) {
            return [
                'allowed' => false,
                'message' => 'Es necesario proteger el documento Word contra escritura antes de subirlo. '
                    . 'En Word: Revisar → Restringir edición → permita solo el tipo de edición deseado y establezca una contraseña.',
            ];
        }

        return [
            'allowed' => true,
            'warning' => 'Advertencia: el documento Word que está cargando no tiene ninguna protección contra escritura.',
        ];
    }

    /**
     * @return array{
     *   inspectable: bool,
     *   encrypted: bool,
     *   write_protected: bool
     * }
     */
    public function inspectDocx(string $path): array
    {
        $reader = DocxArchiveReader::open($path);
        if ($reader === null) {
            return [
                'inspectable' => false,
                'encrypted' => $this->looksEncryptedOffice($path),
                'write_protected' => false,
            ];
        }

        try {
            if ($this->isEncryptedPackage($reader)) {
                return [
                    'inspectable' => false,
                    'encrypted' => true,
                    'write_protected' => false,
                ];
            }

            $settings = $reader->getFromName('word/settings.xml');
            if ($settings === false || $settings === '') {
                return [
                    'inspectable' => true,
                    'encrypted' => false,
                    'write_protected' => false,
                ];
            }

            return [
                'inspectable' => true,
                'encrypted' => false,
                'write_protected' => $this->parseWriteProtectionFromSettings($settings),
            ];
        } finally {
            $reader->close();
        }
    }

    private function isEncryptedPackage(DocxArchiveReader $reader): bool
    {
        if ($reader->getFromName('EncryptedPackage') !== false) {
            return true;
        }

        $contentTypes = $reader->getFromName('[Content_Types].xml');
        if ($contentTypes !== false && is_string($contentTypes)) {
            return str_contains($contentTypes, 'encrypted-package');
        }

        return false;
    }

    private function parseWriteProtectionFromSettings(string $xml): bool
    {
        $dom = new DOMDocument();
        if (!@$dom->loadXML($xml)) {
            return false;
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::W_NS);
        $nodes = $xpath->query('//w:documentProtection');
        if ($nodes === false) {
            return false;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $enforcement = strtolower(trim($node->getAttributeNS(self::W_NS, 'enforcement')));
            if (in_array($enforcement, ['1', 'true', 'on'], true)) {
                return true;
            }
        }

        return false;
    }

    private function looksEncryptedOffice(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $header = fread($handle, 8);
        fclose($handle);

        if ($header === false || strlen($header) < 4) {
            return false;
        }

        // OLE compound document (Word .doc o OOXML cifrado empaquetado).
        return str_starts_with($header, "\xD0\xCF\x11\xE0");
    }
}
