<?php

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Reporte SIHOS "Nómina — Aportes en línea (PILA)": una fila por empleado
 * (más una por cada novedad — vacaciones, incapacidad, licencia no
 * remunerada, licencia de maternidad — del período) con los datos y valores
 * de cotización de pensión, salud, ARL y parafiscales, en el mismo orden de
 * columnas que la plantilla de cargue de "aportes en línea" (hoja
 * "Liquidaciones", columnas B a CM). Encabezados agrupados replicados de esa
 * plantilla; el resto de la hoja (columnas ESAP/MEN/Exonerado/Cotizante UPC
 * Adicional, y el bloque "Datos Generales" de cabecera con banco/aportante/
 * administradora ARL) no lo produce la consulta base y queda fuera de este
 * reporte — ver docblock de SihosExternalRepository::fetchNominaPila().
 */
class SihosNominaPilaService
{
    /**
     * Encabezados de columna B..CM de la plantilla, en el mismo orden que
     * devuelve SihosExternalRepository::fetchNominaPila() — se usan para la
     * hoja exportada y para la grilla de vista previa (app/views/sihos/nomina-pila.php),
     * nunca para leer la fila (esa viene ya asociativa del repositorio).
     */
    public const ENCABEZADOS = [
        'Tipo ID', 'No ID', 'Primer Apellido', 'Segundo Apellido', 'Primer Nombre', 'Segundo Nombre',
        'Departamento', 'Ciudad', 'Tipo de Cotizante', 'Subtipo de Cotizante', 'Horas Laboradas',
        'Extranjero', 'Residente en el Exterior', 'Fecha Radicación en el Exterior', 'ING', 'Fecha ING',
        'RET', 'Fecha RET', 'TDE', 'TAE', 'TDP', 'TAP', 'VSP', 'Fecha VSP', 'VST', 'SLN', 'Inicio SLN',
        'Fin  SLN', 'IGE', 'Inicio IGE', 'Fin IGE', 'LMA', 'Inicio LMA', 'Fin LMA', 'VAC-LR',
        'Inicio VAC-LR', 'Fin VAC-LR', 'AVP', 'VCT', 'Inicio VCT', 'Fin VCT', 'IRL', 'Inicio IRL',
        'Fin IRL', 'Correcciones', 'Salario Mensual($)', 'Salario Integral', 'Salario Variable',
        'Administradora', 'Días', 'IBC', 'Tarifa', 'Valor Cotización', 'Indicador Alto Riesgo',
        'Cotización Voluntaria Afiliado', 'Cotización Voluntaria Empleador', 'Fondo Solidaridad Pensional',
        'Fondo Subsistencia', 'Valor no Retenido', 'Total', 'AFP Destino', 'Administradora', 'Días', 'IBC',
        'Tarifa', 'Valor Cotización', 'Valor UPC', 'N° Autorización Incapacidad EG',
        'Valor Incapacidad EG', 'N° Autorización LMA', 'Valor Licencia Maternidad', 'EPS Destino',
        'Administradora', 'Días', 'IBC', 'Tarifa', 'Clase', 'Centro de Trabajo', 'Actividad Económica',
        'Valor Cotización', 'Días', 'Administradora CCF', 'IBC CCF', 'Tarifa CCF', 'Valor Cotización CCF',
        'IBC Otros Parafiscales', 'Tarifa SENA', 'Valor Cotización SENA', 'Tarifa ICBF',
        'Valor Cotización ICBF',
    ];

    /** Columna (índice 0-based sobre A..CM, "No." incluido) donde empieza cada grupo de la fila 17 de la plantilla. */
    private const GRUPOS = [
        0 => 'Empleado',
        15 => 'Novedades',
        46 => 'Salario',
        49 => 'Pensión',
        62 => 'Salud',
        73 => 'Riesgos',
        81 => 'Parafiscales',
    ];

    /**
     * Columna de nombre (viene de SIHOS, texto libre) => columna de NIT
     * correspondiente (agregada por SihosExternalRepository::fetchNominaPila()
     * solo para este cruce). Los 4 códigos de columna coinciden a propósito
     * con `tipo_tercero.codigo` (AFP/EPS/ARL/CCF).
     */
    private const COLUMNAS_NIT_ADMINISTRADORA = [
        'AFP' => 'AFP_NIT',
        'EPS' => 'EPS_NIT',
        'ARL' => 'ARL_NIT',
        'CCF' => 'CCF_NIT',
    ];

    private SihosEmpresaConfigRepository $configRepository;
    private PDO $pdo;

    public function __construct()
    {
        $this->configRepository = new SihosEmpresaConfigRepository();
        $this->pdo = (new Database())->connect();
    }

    /**
     * @return array{ok:bool,error?:string,filas?:list<array<string,mixed>>}
     */
    public function buildFilas(int $empresaId, string $codiAno, string $codiMes): array
    {
        if (!preg_match('/^\d{4}$/', $codiAno) || !preg_match('/^\d{1,2}$/', $codiMes)) {
            return ['ok' => false, 'error' => 'Año o mes de período inválido.'];
        }

        $configFila = $this->configRepository->findByEmpresaId($empresaId);
        if ($configFila === null || $configFila['host'] === '' || $configFila['base_datos'] === '' || $configFila['usuario'] === '') {
            return ['ok' => false, 'error' => 'Esta empresa no tiene conexión a SIHOS configurada.'];
        }

        $codiInst = trim((string)($configFila['codi_inst'] ?? ''));
        if ($codiInst === '') {
            return ['ok' => false, 'error' => 'Falta configurar el CodiInst de esta empresa en Conexión SIHOS.'];
        }

        $repository = new SihosExternalRepository([
            'host' => $configFila['host'],
            'port' => (string)$configFila['puerto'],
            'database' => $configFila['base_datos'],
            'username' => $configFila['usuario'],
            'codiInst' => $codiInst,
            'password' => SihosCredentialCipher::decrypt($configFila['password_cifrado'] ?? null),
            'charset' => $configFila['charset'],
        ]);

        $municipio = $this->configRepository->municipioEmpresa($empresaId);
        $salarioMinimoMensual = $this->configRepository->salarioMinimoMensual($empresaId, (int)$codiAno);

        try {
            $filas = $repository->fetchNominaPila($codiAno, $codiMes, $municipio, $salarioMinimoMensual);
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => 'No se pudo consultar SIHOS: ' . $e->getMessage()];
        }

        $filas = $this->fusionarNormalConUnicaNovedad($filas);
        $filas = $this->aplicarNombresPila($filas, $empresaId);

        return ['ok' => true, 'filas' => $filas];
    }

    /**
     * Tarifas fijas por tipo de novedad (AFP/EPS, y si aplica CCF/SENA/ICBF)
     * — las MISMAS que ya usa cada bloque de `fetchNominaPila()` para esa
     * novedad, replicadas aquí solo para volver a calcular la cotización del
     * total fusionado (ver fusionarNormalConUnicaNovedad()). `redondeoAfpEps`
     * es el número de decimales de `round()` que ya usa esa fila para
     * COTIZACION AFP/EPS (0 en vacaciones — igual que el bloque de
     * vacaciones real/sintética de fetchNominaPila — , -2 en incapacidad/SLN/
     * LMA), para no introducir una convención de redondeo nueva.
     *
     * `tieneCcf=false` (incapacidad, SLN) significa que esa novedad nunca
     * aporta su propio valor de CCF/SENA/ICBF (los 3 quedan en blanco en su
     * bloque de `fetchNominaPila()`) — al fusionar, esas 5 columnas
     * simplemente se copian tal cual de la fila normal, sin recalcular nada
     * (0 + normal = normal).
     */
    private const TARIFAS_NOVEDAD_FUSION = [
        'VAC' => ['afp' => 16, 'eps' => 12.5, 'redondeoAfpEps' => 0, 'tieneCcf' => true],
        'INC' => ['afp' => 16, 'eps' => 12.5, 'redondeoAfpEps' => -2, 'tieneCcf' => false],
        'SLN' => ['afp' => 12, 'eps' => 8.5, 'redondeoAfpEps' => -2, 'tieneCcf' => false],
        'LMA' => ['afp' => 16, 'eps' => 12.5, 'redondeoAfpEps' => -2, 'tieneCcf' => true],
    ];

    /**
     * Cuando la única actividad de un empleado en el período es UNA sola
     * novedad (vacaciones, incapacidad —cualquiera de sus 4 variantes—,
     * licencia no remunerada o licencia de maternidad) y por eso la fila
     * "normal" (días laborados) de `fetchNominaPila()` queda en 0 días, esa
     * fila normal deja de tener sentido como fila aparte: sigue cargando
     * dinero real (p. ej. de retroactivos que no son de esa novedad —
     * RETROACTIVO SUELDO, RECARGOS, HORAS EXTRAS) pero con 0 días, lo que
     * confunde más de lo que aclara. A pedido explícito del usuario: en ese
     * caso se fusiona TODO en la única fila de novedad y la fila normal (0
     * días) se elimina — dinero conservado, nunca se pierde ni se duplica.
     *
     * Se detecta por CONTEO de filas, no repitiendo la lógica de
     * `fetchNominaPila()`: si un empleado tiene EXACTAMENTE 2 filas ese
     * período y una de ellas es la normal (sin ninguna marca de
     * VAC/IGE/SLN/LMA) con `D_AFP` en 0, la otra es por definición su única
     * novedad — no hace falta volver a consultar SIHOS ni contar conceptos.
     * Si hay 3+ filas (dos o más novedades a la vez, o novedad + días sí
     * trabajados) no se toca nada — el pedido del usuario fue explícito en
     * que la fusión es solo para "un solo concepto".
     *
     * La BASE (I.B.C. PENSION/EPS, y CCF/Otros Parafiscales cuando la
     * novedad los tiene) se SUMA entre las dos filas — son el mismo tipo de
     * valor, sumar es correcto. La COTIZACIÓN correspondiente se
     * RECALCULA sobre esa base ya sumada, con la tarifa fija de la novedad
     * (`TARIFAS_NOVEDAD_FUSION`) — nunca se suman las dos cotizaciones
     * directamente, porque cada fila pudo haber usado una tarifa distinta
     * (p. ej. normal con AFP de alto riesgo al 26%, la novedad siempre a
     * tarifa plana) y sumarlas produciría un número sin sentido.
     *
     * `I.B.C. ARL` SÍ se iguala al mismo total fusionado (`$baseAfpEps`) —
     * a pedido explícito del usuario, mismo criterio que la fila de
     * vacaciones real fusionada con retroactivo: las 5 columnas de IBC de
     * una misma fila deben mostrar el mismo valor. `COTIZACION ARL` NUNCA
     * se recalcula ni se traslada — la fila de novedad ya trae su propio
     * criterio para esa columna (vacía en incapacidad/SLN/vacaciones/
     * licencia de maternidad). `Total_AFP` tampoco se traslada de la fila
     * normal: se iguala a la propia `COTIZACION AFP` recién recalculada —
     * no al total real sin ajustar que muestra la fila normal, que es un
     * valor distinto con otro propósito (ver docblock de
     * `fetchNominaPila()`). Si la fila normal traía `FSolidaridad` (Fondo de
     * Solidaridad Pensional, solo salarios altos) se preserva sumándolo,
     * por si el caso llega a darse.
     *
     * @param list<array<string,mixed>> $filas
     * @return list<array<string,mixed>>
     */
    private function fusionarNormalConUnicaNovedad(array $filas): array
    {
        $porEmpleado = [];
        foreach ($filas as $indice => $fila) {
            $clave = ($fila['TipoDocu'] ?? '') . '|' . ($fila['NumePers'] ?? '');
            $porEmpleado[$clave][] = $indice;
        }

        $indicesAEliminar = [];

        foreach ($porEmpleado as $indices) {
            if (count($indices) !== 2) {
                continue;
            }

            [$i1, $i2] = $indices;
            $tipo1 = $this->tipoNovedadFila($filas[$i1]);
            $tipo2 = $this->tipoNovedadFila($filas[$i2]);

            if ($tipo1 === null && $tipo2 !== null) {
                $indiceNormal = $i1;
                $indiceNovedad = $i2;
                $tipoNovedad = $tipo2;
            } elseif ($tipo2 === null && $tipo1 !== null) {
                $indiceNormal = $i2;
                $indiceNovedad = $i1;
                $tipoNovedad = $tipo1;
            } else {
                // Las dos son normales (no debería pasar, fetchNominaPila()
                // nunca duplica la fila normal) o las dos son de novedad
                // (dos novedades distintas el mismo período) — no fusiona.
                continue;
            }

            $normal = $filas[$indiceNormal];
            if (abs((float)($normal['D_AFP'] ?? 0)) >= 0.01) {
                continue;
            }

            $tarifas = self::TARIFAS_NOVEDAD_FUSION[$tipoNovedad];
            $novedad = $filas[$indiceNovedad];

            $baseAfpEps = round((float)($normal['I.B.C. PENSION'] ?? 0) + (float)($novedad['I.B.C. PENSION'] ?? 0), 2);
            $novedad['I.B.C. PENSION'] = $baseAfpEps;
            $novedad['I.B.C. EPS'] = $baseAfpEps;
            $novedad['COTIZACION AFP'] = round($baseAfpEps * $tarifas['afp'] / 100, $tarifas['redondeoAfpEps']);
            $novedad['COTIZACION EPS'] = round($baseAfpEps * $tarifas['eps'] / 100, $tarifas['redondeoAfpEps']);
            // I.B.C. ARL se iguala al mismo total (nunca se recalcula
            // COTIZACION ARL) — mismo criterio ya aplicado a la fila de
            // vacaciones real fusionada con retroactivo: las 5 columnas de
            // IBC de una misma fila deben mostrar el mismo valor, la
            // cotización de riesgos es la única que puede quedar vacía.
            $novedad['I.B.C. ARL'] = $baseAfpEps;

            $fsolidaridadNormal = is_numeric($normal['FSolidaridad'] ?? null) ? (float)$normal['FSolidaridad'] : 0.0;
            $fsolidaridadNovedad = is_numeric($novedad['FSolidaridad'] ?? null) ? (float)$novedad['FSolidaridad'] : 0.0;
            $fsolidaridadTotal = $fsolidaridadNormal + $fsolidaridadNovedad;
            $novedad['FSolidaridad'] = $fsolidaridadTotal > 0 ? $fsolidaridadTotal : $novedad['FSolidaridad'];
            $novedad['Total_AFP'] = $novedad['COTIZACION AFP'] + $fsolidaridadTotal;

            if ($tarifas['tieneCcf']) {
                $baseCcf = round((float)($normal['I.B.C. CCF'] ?? 0) + (float)($novedad['I.B.C. CCF'] ?? 0), 2);
                $novedad['I.B.C. CCF'] = $baseCcf;
                $novedad['IBC Otros Parafiscales'] = $baseCcf;
                $novedad['COTIZACION CCF'] = round($baseCcf * 4 / 100, -2);
                $novedad['COTIZACION SENA'] = round($baseCcf * 2 / 100, -2);
                $novedad['COTIZACION ICBF'] = round($baseCcf * 3 / 100, -2);
            } else {
                $novedad['I.B.C. CCF'] = $normal['I.B.C. CCF'] ?? '';
                $novedad['IBC Otros Parafiscales'] = $normal['IBC Otros Parafiscales'] ?? '';
                $novedad['COTIZACION CCF'] = $normal['COTIZACION CCF'] ?? '';
                $novedad['COTIZACION SENA'] = $normal['COTIZACION SENA'] ?? '';
                $novedad['COTIZACION ICBF'] = $normal['COTIZACION ICBF'] ?? '';
            }

            $filas[$indiceNovedad] = $novedad;
            $indicesAEliminar[$indiceNormal] = true;
        }

        if ($indicesAEliminar === []) {
            return $filas;
        }

        $resultado = [];
        foreach ($filas as $indice => $fila) {
            if (!isset($indicesAEliminar[$indice])) {
                $resultado[] = $fila;
            }
        }

        return $resultado;
    }

    /**
     * 'VAC'/'INC'/'SLN'/'LMA' según las columnas de novedad de la fila, o
     * `null` si es la fila "normal" (sin ninguna novedad activa) — mismas 4
     * columnas que ya distinguen los bloques de `fetchNominaPila()`.
     */
    private function tipoNovedadFila(array $fila): ?string
    {
        if (($fila['VAC'] ?? 'NO') === 'VACACIONES') {
            return 'VAC';
        }
        if (($fila['IGE'] ?? 'NO') === 'SI') {
            return 'INC';
        }
        if (($fila['SLN'] ?? 'NO') !== 'NO') {
            return 'SLN';
        }
        if (($fila['LMA'] ?? 'NO') === 'SI') {
            return 'LMA';
        }

        return null;
    }

    /**
     * Reemplaza el nombre crudo de SIHOS (columnas AFP/EPS/ARL/CCF, tomado de
     * `CodiTerc.NombTerc`) por `tercero_nomina.nombre_pila` — el nombre EXACTO
     * que exige el operador de aportes en línea — cuando esa administradora ya
     * está registrada para esta empresa en Terceros Nómina.
     *
     * Cruce PRIMARIO por NIT: `SihosExternalRepository::fetchNominaPila()`
     * agrega las columnas `AFP_NIT`/`EPS_NIT`/`ARL_NIT`/`CCF_NIT`
     * (`CodiTerc.NumeTerc`, con guión y DV) solo para este cruce — se
     * comparan contra `terceroidentificacion.numero` (sin guión ni DV, así se
     * guarda en SAVID) filtrando por `tipo_tercero`, más confiable que
     * comparar texto libre. Esas 4 columnas se descartan de cada fila antes
     * de retornar: nunca deben llegar a la grilla ni al Excel.
     *
     * Cruce de RESPALDO por nombre normalizado (trim + espacios colapsados +
     * mayúsculas) cuando el NIT no tiene match — por ejemplo, si
     * `tercero_nomina` aún no se ha vuelto a importar con la columna de NIT.
     * `scripts/import_tercero_nomina_sihos.php` guardó `tercero.razon_social`
     * tal cual vino de `CodiTerc.NombTerc` (la misma columna que este reporte
     * lee para AFP/EPS/ARL/CCF), así que ambos lados parten del mismo texto.
     *
     * Si ninguno de los dos cruces encuentra nada, se deja el nombre crudo de
     * SIHOS tal cual — nunca se rompe el reporte por un dato faltante, solo
     * no se corrige.
     *
     * @param list<array<string,mixed>> $filas
     * @return list<array<string,mixed>>
     */
    private function aplicarNombresPila(array $filas, int $empresaId): array
    {
        if ($filas === []) {
            return $filas;
        }

        [$mapaPorNit, $mapaPorNombre] = $this->cargarNombresPila($empresaId);

        foreach ($filas as &$fila) {
            foreach (self::COLUMNAS_NIT_ADMINISTRADORA as $columnaNombre => $columnaNit) {
                $nombrePila = null;

                $nitLimpio = self::limpiarNit((string)($fila[$columnaNit] ?? ''));
                if ($nitLimpio !== null) {
                    $nombrePila = $mapaPorNit[$columnaNombre][$nitLimpio] ?? null;
                }

                if ($nombrePila === null) {
                    $valorActual = trim((string)($fila[$columnaNombre] ?? ''));
                    if ($valorActual !== '') {
                        $nombrePila = $mapaPorNombre[$columnaNombre][self::normalizarNombre($valorActual)] ?? null;
                    }
                }

                if ($nombrePila !== null) {
                    $fila[$columnaNombre] = $nombrePila;
                }

                unset($fila[$columnaNit]);
            }
        }
        unset($fila);

        return $filas;
    }

    /**
     * @return array{0: array<string, array<string, string>>, 1: array<string, array<string, string>>}
     *     [tipo_tercero.codigo => (NIT sin guión/DV => nombre_pila), tipo_tercero.codigo => (nombre normalizado => nombre_pila)]
     */
    private function cargarNombresPila(int $empresaId): array
    {
        $tipos = array_keys(self::COLUMNAS_NIT_ADMINISTRADORA);
        $placeholders = implode(',', array_fill(0, count($tipos), '?'));

        $stmt = $this->pdo->prepare(
            "SELECT tt.codigo AS tipo, ti.numero, t.razon_social, tn.nombre_pila
             FROM tercero_nomina tn
             INNER JOIN terceroidentificacion ti ON ti.id = tn.terceroidentificacion_id
             INNER JOIN tercero t ON t.id = ti.tercero_id
             INNER JOIN tipo_tercero tt ON tt.id = tn.tipo_tercero_id
             WHERE tn.empresa_id = ?
               AND tn.deleted_at IS NULL
               AND tn.nombre_pila IS NOT NULL AND tn.nombre_pila <> ''
               AND tt.codigo IN ({$placeholders})"
        );
        $stmt->execute([$empresaId, ...$tipos]);

        $porNit = [];
        $porNombre = [];
        while ($fila = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tipo = (string)$fila['tipo'];
            $nombrePila = (string)$fila['nombre_pila'];
            $porNit[$tipo][trim((string)$fila['numero'])] = $nombrePila;
            $porNombre[$tipo][self::normalizarNombre((string)$fila['razon_social'])] = $nombrePila;
        }

        return [$porNit, $porNombre];
    }

    private static function normalizarNombre(string $nombre): string
    {
        $nombre = trim($nombre);
        $nombre = preg_replace('/\s+/', ' ', $nombre) ?? $nombre;

        return function_exists('mb_strtoupper') ? mb_strtoupper($nombre) : strtoupper($nombre);
    }

    /** "900336004-7" => "900336004"; "900336004" => "900336004"; formato irreconocible => null. */
    private static function limpiarNit(string $nit): ?string
    {
        $nit = trim($nit);
        if ($nit === '' || !preg_match('/^(\d+)(-\d)?$/', $nit, $m)) {
            return null;
        }

        return $m[1];
    }

    /**
     * Ruta de la plantilla oficial "aportes en línea" tal cual la descarga el
     * operador (el archivo que subió el usuario, sin ninguna modificación).
     *
     * Historia: se probaron dos enfoques anteriores basados en PhpSpreadsheet
     * (cargar con IOFactory::load(), modificar el modelo de objetos, volver a
     * guardar con el Writer) y AMBOS fueron rechazados por el portal con
     * "Las firmas de los archivos excel no coinciden con los valores
     * predeterminados": primero quitando la hoja oculta `DatosPruebaEmp` y
     * sus 71 nombres definidos/53 validaciones (creyendo que no hacían
     * falta), y luego preservándolos pero aun así reescritos por
     * PhpSpreadsheet (incluso reinyectando a mano las partes `customXml` que
     * esa librería no soporta). Subir la plantilla original intacta al
     * portal SÍ pasa la validación — confirmado explícitamente por el
     * usuario — así que el problema real es que PhpSpreadsheet, al volver a
     * serializar el archivo completo, produce un ZIP/XML distinto en algún
     * aspecto que ese validador exige exacto, y no fue posible identificar
     * cuál con comparación de texto (se revisó workbook.xml, docProps,
     * customXml, sheetProtection, autoFilter, validaciones — todo
     * funcionalmente equivalente pero con metadatos de "quién guardó el
     * archivo" distintos: fileVersion/rupBuild, sheetId, mc:Ignorable/extLst,
     * comillas en referencias de hoja, etc.).
     *
     * Por eso este servicio YA NO usa PhpSpreadsheet para generar el archivo
     * final: copia este archivo BYTE POR BYTE y edita como texto plano,
     * dentro del ZIP, únicamente las celdas de datos de la hoja Liquidaciones
     * (ver exportarXlsx() y los métodos privados de más abajo) — todo lo
     * demás del paquete (workbook.xml, docProps, customXml, drawings,
     * styles.xml, hoja oculta DatosPruebaEmp, nombres definidos,
     * validaciones) queda exactamente igual al archivo que el portal ya
     * acepta.
     */
    private const RUTA_PLANTILLA = BASE_PATH . '/storage/plantillas/PlantillaXLSXjul2026.xlsx';

    /** Ruta dentro del .xlsx (ZIP) de la hoja "Liquidaciones" — confirmada leyendo xl/workbook.xml de la plantilla. */
    private const RUTA_HOJA_LIQUIDACIONES = 'xl/worksheets/sheet2.xml';

    private const FILA_DATOS_GENERALES = 10;
    private const FILA_MOLDE = 19;

    /**
     * La plantilla oficial trae 23 filas de EJEMPLO (19..41) con datos de
     * muestra del operador — el estilo por columna se toma combinando esas
     * filas (ver extraerEstilosPorColumna()). Si el reporte tiene menos de
     * 23 filas, el bloque de filas de ejemplo se ELIMINA por completo más
     * allá de la última fila real (no se dejan en blanco): el portal
     * "Aportes en línea" procesa cualquier `<row>` presente como línea de
     * empleado y rechaza las que tengan Tipo ID/No ID vacíos ("El archivo
     * presenta inconsistencias en los campos tipo ID y No ID Línea :N") —
     * confirmado por el usuario. Si el reporte tiene más de 23 filas, se
     * agregan filas nuevas después de la 41 con el mismo estilo por columna.
     */
    private const ULTIMA_FILA_EJEMPLO = 41;

    /**
     * Valores fijos de las columnas CN/CP (ESAP/MEN) que trae la plantilla
     * real para cada fila de empleado: este reporte no calcula esos valores,
     * así que se replica el default (0%) en cada fila.
     *
     * `CS` (Tipo ID de "Cotizante de UPC Adicional") NO va aquí: las filas de
     * ejemplo de la plantilla traían "CC" ahí, pero es un dato de esas filas
     * de ejemplo puntuales, no un default real — el portal "Aportes en
     * línea" lo rechaza para cotizantes normales con el error 151 ("Tipo de
     * identificación de cotizante principal debe ser vacío"), confirmado
     * contra el reporte de validación real. Al no listar `CS`/`CO`/`CQ`/`CR`/
     * `CT` aquí, quedan vacíos — que es lo correcto salvo el caso especial de
     * UPC adicional, que este reporte no calcula.
     */
    private const VALORES_FIJOS_CN_CT = [
        'CN' => 0,      // Tarifa ESAP
        'CP' => 0,      // Tarifa MEN
    ];

    /**
     * Índice (0-based sobre ENCABEZADOS/`$valores`) de la "Tarifa" del grupo
     * Riesgos (columna Excel BY): la única de las 6 columnas "Tarifa"
     * (índices 51 Pensión, 64 Salud, 75 Riesgos, 83 CCF, 86 SENA, 88 ICBF)
     * que `SihosExternalRepository::fetchNominaPila()` devuelve como NÚMERO
     * puro en puntos porcentuales (`ROUND(arp.PorcClAr,3)`, p. ej. 2.436 =
     * 2.436%) en vez de texto con "%" (p. ej. '12.50%', como las otras 5).
     * Se maneja aparte del caso genérico de normalizarValorTarifa() porque
     * no tiene el sufijo "%" que permite detectarla por patrón.
     */
    private const INDICE_TARIFA_ARL = 75;

    /**
     * Convierte a fracción numérica (0.16, no "16%") cualquier valor de
     * Tarifa que llegue como texto con "%" desde SIHOS (Pensión, Salud,
     * CCF, SENA, ICBF: literales SQL como '16%', '12.50%', '4.00%'). El
     * campo "Tarifa de aportes" del formato PILA plano es numérico de ancho
     * fijo (7 caracteres) — el portal rechazaba el archivo con "Valor fuera
     * de formato" (código 111) porque el conversor Excel→txt del operador
     * escribía el texto "16%" tal cual, quedando "16%0000" en ese campo.
     * La Tarifa ARL (índice INDICE_TARIFA_ARL) no pasa por aquí porque ya
     * llega como número puro sin "%" — se maneja aparte, arriba.
     */
    private function normalizarValorTarifa(mixed $valor): mixed
    {
        if (is_string($valor) && preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*%\s*$/', $valor, $m)) {
            return ((float)$m[1]) / 100;
        }

        return $valor;
    }

    /**
     * Construir este .xlsx es liviano (manipulación de texto sobre un XML de
     * ~150 KB, no un modelo de objetos completo), pero el colchón de
     * memory_limit/max_execution_time se conserva por si un reporte
     * excepcionalmente grande empuja el uso de memoria del proceso PHP en
     * general — mismo patrón que SihosCruceReconocimientoService: solo SUBE
     * el límite, nunca lo baja.
     */
    private const MEMORY_LIMIT_MB = 512;

    /**
     * Genera el .xlsx de la nómina PILA a partir de una copia byte-por-byte
     * de la plantilla oficial (ver docblock de RUTA_PLANTILLA), editando
     * como texto plano únicamente las celdas de datos dentro de
     * xl/worksheets/sheet2.xml. El resto del paquete queda intacto.
     *
     * `$sucursalCodigo` (celda G10, "Datos Generales de la Liquidación") es
     * texto libre que el usuario escribe en el formulario de descarga —
     * viaja por GET desde la vista hasta acá (ver SihosController::nominaPilaExportar())
     * en vez de quedar fijo en la plantilla. Si llega vacío, la celda G10 NO
     * se toca (queda tal cual la trae la plantilla) — no se borra un valor
     * por accidente solo porque el usuario descargó sin llenar el campo.
     */
    public function exportarXlsx(array $filas, string $codiAno, string $codiMes, string $destino, string $sucursalCodigo = ''): void
    {
        if ((self::ENCABEZADOS[self::INDICE_TARIFA_ARL] ?? null) !== 'Tarifa') {
            throw new \RuntimeException('SihosNominaPilaService::INDICE_TARIFA_ARL desincronizado con ENCABEZADOS.');
        }

        $limitePrevio = ini_get('memory_limit');
        $bytesPrevios = $this->aBytes($limitePrevio);
        $bytesDeseados = self::MEMORY_LIMIT_MB * 1024 * 1024;

        if ($bytesPrevios !== -1 && $bytesPrevios < $bytesDeseados) {
            ini_set('memory_limit', self::MEMORY_LIMIT_MB . 'M');
        }

        if ((int)ini_get('max_execution_time') !== 0) {
            set_time_limit(0);
        }

        $rutaTemporal = tempnam(sys_get_temp_dir(), 'sihos_pila_');
        if ($rutaTemporal === false) {
            throw new \RuntimeException('No se pudo crear el archivo temporal para exportar la nómina PILA.');
        }

        try {
            if (!copy(self::RUTA_PLANTILLA, $rutaTemporal)) {
                throw new \RuntimeException('No se pudo copiar la plantilla base para exportar la nómina PILA.');
            }

            $zip = new \ZipArchive();
            if ($zip->open($rutaTemporal) !== true) {
                throw new \RuntimeException('No se pudo abrir el archivo temporal de la nómina PILA.');
            }

            $sheetXml = $zip->getFromName(self::RUTA_HOJA_LIQUIDACIONES);
            if ($sheetXml === false) {
                $zip->close();
                throw new \RuntimeException('La plantilla base no tiene la hoja Liquidaciones esperada.');
            }

            $sheetXml = $this->reemplazarFilaDatosGenerales($sheetXml, $filas, $codiAno, $codiMes, $sucursalCodigo);
            $sheetXml = $this->reemplazarFilasDeDatos($sheetXml, $filas);

            $zip->addFromString(self::RUTA_HOJA_LIQUIDACIONES, $sheetXml);
            $zip->close();

            if ($destino === 'php://output') {
                readfile($rutaTemporal);
            } elseif (!copy($rutaTemporal, $destino)) {
                throw new \RuntimeException('No se pudo escribir el archivo final en ' . $destino);
            }
        } finally {
            @unlink($rutaTemporal);

            if ($bytesPrevios === -1 || memory_get_usage(true) < $bytesPrevios) {
                ini_set('memory_limit', $limitePrevio);
            }
        }
    }

    /** "128M" / "1G" / "-1" → bytes. -1 = sin límite. */
    private function aBytes(string $valor): int
    {
        $valor = trim($valor);
        if ($valor === '-1') {
            return -1;
        }

        $unidad = strtolower(substr($valor, -1));
        $numero = (int)$valor;

        return match ($unidad) {
            'g' => $numero * 1024 * 1024 * 1024,
            'm' => $numero * 1024 * 1024,
            'k' => $numero * 1024,
            default => (int)$valor,
        };
    }

    /**
     * Fila 10 de la plantilla ("Datos Generales de la Liquidación"): período
     * de pensión (actual) y salud (siguiente), tipo de planilla, tipo de
     * aportante, la ARL de la empresa (nombre_pila, tomado de la primera
     * fila del reporte — en la práctica todos los empleados de una empresa
     * comparten la misma ARL) y el código de sucursal (`$sucursalCodigo`,
     * columna G — texto libre que escribe el usuario en el formulario de
     * descarga, ver docblock de exportarXlsx()). Solo se tocan las celdas
     * A/C/D/G/I/K — el resto de la fila (labels, celdas de combinación)
     * queda tal cual estaba. G se omite del reemplazo si llega vacío, para
     * no borrar lo que ya trajera la plantilla.
     */
    private function reemplazarFilaDatosGenerales(string $sheetXml, array $filas, string $codiAno, string $codiMes, string $sucursalCodigo): string
    {
        $anoActual = (int)$codiAno;
        $mesActual = (int)$codiMes;

        $mesSiguiente = $mesActual + 1;
        $anoSiguiente = $anoActual;
        if ($mesSiguiente > 12) {
            $mesSiguiente = 1;
            $anoSiguiente++;
        }

        $nombreArl = '';
        foreach ($filas as $fila) {
            $valor = trim((string)($fila['ARL'] ?? ''));
            if ($valor !== '') {
                $nombreArl = $valor;
                break;
            }
        }

        $valoresPorColumna = [
            'A' => sprintf('%04d-%02d', $anoActual, $mesActual),
            'C' => sprintf('%04d-%02d', $anoSiguiente, $mesSiguiente),
            'D' => 'E',
            'I' => 'EMPLEADOR',
            'K' => $nombreArl,
        ];

        $sucursalCodigo = trim($sucursalCodigo);
        if ($sucursalCodigo !== '') {
            $valoresPorColumna['G'] = $sucursalCodigo;
        }

        return $this->reemplazarCeldasDeFila($sheetXml, self::FILA_DATOS_GENERALES, $valoresPorColumna);
    }

    /**
     * Reemplaza solo los valores de las columnas indicadas dentro de una
     * fila EXISTENTE del XML, preservando el resto de sus celdas (labels,
     * celdas vacías de combinación) y el estilo (`s="N"`) de cada una tal
     * cual estaban.
     *
     * @param array<string,mixed> $valoresPorColumna columna => valor nuevo
     */
    private function reemplazarCeldasDeFila(string $sheetXml, int $numeroFila, array $valoresPorColumna): string
    {
        [$inicio, $fin, $filaXml] = $this->extraerFila($sheetXml, $numeroFila);
        if ($filaXml === null) {
            throw new \RuntimeException("La plantilla base no tiene la fila $numeroFila esperada.");
        }

        $filaXml = preg_replace_callback(
            '/<c r="([A-Z]+)' . $numeroFila . '"((?:\s+[a-zA-Z:]+="[^"]*")*)\s*(?:\/>|>.*?<\/c>)/s',
            function (array $m) use ($valoresPorColumna, $numeroFila): string {
                $columna = $m[1];
                if (!array_key_exists($columna, $valoresPorColumna)) {
                    return $m[0];
                }
                $estilo = preg_match('/\bs="(\d+)"/', $m[2], $ms) ? $ms[1] : null;

                return $this->construirCeldaXml($columna, $numeroFila, $valoresPorColumna[$columna], $estilo);
            },
            $filaXml
        );

        if ($filaXml === null) {
            throw new \RuntimeException("No se pudo reemplazar celdas de la fila $numeroFila (error de expresión regular).");
        }

        return substr($sheetXml, 0, $inicio) . $filaXml . substr($sheetXml, $fin);
    }

    /**
     * Reemplaza el bloque completo de filas de ejemplo (FILA_MOLDE..ULTIMA_FILA_EJEMPLO)
     * por las filas reales del reporte, en el mismo estilo por columna que
     * traía la plantilla. Si el reporte tiene menos de 23 filas, las
     * sobrantes quedan en blanco (mismo estilo); si tiene más, se agregan
     * filas nuevas después de la 41 y se actualiza `<dimension>`.
     *
     * @param list<array<string,mixed>> $filas
     */
    private function reemplazarFilasDeDatos(string $sheetXml, array $filas): string
    {
        $estilosPorColumna = $this->extraerEstilosPorColumna($sheetXml, self::FILA_MOLDE, self::ULTIMA_FILA_EJEMPLO);

        [$inicioBloque] = $this->extraerFila($sheetXml, self::FILA_MOLDE);
        [, $finBloque] = $this->extraerFila($sheetXml, self::ULTIMA_FILA_EJEMPLO);

        $bloque = '';
        $numero = 1;
        $ultimaFila = self::FILA_MOLDE - 1;

        foreach ($filas as $fila) {
            $filaDestino = self::FILA_MOLDE + $numero - 1;

            $valoresPorColumna = ['A' => $numero];

            $valores = array_values($fila);
            foreach ($valores as $i => $valor) {
                if ($i === self::INDICE_TARIFA_ARL && is_numeric($valor)) {
                    $valor = $valor / 100;
                } else {
                    $valor = $this->normalizarValorTarifa($valor);
                }
                $valoresPorColumna[Coordinate::stringFromColumnIndex($i + 2)] = $valor; // B..CM
            }

            foreach (self::VALORES_FIJOS_CN_CT as $columna => $valorFijo) {
                $valoresPorColumna[$columna] = $valorFijo;
            }

            $bloque .= $this->construirFilaXml($filaDestino, $valoresPorColumna, $estilosPorColumna);
            $ultimaFila = $filaDestino;
            $numero++;
        }

        /*
         * NO se rellenan filas "vacías pero con estilo" hasta ULTIMA_FILA_EJEMPLO:
         * el portal "Aportes en línea" SÍ las procesa como líneas de empleado
         * (con Tipo ID/No ID vacíos) y las rechaza — "El archivo presenta
         * inconsistencias en los campos tipo ID y No ID Línea :N" — confirmado
         * por el usuario al borrar esas filas a mano y subir el archivo con
         * éxito. Si el reporte tiene menos de 23 filas, el bloque de filas de
         * ejemplo simplemente se elimina del todo (sin reemplazo) en vez de
         * dejarse en blanco.
         */
        $sheetXml = substr($sheetXml, 0, $inicioBloque) . $bloque . substr($sheetXml, $finBloque);

        $sheetXml = preg_replace(
            '/<dimension ref="A1:CT\d+"\/>/',
            '<dimension ref="A1:CT' . max($ultimaFila, self::FILA_MOLDE - 1) . '"/>',
            $sheetXml,
            1
        ) ?? $sheetXml;

        return $sheetXml;
    }

    /**
     * Combina el estilo (`s="N"`) de cada columna A..CT visto en cualquiera
     * de las filas $filaDesde..$filaHasta (el primero encontrado gana) — se
     * combinan varias filas de ejemplo en vez de tomar solo una porque
     * algunas columnas quedan totalmente vacías (sin celda en el XML) en
     * ciertas filas de ejemplo puntuales, pero sí tienen estilo en otras.
     *
     * @return array<string,?string> columna => estilo (o null si ninguna fila trae esa columna)
     */
    private function extraerEstilosPorColumna(string $sheetXml, int $filaDesde, int $filaHasta): array
    {
        if (!preg_match_all('/<row r="(\d+)"[^>]*>(.*?)<\/row>/s', $sheetXml, $filasMatch, PREG_SET_ORDER)) {
            return [];
        }

        $porIndice = [];
        foreach ($filasMatch as $f) {
            $numFila = (int)$f[1];
            if ($numFila < $filaDesde || $numFila > $filaHasta) {
                continue;
            }

            if (!preg_match_all('/<c ([^>]*?)\/?>/', $f[2], $celdas)) {
                continue;
            }

            foreach ($celdas[1] as $atributos) {
                if (!preg_match('/\br="([A-Z]+)\d+"/', $atributos, $mr)) {
                    continue;
                }
                $columna = $mr[1];
                $idx = Coordinate::columnIndexFromString($columna);
                if (isset($porIndice[$idx])) {
                    continue;
                }
                $porIndice[$idx] = [
                    'columna' => $columna,
                    'estilo' => preg_match('/\bs="(\d+)"/', $atributos, $ms) ? $ms[1] : null,
                ];
            }
        }

        ksort($porIndice);

        $resultado = [];
        foreach ($porIndice as $info) {
            $resultado[$info['columna']] = $info['estilo'];
        }

        return $resultado;
    }

    /**
     * @param array<string,mixed> $valoresPorColumna columna => valor (columnas ausentes quedan vacías)
     * @param array<string,?string> $estilosPorColumna columna => estilo, en el orden A..CT deseado
     */
    private function construirFilaXml(int $numeroFila, array $valoresPorColumna, array $estilosPorColumna): string
    {
        $celdas = '';
        foreach ($estilosPorColumna as $columna => $estilo) {
            $celdas .= $this->construirCeldaXml($columna, $numeroFila, $valoresPorColumna[$columna] ?? null, $estilo);
        }

        return '<row r="' . $numeroFila . '" spans="1:' . count($estilosPorColumna) . '" x14ac:dyDescent="0.25">'
            . $celdas . '</row>';
    }

    /** Números como valores numéricos de celda; todo lo demás como texto inline (no toca sharedStrings.xml). */
    private function construirCeldaXml(string $columna, int $fila, mixed $valor, ?string $estilo): string
    {
        $ref = $columna . $fila;
        $sAttr = $estilo !== null ? ' s="' . $estilo . '"' : '';

        if ($valor === null || $valor === '') {
            return "<c r=\"$ref\"$sAttr/>";
        }

        if (is_int($valor) || is_float($valor) || (is_string($valor) && is_numeric($valor))) {
            $v = is_string($valor) ? $valor : (string)$valor;

            return "<c r=\"$ref\"$sAttr><v>" . htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</v></c>';
        }

        $texto = $this->sanearTextoXml((string)$valor);

        return "<c r=\"$ref\"$sAttr t=\"inlineStr\"><is><t xml:space=\"preserve\">$texto</t></is></c>";
    }

    /** Escapa para XML y quita caracteres de control no permitidos por XML 1.0 (fuera de tab/CR/LF). */
    private function sanearTextoXml(string $texto): string
    {
        $texto = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $texto) ?? $texto;

        return htmlspecialchars($texto, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Offsets [inicio, fin] (fin exclusivo) y contenido del bloque
     * `<row r="$numeroFila" ...>...</row>` dentro de $sheetXml, o
     * `[0, 0, null]` si no existe esa fila.
     *
     * @return array{0:int,1:int,2:?string}
     */
    private function extraerFila(string $sheetXml, int $numeroFila): array
    {
        if (!preg_match('/<row r="' . $numeroFila . '"[^>]*>.*?<\/row>/s', $sheetXml, $m, PREG_OFFSET_CAPTURE)) {
            return [0, 0, null];
        }

        $inicio = $m[0][1];
        $fin = $inicio + strlen($m[0][0]);

        return [$inicio, $fin, $m[0][0]];
    }
}
