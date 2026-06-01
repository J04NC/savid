<?php

/**
 * Lectura de hojas Excel vía script Python (xlrd / zip+xml).
 */
class SgdSpreadsheetReader
{
    /**
     * @return array{ok: bool, rows?: array<string, array<string, string>>, error?: string}
     */
    public static function read(string $absolutePath, ?string $sheetName = null): array
    {
        if (!is_readable($absolutePath)) {
            return ['ok' => false, 'error' => 'Archivo no legible'];
        }

        $script = BASE_PATH . '/scripts/sgd_read_sheet.py';
        if (!is_readable($script)) {
            return ['ok' => false, 'error' => 'Script de lectura no disponible'];
        }

        $cmd = sprintf(
            'python3 %s %s%s 2>&1',
            escapeshellarg($script),
            escapeshellarg($absolutePath),
            $sheetName !== null && $sheetName !== '' ? ' ' . escapeshellarg($sheetName) : ''
        );

        $output = shell_exec($cmd);
        if ($output === null || trim($output) === '') {
            return ['ok' => false, 'error' => 'No se pudo ejecutar el lector de Excel'];
        }

        $decoded = json_decode(trim($output), true);
        if (!is_array($decoded) || empty($decoded['ok'])) {
            return [
                'ok' => false,
                'error' => is_array($decoded) ? (string)($decoded['error'] ?? 'Error al parsear Excel') : 'Salida inválida del lector',
            ];
        }

        return [
            'ok' => true,
            'rows' => is_array($decoded['rows'] ?? null) ? $decoded['rows'] : [],
        ];
    }
}
