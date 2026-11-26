<?php

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Escritura en streaming (OpenSpout) del Excel del reporte SIHOS "Auxiliar",
 * con el mismo formato de imprauxiliar.php de SIHOS:
 *  - Vertical: fila de cuenta (negrita, fondo azul), fila de tercero (documento
 *    y nombre, subtotales en negrita) y el detalle (fecha + descripción en varias líneas).
 *  - Horizontal: fila de cuenta con "--" en las columnas del documento y una fila
 *    por movimiento con código y nombre de la cuenta repetidos.
 * "Solo movimientos" oculta las columnas de saldo. Los valores son números
 * reales con formato $ (se pueden sumar en Excel).
 */
class SihosAuxiliarExcel
{
    private const FILAS_POR_HOJA = 1048570;
    private const FORMATO_VALOR = '"$"#,##0.00;-"$"#,##0.00';
    /** Columnas de datos del documento en horizontal (de "Documento" a "Concepto"). */
    private const COLUMNAS_DOC_HORIZONTAL = 11;

    private Writer $writer;
    private bool $vertical;
    private bool $conSaldos;
    /** @var list<string> */
    private array $encabezado;
    private int $colValores;
    private int $filasHoja = 0;
    private int $hojas = 1;
    /** @var array<string,array<int,Style>> estilos por celda para cada tipo de fila */
    private array $estilos = [];
    /** @var array<string,?Style> estilo de fila para cada tipo */
    private array $estiloFila = [];
    private Style $negrita;
    /** @var (callable(string,list<mixed>):void)|null recibe cada fila de cuenta/detalle (copia para la tabla en pantalla) */
    private $alEscribir;

    /**
     * @param array<string,mixed> $f filtros validados
     * @param list<string> $titulos líneas del encabezado (institución, NIT, título, rango…)
     * @param (callable(string,list<mixed>):void)|null $alEscribir
     */
    public function __construct(string $ruta, array $f, array $titulos, ?callable $alEscribir = null)
    {
        $this->alEscribir = $alEscribir;
        $this->vertical = $f['diseno'] === 'vertical';
        $this->conSaldos = !$f['solo_movimientos'];
        $this->negrita = (new Style())->setFontBold();
        if ($this->vertical) {
            $valores = $this->conSaldos ? ['Saldo Ant.', 'Débito', 'Crédito', 'Nuevo Saldo'] : ['Débito', 'Crédito'];
            $this->encabezado = ['Código', 'Nombre', ...$valores];
            $this->colValores = 2;
        } else {
            $valores = $this->conSaldos ? ['Saldo Anterior', 'Débito', 'Crédito', 'Nuevo Saldo'] : ['Débito', 'Crédito'];
            $this->encabezado = ['Código', 'Nombre', 'Documento', '', 'Fecha', 'Documento Tercero', 'Nombre Tercero', 'Centro de Costo', 'Doc. Ref.', '', 'Doc. Ext.', 'Cheque', 'Concepto', ...$valores];
            $this->colValores = 2 + self::COLUMNAS_DOC_HORIZONTAL;
        }
        $this->prepararEstilos();

        $this->writer = new Writer();
        $op = $this->writer->getOptions();
        $total = count($this->encabezado);
        if ($this->vertical) {
            $op->setColumnWidth(16, 1);
            $op->setColumnWidth(75, 2);
        } else {
            $op->setColumnWidth(12, 1, 3, 4, 5, 9, 10, 12);
            $op->setColumnWidth(40, 2, 7, 13);
            $op->setColumnWidth(18, 6, 8, 11);
            $fila = count($titulos) + 2;
            $op->mergeCells(2, $fila, 3, $fila);
            $op->mergeCells(8, $fila, 9, $fila);
        }
        $op->setColumnWidthForRange(17, $this->colValores + 1, $total);
        $this->writer->openToFile($ruta);
        $this->writer->getCurrentSheet()->setName('Auxiliar');
        foreach ($titulos as $i => $t) {
            $this->writer->addRow(Row::fromValues([$t], $i === 0 ? (new Style())->setFontBold()->setFontSize(13) : $this->negrita));
        }
        $this->writer->addRow(Row::fromValues([]));
        $this->writer->addRow(Row::fromValues($this->encabezado, (new Style())->setFontBold()->setCellAlignment(CellAlignment::CENTER)));
        $this->filasHoja = count($titulos) + 2;
    }

    public function cuenta(string $codigo, string $nombre, float $salAnt, float $db, float $cr, float $salNue): void
    {
        $datos = [$codigo, SihosExcelTexto::limpiar($nombre)];
        if (!$this->vertical) {
            $datos = [...$datos, ...array_fill(0, self::COLUMNAS_DOC_HORIZONTAL, '--')];
        }
        $this->escribir('cuenta', $this->conValores($datos, $salAnt, $db, $cr, $salNue));
    }

    /** Fila de tercero con sus subtotales (solo diseño vertical). */
    public function tercero(string $documento, string $nombre, float $salAnt, float $db, float $cr, float $salNue): void
    {
        $this->escribir('tercero', $this->conValores([$documento, SihosExcelTexto::limpiar($nombre)], $salAnt, $db, $cr, $salNue));
    }

    public function total(float $salAnt, float $db, float $cr, float $salNue): void
    {
        $this->escribir('total', $this->conValores([], $salAnt, $db, $cr, $salNue));
    }

    /**
     * Movimiento. Vertical: [fecha, descripción multilínea]; horizontal: código,
     * nombre y las 11 columnas del documento. Saldo anterior/nuevo van vacíos, como en SIHOS.
     *
     * @param list<mixed> $datos
     */
    public function detalle(array $datos, float $valor, bool $anulado): void
    {
        $db = $valor > 0 ? $valor : 0.0;
        $cr = $valor > 0 ? 0.0 : abs($valor);
        $this->escribir($anulado ? 'anulado' : 'detalle', $this->conValores($datos, null, $db, $cr, null));
    }

    /** @return int hojas usadas */
    public function cerrar(): int
    {
        $this->writer->close();

        return $this->hojas;
    }

    private function prepararEstilos(): void
    {
        $total = $this->vertical ? 2 + ($this->conSaldos ? 4 : 2) : $this->colValores + ($this->conSaldos ? 4 : 2);
        $valor = (new Style())->setFormat(self::FORMATO_VALOR);
        $valorNegrita = (new Style())->setFormat(self::FORMATO_VALOR)->setFontBold();
        $base = [];
        for ($i = $this->colValores; $i < $total; $i++) {
            $base[$i] = $valor;
        }
        $negritaValores = array_map(static fn (): Style => $valorNegrita, $base);
        $fecha = (new Style())->setFormat('dd/mm/yyyy')->setCellAlignment(CellAlignment::RIGHT)->setCellVerticalAlignment(CellVerticalAlignment::TOP);

        $this->estiloFila = ['cuenta' => (new Style())->setFontBold()->setBackgroundColor('9FC7F4'), 'tercero' => null, 'total' => (new Style())->setFontBold()->setBackgroundColor('D9D9D9'), 'detalle' => null, 'anulado' => null];
        $this->estilos['cuenta'] = $negritaValores;
        $this->estilos['tercero'] = $negritaValores;
        $this->estilos['total'] = $negritaValores;
        if ($this->vertical) {
            $descripcion = (new Style())->setShouldWrapText(true)->setCellVerticalAlignment(CellVerticalAlignment::TOP);
            $this->estilos['detalle'] = [0 => $fecha, 1 => $descripcion] + $base;
            $this->estilos['anulado'] = [0 => $fecha, 1 => (clone $descripcion)->setBackgroundColor('F4B6B6')] + $base;
        } else {
            $this->estilos['detalle'] = [0 => $this->negrita, 1 => $this->negrita, 4 => (new Style())->setFormat('dd/mm/yyyy')] + $base;
            $this->estilos['anulado'] = $this->estilos['detalle'];
            $this->estiloFila['anulado'] = (new Style())->setBackgroundColor('F4B6B6');
        }
    }

    /** Completa las columnas de texto y agrega las de valores según el diseño. */
    private function conValores(array $datos, ?float $salAnt, float $db, float $cr, ?float $salNue): array
    {
        $datos = array_pad($datos, $this->colValores, '');
        $valores = $this->conSaldos ? [$salAnt, $db, $cr, $salNue] : [$db, $cr];

        return [...$datos, ...array_map(static fn (?float $v) => $v === null ? '' : round($v, 2), $valores)];
    }

    private function escribir(string $tipo, array $valores): void
    {
        if ($this->filasHoja >= self::FILAS_POR_HOJA) {
            $this->hojas++;
            $this->writer->addNewSheetAndMakeItCurrent()->setName('Auxiliar ' . $this->hojas);
            $this->writer->addRow(Row::fromValues($this->encabezado, $this->negrita));
            $this->filasHoja = 1;
        }
        $this->writer->addRow(Row::fromValuesWithStyles($valores, $this->estiloFila[$tipo], $this->estilos[$tipo]));
        if ($this->alEscribir !== null && $tipo !== 'total' && $tipo !== 'tercero') {
            ($this->alEscribir)($tipo, $valores);
        }
        $this->filasHoja++;
    }
}
