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
    ];

    /** Columnas que muestra el modal (nombres del CSV de detalle). */
    public const COLUMNAS_MODAL = [
        'Fecha liquidación', 'Documento', 'Número liquidación', 'Administradora', 'Servicio origen', 'Actividad', 'Componente',
        'CUPS', 'Procedimiento', 'Profesional', 'Profesional inferido', 'Confianza inferencia', 'Cantidad', 'Valor total',
    ];
    private const NUMERICAS = ['Cantidad', 'Valor total'];
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
            foreach ($reglas as [, $valor]) {
                if ($valor !== '' && strpos($reg, $valor) === false) {
                    continue 2; // prefiltro de texto: evita parsear la mayoría de las líneas
                }
            }
            $f = str_getcsv($reg, ';');
            foreach ($reglas as [$idx, $valor, $modo]) {
                $celda = (string)($f[$idx] ?? '');
                if ($modo === 'prefijo' ? !str_starts_with($celda, $valor) : $celda !== $valor) {
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
