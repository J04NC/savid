<?php

/**
 * Filas de detalle del reporte SIHOS "Auxiliar", con el mismo contenido y
 * textos que imprauxiliar.php de SIHOS: en vertical, fecha + descripción en
 * varias líneas (documento, "C.Costo:", "Doc.Ref:", "Chequ No:", "Doc.Ext.:",
 * "Concepto:" y, en documentos de nómina, "Empleado:"); en horizontal, cada
 * dato en su columna. A diferencia de SIHOS, concepto y nombre del tercero van
 * completos (pedido del usuario); la pantalla los corta con "…". Los nombres
 * llegan precargados en $ctx (sin consultas por fila).
 */
class SihosAuxiliarDetalle
{
    /** MaesDocu.DocuApli de documentos de nómina: SIHOS muestra el empleado en el detalle. */
    private const DOCU_APLI_NOMINA = [41, 42, 43, 44, 45, 46, 72, 86, 87, 94];

    /** Nombre del tercero vigente en $fecha: el anterior al primer cambio posterior (HistNoTe) o el actual (CodiTerc), como HistNomb de SIHOS. */
    public static function nombreTercero(array $terceros, string $tiDo, string $nuDo, string $fecha): string
    {
        $k = $tiDo . "\t" . $nuDo;
        foreach ($terceros['historial'][$k] ?? [] as [$fechCamb, $nombre]) {
            if ($fecha < $fechCamb) {
                return $nombre;
            }
        }

        return $terceros['actual'][$k] ?? '';
    }

    /** @return list<mixed> [fecha, descripción en varias líneas] */
    public static function vertical(array $d, array $ctx): array
    {
        $doc = $ctx['documentos'];
        $codiDocu = trim((string)$d['CodiDocu']);
        $lineas = [($doc[$codiDocu]['nombre'] ?? $codiDocu) . ' No. ' . $d['NumeDocu']];
        $cc = trim((string)$d['CentCost']);
        if ($cc !== '') {
            $lineas[] = 'C.Costo:' . $cc . ' - ' . ($ctx['centros'][$cc] ?? '');
        }
        $refe = trim((string)$d['TiDoRefe']);
        if (($doc[$refe]['nombre'] ?? '') !== '') {
            $lineas[] = 'Doc.Ref: ' . $doc[$refe]['nombre'] . ' No. ' . $d['NuDoRefe'];
        }
        if (trim((string)$d['NumeCheq']) !== '') {
            $lineas[] = 'Chequ No: ' . trim((string)$d['NumeCheq']);
        }
        $exte = trim((string)$d['TiDoExte']);
        if (($doc[$exte]['nombre'] ?? '') !== '') {
            $lineas[] = 'Doc.Ext.: ' . $doc[$exte]['nombre'] . ' No. ' . trim((string)$d['NumeExte']);
        }
        $lineas[] = 'Concepto: ' . SihosExcelTexto::limpiar((string)$d['Concepto']);
        $tipo = trim((string)$d['TipoDocu']);
        $nume = trim((string)$d['NumePers']);
        if ($tipo !== '' && $nume !== '' && in_array($doc[$codiDocu]['docuApli'] ?? 0, self::DOCU_APLI_NOMINA, true)) {
            $lineas[] = 'Empleado:' . $tipo . '-' . $nume . ' ' . ($ctx['empleados'][$tipo . "\t" . $nume] ?? '');
        }

        return [self::fecha($d['FechDocu']), SihosExcelTexto::recortar(implode("\n", array_map([SihosExcelTexto::class, 'limpiar'], $lineas)))];
    }

    /** @return list<mixed> código, nombre de la cuenta y las 11 columnas del documento (diseño horizontal) */
    public static function horizontal(array $d, string $cuenta, string $nombreCuenta, string $tiDo, string $nuDo, array $ctx): array
    {
        $cc = trim((string)$d['CentCost']);
        $exte = trim((string)$d['TiDoExte']);
        $numeExte = trim((string)$d['NumeExte']);
        $fecha = (string)$d['FechDocu'];
        $nombreTercero = $nuDo !== '' ? SihosExcelTexto::limpiar(self::nombreTercero($ctx['terceros'], $tiDo, $nuDo, $fecha)) : '';

        return [
            $cuenta, SihosExcelTexto::limpiar($nombreCuenta), trim((string)$d['CodiDocu']), (int)$d['NumeDocu'], self::fecha($fecha),
            $nuDo !== '' ? $tiDo . ' - ' . $nuDo : '', $nombreTercero,
            $cc !== '' ? $cc . ' - ' . SihosExcelTexto::limpiar($ctx['centros'][$cc] ?? '') : '',
            trim((string)$d['TiDoRefe']), $d['NuDoRefe'] !== null && (string)$d['NuDoRefe'] !== '' ? (int)$d['NuDoRefe'] : '',
            $exte !== '' || $numeExte !== '' ? $exte . ' - ' . $numeExte : '', SihosExcelTexto::limpiar((string)$d['NumeCheq']),
            SihosExcelTexto::limpiar((string)$d['Concepto']),
        ];
    }

    private static function fecha(mixed $f): DateTimeImmutable|string
    {
        $d = $f !== null ? DateTimeImmutable::createFromFormat('!Y-m-d', (string)$f) : false;

        return $d ?: '';
    }
}
