<?php

/**
 * Construye el código legible del listado maestro a partir de proceso, tipo, línea, padre y consecutivo.
 */
class SgdDocumentoCodigoService
{
    /** @var array<int, string> */
    private array $cache = [];

    public function clearCache(): void
    {
        $this->cache = [];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>>|null $allById
     */
    public function buildForRow(array $row, ?array $allById = null): string
    {
        $id = (int)($row['id'] ?? 0);
        if ($id > 0 && isset($this->cache[$id])) {
            return $this->cache[$id];
        }

        $padreId = isset($row['documento_id']) && $row['documento_id'] !== '' && $row['documento_id'] !== null
            ? (int)$row['documento_id']
            : 0;

        $padreCodigo = '';
        if ($padreId > 0 && $allById !== null && isset($allById[$padreId])) {
            $padreCodigo = $this->buildForRow($allById[$padreId], $allById);
        }

        $proceso = strtoupper(trim((string)($row['proceso_codigo'] ?? '')));
        $tipo = strtoupper(trim((string)($row['tipo_codigo'] ?? '')));
        $linea = strtoupper(trim((string)($row['linea_codigo'] ?? '')));
        $n = trim((string)($row['consecutivo'] ?? ''));

        if ($padreCodigo !== '') {
            $code = $padreCodigo . '-' . $this->buildChildSuffix($tipo, $n);
        } else {
            $code = $this->buildRootCode($proceso, $tipo, $linea, $n);
        }

        if ($id > 0) {
            $this->cache[$id] = $code;
        }

        return $code;
    }

    /**
     * Vista previa en formulario (sin id persistido).
     *
     * @param array<string, mixed> $input
     * @param array<int, array<string, mixed>> $padresById
     */
    public function buildPreview(array $input, array $padresById): string
    {
        $padreId = (int)($input['documento_id'] ?? 0);
        $row = [
            'id' => 0,
            'documento_id' => $padreId > 0 ? $padreId : null,
            'proceso_codigo' => $input['proceso_codigo'] ?? '',
            'tipo_codigo' => $input['tipo_codigo'] ?? '',
            'linea_codigo' => $input['linea_codigo'] ?? '',
            'consecutivo' => $input['consecutivo'] ?? '',
        ];

        if ($padreId > 0 && isset($padresById[$padreId])) {
            $allById = $padresById;
            $row['proceso_codigo'] = $row['proceso_codigo'] ?: ($padresById[$padreId]['proceso_codigo'] ?? '');
        } else {
            $allById = null;
        }

        return $this->buildForRow($row, $allById);
    }

    private function buildChildSuffix(string $tipo, string $consecutivo): string
    {
        $consecutivo = trim($consecutivo);
        if ($consecutivo === '') {
            return $tipo;
        }

        if (preg_match('/^[A-Z]+\d+$/i', $consecutivo)) {
            return strtoupper($consecutivo);
        }

        if (ctype_digit($consecutivo)) {
            return $tipo . $consecutivo;
        }

        return strtoupper($consecutivo);
    }

    /**
     * Documento raíz (sin padre): proceso-tipo[+línea]-consecutivo.
     * La línea documental es opcional y aplica a cualquier tipo (catálogo por empresa).
     */
    private function buildRootCode(string $proceso, string $tipo, string $linea, string $consecutivo): string
    {
        if ($proceso === '' || $tipo === '' || $consecutivo === '') {
            return '';
        }

        $base = $proceso . '-' . $tipo;
        if ($linea !== '') {
            return $base . $linea . '-' . $consecutivo;
        }

        return $base . $consecutivo;
    }
}
