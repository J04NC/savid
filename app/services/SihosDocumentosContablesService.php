<?php

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Reporte SIHOS "Documentos contables": todas las líneas contables causadas y
 * no anuladas del rango, sumadas por documento y cuenta, de la contabilidad
 * elegida (ColGAAP = DetaCont, NIIF = DetaNIIF), con filtro opcional de cuenta.
 *
 * Reproduce la consulta que el área contable ya entregaba (mismas columnas y
 * agrupación), con tres ajustes: filtra por el CodiInst de la empresa, agrupa
 * también por año (CodiDocu+NumeDocu se repite entre años: miles de casos
 * reales) y no pierde documentos cuyo usuario ya no existe en Usuarios.
 *
 * Se escribe un .xlsx en streaming (OpenSpout, memoria constante): el CSV se
 * rompía con los saltos de línea de Concepto. Solo lectura contra SIHOS.
 */
class SihosDocumentosContablesService
{
    public const CONTABILIDADES = ['colgaap' => 'ColGAAP (DetaCont)', 'niif' => 'NIIF (DetaNIIF)'];
    public const MODOS_CUENTA = ['todas' => 'Todas las cuentas', 'inicia' => 'Inicia por', 'igual' => 'Igual a', 'entre' => 'Entre'];
    public const COLUMNAS = ['Fecha', 'Código', 'Documento', 'Número', 'Concepto', 'Cuenta', 'Valor', 'Usuario'];

    /** Filas de datos por hoja: Excel admite 1.048.576 contando el encabezado. */
    private const FILAS_POR_HOJA = 1048575;
    /** Límite de caracteres de una celda de Excel. */
    private const MAX_CELDA = 32767;

    private SihosEmpresaConfigRepository $configRepository;

    public function __construct()
    {
        $this->configRepository = new SihosEmpresaConfigRepository();
    }

    /**
     * Normaliza y valida los filtros del formulario.
     *
     * @param array<string,mixed> $entrada
     * @return array{ok:bool,error?:string,filtros?:array<string,string>}
     */
    public static function validarFiltros(array $entrada): array
    {
        $f = [
            'contabilidad' => trim((string)($entrada['contabilidad'] ?? '')),
            'desde' => trim((string)($entrada['desde'] ?? '')),
            'hasta' => trim((string)($entrada['hasta'] ?? '')),
            'modo_cuenta' => trim((string)($entrada['modo_cuenta'] ?? 'todas')),
            'cuenta' => trim((string)($entrada['cuenta'] ?? '')),
            'cuenta_hasta' => trim((string)($entrada['cuenta_hasta'] ?? '')),
        ];
        if (!isset(self::CONTABILIDADES[$f['contabilidad']])) {
            return ['ok' => false, 'error' => 'Elija la contabilidad (ColGAAP o NIIF).'];
        }
        $error = SihosLiquidacionService::validarRango($f['desde'], $f['hasta']);
        if ($error !== null) {
            return ['ok' => false, 'error' => $error];
        }
        if (!isset(self::MODOS_CUENTA[$f['modo_cuenta']])) {
            return ['ok' => false, 'error' => 'Filtro de cuenta no válido.'];
        }
        $esCuenta = static fn (string $c): bool => preg_match('/^\d{1,16}$/', $c) === 1;
        if ($f['modo_cuenta'] === 'todas') {
            $f['cuenta'] = $f['cuenta_hasta'] = '';
        } elseif (!$esCuenta($f['cuenta'])) {
            return ['ok' => false, 'error' => 'La cuenta debe tener solo dígitos (máximo 16).'];
        }
        if ($f['modo_cuenta'] === 'entre') {
            if (!$esCuenta($f['cuenta_hasta'])) {
                return ['ok' => false, 'error' => 'La cuenta final debe tener solo dígitos (máximo 16).'];
            }
            if (strcmp($f['cuenta'], $f['cuenta_hasta']) > 0) {
                return ['ok' => false, 'error' => 'La cuenta inicial no puede ser mayor que la final.'];
            }
        } else {
            $f['cuenta_hasta'] = '';
        }

        return ['ok' => true, 'filtros' => $f];
    }

    /** Texto corto del filtro de cuenta, para la pantalla y el nombre del archivo. */
    public static function describirCuenta(array $filtros): string
    {
        return match ($filtros['modo_cuenta'] ?? 'todas') {
            'inicia' => 'cuentas que inician por ' . $filtros['cuenta'],
            'igual' => 'cuenta ' . $filtros['cuenta'],
            'entre' => 'cuentas entre ' . $filtros['cuenta'] . ' y ' . $filtros['cuenta_hasta'],
            default => 'todas las cuentas',
        };
    }

    /**
     * Genera el .xlsx en $rutaXlsx.
     *
     * @param array<string,string> $filtros salida de validarFiltros()
     * @param callable(int):void|null $progreso recibe las filas escritas
     * @return array{ok:bool,error?:string,lineas?:int,hojas?:int}
     */
    public function generar(int $empresaId, array $filtros, string $rutaXlsx, ?callable $progreso = null): array
    {
        $validacion = self::validarFiltros($filtros);
        if (!$validacion['ok']) {
            return ['ok' => false, 'error' => $validacion['error']];
        }
        $filtros = $validacion['filtros'];

        $cfg = $this->configRepository->findByEmpresaId($empresaId);
        $codiInst = trim((string)($cfg['codi_inst'] ?? ''));
        if ($cfg === null || $codiInst === '' || $cfg['host'] === '' || $cfg['base_datos'] === '' || $cfg['usuario'] === '') {
            return ['ok' => false, 'error' => 'Esta empresa no tiene conexión a SIHOS configurada (o falta el CodiInst).'];
        }

        $repo = new SihosDocumentosContablesRepository(SihosRepositoryFactory::buildRead($cfg, $codiInst)->connect(), $codiInst);
        $documentos = $repo->fetchNombresDocumento();
        $usuarios = $repo->fetchNombresUsuario();

        $cuenta = match ($filtros['modo_cuenta']) {
            'inicia', 'igual' => ['modo' => $filtros['modo_cuenta'], 'valor' => $filtros['cuenta']],
            'entre' => ['modo' => 'entre', 'desde' => $filtros['cuenta'], 'hasta' => $filtros['cuenta_hasta']],
            default => null,
        };
        $hastaExclusiva = (new DateTimeImmutable($filtros['hasta']))->modify('+1 day')->format('Y-m-d');

        $estiloEncabezado = (new Style())->setFontBold();
        $estilos = [
            0 => (new Style())->setFormat('dd/mm/yyyy'),
            6 => (new Style())->setFormat('#,##0.00;-#,##0.00'),
        ];

        $writer = new Writer();
        $writer->getOptions()->setColumnWidth(11, 1, 2, 4, 6);
        $writer->getOptions()->setColumnWidth(30, 3, 8);
        $writer->getOptions()->setColumnWidth(60, 5);
        $writer->getOptions()->setColumnWidth(16, 7);
        $writer->openToFile($rutaXlsx);
        $hojas = 1;
        $enHoja = 0;
        $escritas = 0;
        $writer->getCurrentSheet()->setName('Documentos');
        $writer->addRow(Row::fromValues(self::COLUMNAS, $estiloEncabezado));

        try {
            $lineas = $repo->recorrerLineas(
                $filtros['contabilidad'], $filtros['desde'], $hastaExclusiva, $cuenta,
                function (array $f) use ($writer, $documentos, $usuarios, $estilos, $estiloEncabezado, $progreso, &$hojas, &$enHoja, &$escritas): void {
                    if ($enHoja >= self::FILAS_POR_HOJA) {
                        $hojas++;
                        $enHoja = 0;
                        $writer->addNewSheetAndMakeItCurrent()->setName('Documentos ' . $hojas);
                        $writer->addRow(Row::fromValues(self::COLUMNAS, $estiloEncabezado));
                    }
                    $codiDocu = trim((string)$f['CodiDocu']);
                    $login = trim((string)$f['UsuaCaus']);
                    $fecha = $f['FechDocu'] !== null ? DateTimeImmutable::createFromFormat('!Y-m-d', (string)$f['FechDocu']) : false;
                    $writer->addRow(Row::fromValuesWithStyles([
                        $fecha ?: '',
                        $codiDocu,
                        self::texto($documentos[$codiDocu] ?? ''),
                        (int)$f['NumeDocu'],
                        self::texto((string)$f['Concepto']),
                        (string)$f['Cuenta'],
                        (float)$f['Valor'],
                        self::texto($usuarios[$login] ?? $login),
                    ], null, $estilos));
                    $enHoja++;
                    $escritas++;
                    if ($progreso !== null) {
                        $progreso($escritas);
                    }
                }
            );
        } finally {
            $writer->close();
        }

        return ['ok' => true, 'lineas' => $lineas, 'hojas' => $hojas];
    }

    /**
     * Texto apto para una celda: UTF-8 válido, sin saltos de línea ni
     * caracteres de control (rompían el CSV y XML no los admite), en una sola
     * línea y dentro del límite de Excel.
     */
    private static function texto(string $s): string
    {
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        }
        $s = trim(preg_replace('/[\x00-\x20\x7F]+/u', ' ', $s) ?? '');

        return mb_strlen($s) > self::MAX_CELDA ? mb_substr($s, 0, self::MAX_CELDA) : $s;
    }
}
