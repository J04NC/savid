<?php

/**
 * Código de carpeta archivística: dependencia.serie[.subserie]
 */
class SgdCcdCodigoService
{
    public function buildCarpetaCodigo(?string $depCodigo, ?string $serieCodigo, ?string $subserieCodigo): string
    {
        $dep = trim((string)$depCodigo);
        if ($dep === '') {
            return '';
        }

        $parts = [$dep];
        $serie = trim((string)$serieCodigo);
        if ($serie !== '') {
            $parts[] = $serie;
            $sub = trim((string)$subserieCodigo);
            if ($sub !== '') {
                $parts[] = $sub;
            }
        }

        return implode('.', $parts);
    }

    /**
     * @param array<string, mixed> $row
     */
    public function buildForEntradaRow(array $row): string
    {
        return $this->buildCarpetaCodigo(
            $row['dependencia_codigo'] ?? null,
            $row['serie_codigo'] ?? null,
            $row['subserie_codigo'] ?? null
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public function buildUbicacionLabel(array $row): string
    {
        $sub = trim((string)($row['subserie_nombre'] ?? ''));
        if ($sub !== '') {
            return $sub;
        }

        $serie = trim((string)($row['serie_nombre'] ?? ''));
        if ($serie !== '') {
            return $serie;
        }

        return trim((string)($row['dependencia_nombre'] ?? ''));
    }
}
