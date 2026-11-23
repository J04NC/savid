<?php

/**
 * Cruce (puro, sin BD) entre ítems de orden médica y líneas liquidadas de las
 * mismas admisiones, para el reporte SIHOS "Órdenes vs liquidación".
 *
 * Enlace directo: la línea apunta a la orden (DetaFact.TipoOrde='DetaOrde' +
 * ConsAdmi + NumeOrde + Item) o la orden apunta a la línea (DetaOrde.CodiDocu +
 * NumeLiqu + ConsDeFa). Inferido: sin enlace, misma admisión y mismo CUPS con
 * una línea todavía no usada. CantReal NO decide "atendido" porque puede
 * quedar en 0 si el servicio no se carga desde la liquidación: solo se
 * informa; un valor mayor a 0 es evidencia de atención, un 0 no prueba lo contrario.
 */
class SihosOrdenLiquidacionCruce
{
    public const LIQ_DIRECTO = 'Liquidado (enlace directo)';
    public const LIQ_INFERIDO = 'Liquidado (inferido por admisión y CUPS)';
    public const SIN_LIQ_EVIDENCIA = 'No liquidado, con evidencia de atención';
    public const SIN_LIQ = 'No liquidado, sin evidencia de atención';
    public const SUSPENDIDO = 'Suspendido';
    public const SIN_ORDEN = 'Liquidado sin orden';

    public const CRITERIO_ENLACE = 'Enlace de SIHOS';
    public const CRITERIO_CODIGO = 'Misma admisión y mismo código';
    public const CRITERIO_CUPS = 'Misma admisión y mismo CUPS (código distinto)';

    public const ESTADOS = [
        self::LIQ_DIRECTO, self::LIQ_INFERIDO, self::SIN_LIQ_EVIDENCIA, self::SIN_LIQ, self::SUSPENDIDO, self::SIN_ORDEN,
    ];

    /**
     * @param list<array<string,mixed>> $ordenes ítems de orden (con 'Cups': CodiCups del procedimiento, o su código si no tiene)
     * @param list<array<string,mixed>> $lineas líneas liquidadas de las mismas admisiones
     * @return array{ordenes:array<int,array{estado:string,linea:?int,criterio:string}>,lineasSinOrden:list<int>} por índice de cada lista de entrada
     */
    public static function cruzar(array $ordenes, array $lineas): array
    {
        $porOrden = [];
        $porLiquidacion = [];
        $porAdmision = [];
        $porCups = [];
        foreach ($lineas as $i => $l) {
            if ((string)$l['TipoOrde'] === 'DetaOrde') {
                $porOrden[$l['ConsAdmi'] . '|' . $l['NumeOrde'] . '|' . $l['Item']][] = $i;
            }
            $porLiquidacion[trim((string)$l['CodiDocu']) . '|' . $l['NumeFact'] . '|' . $l['ConsDeFa']] = $i;
            $porAdmision[$l['ConsAdmi']][$l['CodiProc']][] = $i;
            $porCups[$l['ConsAdmi']][$l['Cups']][] = $i;
        }

        $usadas = [];
        $res = [];
        $pendientes = [];
        foreach ($ordenes as $i => $o) {
            $candidatas = $porOrden[$o['ConsAdmi'] . '|' . $o['ConsOrde'] . '|' . $o['Item']] ?? [];
            if ((int)$o['ConsDeFa'] > 0) {
                $propia = $porLiquidacion[trim((string)$o['CodiDocu']) . '|' . $o['NumeLiqu'] . '|' . $o['ConsDeFa']] ?? null;
                if ($propia !== null) {
                    $candidatas[] = $propia;
                }
            }
            $linea = null;
            foreach ($candidatas as $c) {
                if (!isset($usadas[$c])) {
                    $linea = $c;
                    break;
                }
            }
            if ($linea !== null) {
                $usadas[$linea] = true;
                $res[$i] = ['estado' => self::LIQ_DIRECTO, 'linea' => $linea, 'criterio' => self::CRITERIO_ENLACE];
            } else {
                $pendientes[] = $i;
            }
        }

        // Mismo código primero; luego mismo CUPS con código distinto (p. ej. un paquete PMS... cuyo CodiCups es el de la liquidación).
        foreach ($pendientes as $i) {
            $o = $ordenes[$i];
            $linea = null;
            $criterio = self::CRITERIO_CODIGO;
            foreach ($porAdmision[$o['ConsAdmi']][$o['CodiProc']] ?? [] as $c) {
                if (!isset($usadas[$c])) {
                    $linea = $c;
                    break;
                }
            }
            if ($linea === null) {
                $criterio = self::CRITERIO_CUPS;
                foreach ($porCups[$o['ConsAdmi']][$o['Cups']] ?? [] as $c) {
                    if (!isset($usadas[$c])) {
                        $linea = $c;
                        break;
                    }
                }
            }
            if ($linea !== null) {
                $usadas[$linea] = true;
                $res[$i] = ['estado' => self::LIQ_INFERIDO, 'linea' => $linea, 'criterio' => $criterio];
            } else {
                $res[$i] = ['estado' => self::estadoSinLiquidar($o), 'linea' => null, 'criterio' => ''];
            }
        }

        $sinOrden = [];
        foreach (array_keys($lineas) as $i) {
            if (!isset($usadas[$i])) {
                $sinOrden[] = $i;
            }
        }

        return ['ordenes' => $res, 'lineasSinOrden' => $sinOrden];
    }

    /** @param array<string,mixed> $o */
    private static function estadoSinLiquidar(array $o): string
    {
        if (self::esFecha($o['FechSusp'] ?? null)) {
            return self::SUSPENDIDO;
        }

        return (int)$o['CantReal'] > 0 ? self::SIN_LIQ_EVIDENCIA : self::SIN_LIQ;
    }

    /** SIHOS guarda fechas vacías como NULL o '0000-00-00'. */
    public static function esFecha(mixed $v): bool
    {
        return is_string($v) && $v !== '' && $v >= '1900-01-01';
    }
}
