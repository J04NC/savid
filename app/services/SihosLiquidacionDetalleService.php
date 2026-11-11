<?php

/**
 * Detalle (modal) del reporte SIHOS "Liquidación": las líneas del CSV de detalle
 * ya generado que corresponden a un filtro (profesional, especialidad, mes,
 * método de inferencia…). No consulta SIHOS: lee el mismo CSV del reporte, así
 * que coincide con lo que se ve en pantalla.
 *
 * La primera vez que se pide un filtro se recorre el CSV completo (con un
 * prefiltro de texto) y se guarda la selección en un archivo pequeño junto al
 * trabajo; las páginas, el orden y la búsqueda posteriores solo leen ese archivo.
 */
class SihosLiquidacionDetalleService
{
    /** Filtros permitidos: nombre => [columna del CSV, modo]. */
    private const FILTROS = [
        'cedula' => ['Cédula profesional', 'exacto'],
        'especialidad' => ['Especialidad', 'exacto'],
        'mes' => ['Fecha liquidación', 'prefijo'],
        'confianza' => ['Confianza inferencia', 'exacto'],
        'metodo' => ['Método de inferencia', 'exacto'],
        'actividad' => ['Actividad', 'exacto'],
        'prod' => ['Producción médica', 'exacto'],
        // Valor "institucional": líneas de sala y materiales de cirugía (valor '1').
        'institucional' => ['Componente', 'institucional'],
    ];

    /** Columnas que muestra el modal (nombres del CSV de detalle). */
    public const COLUMNAS_MODAL = [
        'Fecha liquidación', 'Documento', 'Número liquidación', 'Administradora', 'Servicio origen', 'Actividad', 'Componente',
        'CUPS', 'Procedimiento', 'Profesional', 'Profesional inferido', 'Confianza inferencia', 'Cantidad', 'Valor total',
    ];
    private const NUMERICAS = ['Cantidad', 'Valor total'];
    /** Columnas del CSV que identifican una línea de DetaFact y su corrección (reportes anteriores no las traen). */
    private const COLUMNAS_LLAVE = ['Año liquidación', 'Documento', 'Número liquidación', 'Consecutivo línea'];
    private const COLUMNA_LOGIN_INFERIDO = 'Usuario profesional inferido';
    public const CONFIANZAS_CORREGIBLES = ['Alta', 'Media'];
    private const MAX_PAGINA = 200;

    public function __construct(private readonly SihosLiquidacionJobService $job)
    {
    }

    /**
     * Deja solo filtros permitidos y bien formados.
     *
     * @param array<mixed> $crudo
     * @return array<string,string>|null null si no hay ningún filtro válido
     */
    public function normalizarFiltros(array $crudo): ?array
    {
        $filtros = [];
        foreach (self::FILTROS as $nombre => $_) {
            if (!isset($crudo[$nombre]) || !is_string($crudo[$nombre]) || mb_strlen($crudo[$nombre]) > 200) {
                continue;
            }
            $valor = trim($crudo[$nombre]);
            // Cédula vacía = profesional sin cédula (p. ej. el usuario administrativo); en los demás un valor vacío no filtra nada.
            if ($valor === '' && $nombre !== 'cedula') {
                continue;
            }
            if ($nombre === 'mes' && preg_match('/^\d{4}-\d{2}$/', $valor) !== 1) {
                continue;
            }
            if (($nombre === 'prod' && !in_array($valor, ['Sí', 'No'], true)) || ($nombre === 'institucional' && $valor !== '1')) {
                continue;
            }
            $filtros[$nombre] = $valor;
        }
        ksort($filtros);

        return $filtros === [] ? null : $filtros;
    }

    /**
     * Ruta local del archivo con las líneas de la selección (lo crea si no existe).
     * null si el reporte no es del usuario, expiró o la selección no se pudo guardar.
     *
     * @param array<string,string> $filtros ya normalizados
     */
    public function seleccion(int $empresaId, int $userId, string $token, array $filtros): ?string
    {
        $detalle = $this->job->rutaArchivo($empresaId, $userId, $token, 'detalle');
        if ($detalle === null || !is_file($detalle)) {
            return null;
        }
        $storage = StorageService::instance();
        $nombre = 'liquidacion_' . strtolower($token) . '_sel_' . substr(sha1((string)json_encode($filtros)), 0, 16) . '.csv';
        $key = $storage->key(StorageService::ZONE_SIHOS_REPORTES, $nombre, $empresaId);
        $ruta = $storage->localPath($key);
        if ($ruta !== null && is_file($ruta)) {
            return $ruta;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'liqsel');
        if ($tmp === false || !$this->extraer($detalle, $tmp, $filtros)) {
            return null;
        }
        if ($storage->putLocalFile(StorageService::ZONE_SIHOS_REPORTES, $nombre, $tmp, true, $empresaId) === null) {
            return null;
        }

        return $storage->localPath($key);
    }

    /**
     * Una página de la selección con el protocolo server-side de DataTables.
     *
     * @param array<string,mixed> $p draw, start, length, search[value], order[0][column|dir], fc[columna] (filtro de texto por columna)
     * @return array<string,mixed>
     */
    public function pagina(string $ruta, array $p): array
    {
        $h = fopen($ruta, 'rb');
        $cabecera = $h ? str_getcsv((string)self::leerRegistro($h), ';') : [];
        $registros = [];
        while ($h && ($reg = self::leerRegistro($h)) !== null) {
            $registros[] = $reg;
        }
        if ($h) {
            fclose($h);
        }
        $total = count($registros);

        $buscar = trim((string)($p['search']['value'] ?? ''));
        if ($buscar !== '') {
            $registros = array_values(array_filter($registros, static fn (string $r): bool => stripos($r, $buscar) !== false));
        }
        $porColumna = [];
        foreach ((array)($p['fc'] ?? []) as $i => $texto) {
            $texto = is_string($texto) ? trim($texto) : '';
            if ($texto !== '' && isset(self::COLUMNAS_MODAL[(int)$i])) {
                $porColumna[(int)$i] = mb_strtolower($texto);
            }
        }
        $indices = array_map(static fn (string $c): int => (int)array_search($c, $cabecera, true), self::COLUMNAS_MODAL);
        if ($porColumna !== []) {
            $registros = array_values(array_filter($registros, static function (string $r) use ($porColumna, $indices): bool {
                foreach ($porColumna as $i => $texto) {
                    if (stripos($r, $texto) === false) {
                        return false; // prefiltro barato: el texto debe estar en algún lugar del registro
                    }
                }
                $f = str_getcsv($r, ';');
                foreach ($porColumna as $i => $texto) {
                    if (mb_stripos((string)($f[$indices[$i]] ?? ''), $texto) === false) {
                        return false;
                    }
                }

                return true;
            }));
        }

        $orden = (int)($p['order'][0]['column'] ?? -1);
        if (isset(self::COLUMNAS_MODAL[$orden]) && count($registros) > 1) {
            $numerica = in_array(self::COLUMNAS_MODAL[$orden], self::NUMERICAS, true);
            $claves = array_map(static function (string $r) use ($indices, $orden, $numerica) {
                $v = str_getcsv($r, ';')[$indices[$orden]] ?? '';

                return $numerica ? (float)str_replace(',', '.', $v) : mb_strtolower($v);
            }, $registros);
            array_multisort($claves, ($p['order'][0]['dir'] ?? 'asc') === 'desc' ? SORT_DESC : SORT_ASC, $numerica ? SORT_NUMERIC : SORT_STRING, $registros);
        }

        $llave = self::indicesCorreccion($cabecera);
        $inicio = max(0, (int)($p['start'] ?? 0));
        $largo = min(self::MAX_PAGINA, max(1, (int)($p['length'] ?? 50)));
        $filas = [];
        foreach (array_slice($registros, $inicio, $largo) as $reg) {
            $f = str_getcsv($reg, ';');
            $fila = [];
            foreach ($indices as $i => $col) {
                $v = (string)($f[$col] ?? '');
                $fila[] = in_array(self::COLUMNAS_MODAL[$i], self::NUMERICAS, true) && $v !== ''
                    ? number_format((float)str_replace(',', '.', $v), 0, ',', '.')
                    : htmlspecialchars($v, ENT_QUOTES);
            }
            $fila[] = $llave !== null ? (self::lineaCorregible($f, $llave)['clave'] ?? '') : ''; // marca de selección del modal; vacía si no se puede corregir
            $filas[] = $fila;
        }

        return ['draw' => (int)($p['draw'] ?? 0), 'recordsTotal' => $total, 'recordsFiltered' => count($registros), 'data' => $filas];
    }

    /**
     * Índices de las columnas necesarias para corregir; null si el CSV es de un reporte anterior a esas columnas.
     *
     * @param list<string> $cabecera
     * @return array<string,int>|null
     */
    private static function indicesCorreccion(array $cabecera): ?array
    {
        $ix = [];
        foreach ([...self::COLUMNAS_LLAVE, self::COLUMNA_LOGIN_INFERIDO, 'Confianza inferencia'] as $nombre) {
            $i = array_search($nombre, $cabecera, true);
            if ($i === false) {
                return null;
            }
            $ix[$nombre] = (int)$i;
        }

        return $ix;
    }

    /**
     * Datos de corrección de una línea, o null si no tiene profesional inferido con confianza suficiente.
     *
     * @param list<string|null> $f
     * @param array<string,int> $ix
     * @return array{clave:string,ano:string,docu:string,nume:string,cons:string,login:string,confianza:string}|null
     */
    private static function lineaCorregible(array $f, array $ix): ?array
    {
        $login = trim((string)($f[$ix[self::COLUMNA_LOGIN_INFERIDO]] ?? ''));
        $confianza = (string)($f[$ix['Confianza inferencia']] ?? '');
        $v = array_map(static fn (string $c): string => trim((string)($f[$ix[$c]] ?? '')), self::COLUMNAS_LLAVE);
        if ($login === '' || !in_array($confianza, self::CONFIANZAS_CORREGIBLES, true) || in_array('', $v, true)) {
            return null;
        }

        return ['clave' => implode('|', $v), 'ano' => $v[0], 'docu' => $v[1], 'nume' => $v[2], 'cons' => $v[3], 'login' => $login, 'confianza' => $confianza];
    }

    /**
     * Líneas de la selección con esas llaves ('año|documento|número|consecutivo') que tienen profesional
     * inferido corregible. Los datos salen del CSV del reporte, nunca de lo que envía el navegador.
     *
     * @param list<string> $claves
     * @return array<string,array{clave:string,ano:string,docu:string,nume:string,cons:string,login:string,confianza:string}>|null null si el reporte no trae las columnas
     */
    public function lineasCorregibles(string $ruta, array $claves): ?array
    {
        $h = fopen($ruta, 'rb');
        if (!$h) {
            return null;
        }
        $cabecera = str_getcsv((string)self::leerRegistro($h), ';');
        $ix = self::indicesCorreccion($cabecera);
        $pendientes = array_flip($claves);
        $out = [];
        while ($ix !== null && $pendientes !== [] && ($reg = self::leerRegistro($h)) !== null) {
            $linea = self::lineaCorregible(str_getcsv($reg, ';'), $ix);
            if ($linea !== null && isset($pendientes[$linea['clave']])) {
                $out[$linea['clave']] = $linea;
                unset($pendientes[$linea['clave']]);
            }
        }
        fclose($h);

        return $ix === null ? null : $out;
    }

    /**
     * Copia a $destino la cabecera y las líneas del detalle que cumplen todos los filtros.
     *
     * @param array<string,string> $filtros
     */
    private function extraer(string $detalle, string $destino, array $filtros): bool
    {
        $h = fopen($detalle, 'rb');
        $out = fopen($destino, 'wb');
        if (!$h || !$out) {
            return false;
        }
        fgets($h); // BOM + "sep=;"
        $cabeceraRaw = self::leerRegistro($h);
        $cabecera = str_getcsv((string)$cabeceraRaw, ';');
        $reglas = [];
        foreach ($filtros as $nombre => $valor) {
            $idx = array_search(self::FILTROS[$nombre][0], $cabecera, true);
            if ($idx === false) {
                return false;
            }
            $reglas[] = [(int)$idx, $valor, self::FILTROS[$nombre][1]];
        }

        fwrite($out, (string)$cabeceraRaw);
        while (($reg = self::leerRegistro($h)) !== null) {
            foreach ($reglas as [, $valor, $modo]) {
                if ($valor !== '' && $modo !== 'institucional' && strpos($reg, $valor) === false) {
                    continue 2; // prefiltro de texto: evita parsear la mayoría de las líneas
                }
            }
            $f = str_getcsv($reg, ';');
            foreach ($reglas as [$idx, $valor, $modo]) {
                $celda = (string)($f[$idx] ?? '');
                $cumple = match ($modo) {
                    'prefijo' => str_starts_with($celda, $valor),
                    'institucional' => in_array($celda, SihosLiquidacionClasificador::COMPONENTES_INSTITUCIONALES, true),
                    default => $celda === $valor,
                };
                if (!$cumple) {
                    continue 2;
                }
            }
            fwrite($out, $reg);
        }
        fclose($h);

        return fclose($out);
    }

    /** Un registro del CSV: une líneas mientras haya comillas sin cerrar (campos con salto de línea). */
    private static function leerRegistro($h): ?string
    {
        $reg = fgets($h);
        if ($reg === false) {
            return null;
        }
        while (substr_count($reg, '"') % 2 === 1 && ($mas = fgets($h)) !== false) {
            $reg .= $mas;
        }

        return $reg;
    }
}
