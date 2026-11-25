<?php

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Escritura en streaming (OpenSpout) del Excel del reporte SIHOS "Auxiliar",
 * con los dos diseños de SIHOS:
 *  - Vertical: fila de cuenta (fondo azul), fila de tercero con subtotales y el
 *    detalle de cada movimiento (fecha + descripción en varias líneas).
 *  - Horizontal: fila de cuenta y una fila por movimiento con todo en columnas.
 * "Solo movimientos" oculta las columnas de saldo. Los valores son números
 * reales (no texto con puntos/comas), así que se pueden sumar en Excel.
 */
class SihosAuxiliarExcel
{
    private const FILAS_POR_HOJA = 1048570;
    private const FORMATO_VALOR = '#,##0.00;-#,##0.00';

    private Writer $writer;
    private bool $vertical;
    private bool $conSaldos;
    /** @var list<string> */
    private array $encabezado;
    /** @var array<int,Style> */
    private array $estilosColumna;
    private Style $estiloCuenta;
    private Style $estiloTercero;
    private Style $estiloTotal;
    private Style $estiloAnulado;
    private int $filasHoja = 0;
    private int $hojas = 1;
    /** Índice (base 0) de la primera columna de valores. */
    private int $colValores;

    /** @param array<string,mixed> $f filtros validados */
    public function __construct(string $ruta, array $f, string $titulo, string $subtitulo)
    {
        $this->vertical = $f['diseno'] === 'vertical';
        $this->conSaldos = !$f['solo_movimientos'];
        $valores = $this->conSaldos ? ['Saldo anterior', 'Débito', 'Crédito', 'Nuevo saldo'] : ['Débito', 'Crédito'];
        $this->encabezado = $this->vertical
            ? ['Código', 'Nombre', ...$valores]
            : ['Código', 'Nombre', 'Documento', 'Número', 'Fecha', 'Documento tercero', 'Nombre tercero', 'Centro de costo', 'Doc. ref.', 'Núm. ref.', 'Doc. ext.', 'Cheque', 'Concepto', ...$valores];
        $this->colValores = $this->vertical ? 2 : 13;

        $numero = (new Style())->setFormat(self::FORMATO_VALOR);
        $fecha = (new Style())->setFormat('dd/mm/yyyy');
        $this->estilosColumna = [];
        for ($i = $this->colValores; $i < count($this->encabezado); $i++) {
            $this->estilosColumna[$i] = $numero;
        }
        if ($this->vertical) {
            $this->estilosColumna[0] = (new Style())->setFormat('dd/mm/yyyy')->setCellVerticalAlignment(CellVerticalAlignment::TOP);
            $this->estilosColumna[1] = (new Style())->setShouldWrapText(true)->setCellVerticalAlignment(CellVerticalAlignment::TOP);
        } else {
            $this->estilosColumna[4] = $fecha;
        }
        $this->estiloCuenta = (new Style())->setFontBold()->setBackgroundColor('9FC7F4');
        $this->estiloTercero = (new Style())->setFontBold();
        $this->estiloTotal = (new Style())->setFontBold()->setBackgroundColor('D9D9D9');
        $this->estiloAnulado = (new Style())->setBackgroundColor('F4B6B6');

        $this->writer = new Writer();
        $op = $this->writer->getOptions();
        if ($this->vertical) {
            $op->setColumnWidth(14, 1);
            $op->setColumnWidth(70, 2);
            $op->setColumnWidthForRange(18, 3, count($this->encabezado));
        } else {
            $op->setColumnWidth(12, 1, 3, 4, 5, 9, 10, 12);
            $op->setColumnWidth(40, 2, 7, 13);
            $op->setColumnWidth(16, 6, 8, 11);
            $op->setColumnWidthForRange(18, 14, count($this->encabezado));
        }
        $this->writer->openToFile($ruta);
        $this->writer->getCurrentSheet()->setName('Auxiliar');
        $this->writer->addRow(Row::fromValues([$titulo], (new Style())->setFontBold()->setFontSize(14)));
        $this->writer->addRow(Row::fromValues([$subtitulo]));
        $this->writer->addRow(Row::fromValues([]));
        $this->writer->addRow(Row::fromValues($this->encabezado, (new Style())->setFontBold()));
        $this->filasHoja = 4;
    }

    /** Fila de cuenta (también la de "total" de cada cuenta en diseño horizontal). */
    public function cuenta(string $codigo, string $nombre, float $salAnt, float $db, float $cr, float $salNue): void
    {
        $this->fila([$codigo, SihosExcelTexto::limpiar($nombre)], $salAnt, $db, $cr, $salNue, $this->estiloCuenta);
    }

    /** Fila de tercero con sus subtotales (solo diseño vertical). */
    public function tercero(string $documento, string $nombre, float $salAnt, float $db, float $cr, float $salNue): void
    {
        $this->fila([$documento, SihosExcelTexto::limpiar($nombre)], $salAnt, $db, $cr, $salNue, $this->estiloTercero);
    }

    public function total(float $salAnt, float $db, float $cr, float $salNue): void
    {
        $this->fila(['', 'TOTAL'], $salAnt, $db, $cr, $salNue, $this->estiloTotal);
    }

    /**
     * Movimiento. Vertical: [fecha, descripción multilínea]; horizontal: las 13 columnas de datos.
     *
     * @param list<mixed> $datos
     */
    public function detalle(array $datos, float $valor, bool $anulado): void
    {
        $db = $valor > 0 ? $valor : 0.0;
        $cr = $valor > 0 ? 0.0 : abs($valor);
        $estilos = $this->estilosColumna;
        if ($anulado) {
            $estilos[1] = $this->vertical ? (clone $estilos[1])->setBackgroundColor('F4B6B6') : $this->estiloAnulado;
        }
        $this->escribir($this->conValores($datos, null, $db, $cr, null), null, $estilos);
    }

    /** @return int hojas usadas */
    public function cerrar(): int
    {
        $this->writer->close();

        return $this->hojas;
    }

    private function fila(array $datos, float $salAnt, float $db, float $cr, float $salNue, Style $estilo): void
    {
        $this->escribir($this->conValores($datos, $salAnt, $db, $cr, $salNue), $estilo, $this->estilosColumna);
    }

    /** Completa las columnas de texto y agrega las de valores según el diseño. */
    private function conValores(array $datos, ?float $salAnt, float $db, float $cr, ?float $salNue): array
    {
        $datos = array_pad($datos, $this->colValores, '');
        $valores = $this->conSaldos ? [$salAnt ?? '', round($db, 2), round($cr, 2), $salNue ?? ''] : [round($db, 2), round($cr, 2)];

        return [...$datos, ...array_map(static fn ($v) => is_float($v) ? round($v, 2) : $v, $valores)];
    }

    private function escribir(array $valores, ?Style $estiloFila, array $estilos): void
    {
        if ($this->filasHoja >= self::FILAS_POR_HOJA) {
            $this->hojas++;
            $this->writer->addNewSheetAndMakeItCurrent()->setName('Auxiliar ' . $this->hojas);
            $this->writer->addRow(Row::fromValues($this->encabezado, (new Style())->setFontBold()));
            $this->filasHoja = 1;
        }
        $this->writer->addRow(Row::fromValuesWithStyles($valores, $estiloFila, $estilos));
        $this->filasHoja++;
    }
}
