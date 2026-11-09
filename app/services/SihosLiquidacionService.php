<?php

/**
 * Reporte SIHOS "Liquidación": producción por actividad y profesional de las
 * liquidaciones confirmadas de un rango de fechas elegido por el usuario.
 * Genera DOS CSV (detalle línea a línea y resumen mes × profesional × actividad)
 * y devuelve los agregados que muestra la pantalla de control. Solo lectura;
 * no incluye ningún dato del paciente.
 */
class SihosLiquidacionService
{
    private const MAX_MESES = 24;

    public const COLUMNAS_DETALLE = [
        'Fecha liquidación', 'Documento', 'Número liquidación', 'Cód. administradora', 'Administradora',
        'Tipo contrato', 'Contrato', 'Nombre contrato', 'Servicio origen', 'Servicio egreso/actual',
        'Tipo servicio (SIHOS)', 'Tipo servicio (CUPS)', 'Componente', 'Actividad', 'Producción médica',
        'CUPS', 'Procedimiento', 'Especialidad', 'Cédula profesional', 'Profesional',
        'Cédula profesional inferido', 'Profesional inferido', 'Método de inferencia', 'Confianza inferencia',
        'Cantidad', 'Valor EAPB', 'Valor usuario', 'Valor total',
    ];
    public const COLUMNAS_RESUMEN = [
        'Mes', 'Especialidad', 'Cédula profesional', 'Profesional', 'Actividad', 'Componente', 'Producción médica',
        'Líneas', 'Cantidad', 'Valor EAPB', 'Valor usuario', 'Valor total',
    ];

    private SihosEmpresaConfigRepository $configRepository;

    public function __construct()
    {
        $this->configRepository = new SihosEmpresaConfigRepository();
    }

    /** @return string|null mensaje de error, o null si el rango es válido */
    public static function validarRango(string $desde, string $hasta): ?string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $desde);
        $h = DateTimeImmutable::createFromFormat('!Y-m-d', $hasta);
        if (!$d || !$h || $d->format('Y-m-d') !== $desde || $h->format('Y-m-d') !== $hasta) {
            return 'Las fechas son inválidas.';
        }
        if ($d > $h) {
            return 'La fecha inicial no puede ser posterior a la final.';
        }
        if ($d->diff($h)->days > self::MAX_MESES * 31) {
            return 'El rango máximo es de ' . self::MAX_MESES . ' meses.';
        }

        return null;
    }

    /**
     * @param resource $detalle handle abierto para escribir el CSV de detalle
     * @param resource $resumen handle abierto para escribir el CSV de resumen
     * @param resource $especialistas handle abierto para escribir el CSV de especialistas
     * @param callable(int):void|null $progreso recibe las líneas procesadas
     * @return array<string,mixed>
     */
    public function generar(int $empresaId, string $desde, string $hasta, $detalle, $resumen, $especialistas, ?callable $progreso = null): array
    {
        $error = self::validarRango($desde, $hasta);
        if ($error !== null) {
            return ['ok' => false, 'error' => $error];
        }
        $cfg = $this->configRepository->findByEmpresaId($empresaId);
        $codiInst = trim((string)($cfg['codi_inst'] ?? ''));
        if ($cfg === null || $codiInst === '' || $cfg['host'] === '' || $cfg['base_datos'] === '' || $cfg['usuario'] === '') {
            return ['ok' => false, 'error' => 'Esta empresa no tiene conexión a SIHOS configurada (o falta el CodiInst).'];
        }

        $repo = new SihosLiquidacionRepository($this->conectar($cfg, $codiInst), $codiInst);
        // Conexión aparte: mientras se recorre el generador de líneas (sin buffer) la principal no admite otra consulta.
        $inferencia = new SihosLiquidacionInferencia(new SihosLiquidacionRepository($this->conectar($cfg, $codiInst), $codiInst));
        $clasif = new SihosLiquidacionClasificador($repo->fetchTiposServicio());

        self::escribirFila($detalle, self::COLUMNAS_DETALLE);
        $agregado = [];
        $mesesRango = array_map(static fn (array $t): string => substr($t[0], 0, 7), $this->meses($desde, $hasta));
        $porEspecialista = new SihosLiquidacionEspecialistas($mesesRango);
        $res = ['ok' => true, 'desde' => $desde, 'lineas' => 0, 'hasta' => $hasta, 'totales' => $this->vacio(),
            'sinEspecialidad' => 0, 'sinProfesional' => 0, 'tiposOtros' => [], 'porActividad' => [], 'porMesEspecialidad' => [], 'inferencia' => []];

        foreach ($this->meses($desde, $hasta) as [$ini, $fin]) {
            $inferencia->precargar($ini, $fin);
            foreach ($repo->fetchLineas($ini, $fin) as $f) {
                $tipoLinea = (int)$f['TipoServ'];
                $tipoCups = $f['TipoServCups'] !== null ? (int)$f['TipoServCups'] : null;
                $componente = $clasif->componente($tipoLinea);
                $actividad = $clasif->actividad($tipoCups, $tipoLinea, (int)$f['TipoDeta']);
                $prod = $clasif->produccionMedica($actividad, $componente);
                $esp = trim((string)$f['NombEspe']) !== '' ? trim((string)$f['NombEspe']) : 'SIN ESPECIALIDAD';
                $valores = [1, (float)$f['CantServ'], (float)$f['ValoEAPB'], (float)$f['ValoUsua'], (float)$f['ValoTota']];

                $inf = SihosLiquidacionInferencia::esAdministrativo($f['CodiMedi']) ? $inferencia->inferir($f, $actividad) : null;
                if ($inf !== null) {
                    $this->sumar($res['inferencia'][$inf['confianza']][$inf['metodo']], $valores);
                }

                self::escribirFila($detalle, [
                    $f['FechFact'], $f['CodiDocu'], $f['NumeFact'], $f['CodiAdmi'], $f['NombAdmi'],
                    $f['NombTipoCont'], $f['NumeCont'], $f['DescCont'], $f['NombServOrig'], $f['NombServEgre'],
                    $clasif->nombreTipoServicio($tipoLinea), $clasif->nombreTipoServicio($tipoCups), $componente, $actividad, $prod,
                    $f['CodiCups'], $f['NombProc'], $esp, $f['CedulaProf'], $f['NombreProf'],
                    $inf['cedula'] ?? '', $inf['nombre'] ?? '', $inf['metodo'] ?? '', $inf['confianza'] ?? '',
                    self::num($valores[1]), self::num($valores[2]), self::num($valores[3]), self::num($valores[4]),
                ]);

                $mes = substr((string)$f['FechFact'], 0, 7);
                $clave = implode("\x1f", [$mes, $esp, (string)$f['CedulaProf'], (string)$f['NombreProf'], $actividad, $componente, $prod]);
                $this->sumar($agregado[$clave], $valores);
                $porEspecialista->agregar($f, $esp, $actividad, $componente, $prod, $valores);
                $this->sumar($res['totales'], $valores);
                $this->sumar($res['porActividad'][$actividad][$prod], $valores);
                if ($prod === 'Sí') {
                    $this->sumar($res['porMesEspecialidad'][$mes][$esp], $valores);
                }
                $res['sinEspecialidad'] += $esp === 'SIN ESPECIALIDAD' ? 1 : 0;
                $res['sinProfesional'] += $f['NombreProf'] === null ? 1 : 0;
                if ($actividad === 'Otros') {
                    $nombre = $clasif->nombreTipoServicio($tipoCups ?? $tipoLinea);
                    $res['tiposOtros'][$nombre] = ($res['tiposOtros'][$nombre] ?? 0) + 1;
                }
                if ($progreso !== null && ++$res['lineas'] % 5000 === 0) {
                    $progreso($res['lineas']);
                }
            }
        }
        $res['lineas'] = (int)$res['totales'][0];

        ksort($agregado);
        self::escribirFila($resumen, self::COLUMNAS_RESUMEN);
        foreach ($agregado as $clave => $v) {
            self::escribirFila($resumen, [...explode("\x1f", $clave), $v[0], self::num($v[1]), self::num($v[2]), self::num($v[3]), self::num($v[4])]);
        }
        $res['especialistas'] = $porEspecialista->filas();
        $porEspecialista->escribirCsv($especialistas, $res['especialistas']);
        $res['mesesRango'] = $mesesRango;
        ksort($res['porMesEspecialidad']);
        ksort($res['porActividad']);
        arsort($res['tiposOtros']);
        ksort($res['inferencia']);

        return $res;
    }

    /** @param array<string,mixed> $cfg */
    private function conectar(array $cfg, string $codiInst): PDO
    {
        return (new SihosExternalRepository([
            'host' => $cfg['host'], 'port' => (string)$cfg['puerto'], 'database' => $cfg['base_datos'],
            'username' => $cfg['usuario'], 'codiInst' => $codiInst,
            'password' => SihosCredentialCipher::decrypt($cfg['password_cifrado'] ?? null), 'charset' => $cfg['charset'],
        ]))->connect();
    }

    /** @return list<array{0:string,1:string}> tramos mensuales [inicio, fin] que cubren el rango */
    private function meses(string $desde, string $hasta): array
    {
        $tramos = [];
        $cursor = new DateTimeImmutable($desde);
        $limite = new DateTimeImmutable($hasta);
        while ($cursor <= $limite) {
            $finMes = $cursor->modify('last day of this month');
            $fin = $finMes < $limite ? $finMes : $limite;
            $tramos[] = [$cursor->format('Y-m-d'), $fin->format('Y-m-d')];
            $cursor = $fin->modify('+1 day');
        }

        return $tramos;
    }

    /** @return array{0:int,1:float,2:float,3:float,4:float} líneas, cantidad, EAPB, usuario, total */
    private function vacio(): array
    {
        return [0, 0.0, 0.0, 0.0, 0.0];
    }

    /** @param array<int,int|float>|null $acum @param array<int,int|float> $valores */
    private function sumar(?array &$acum, array $valores): void
    {
        $acum ??= $this->vacio();
        foreach ($valores as $i => $v) {
            $acum[$i] += $v;
        }
    }

    /** Entero si no tiene decimales; si no, coma decimal (Excel es-CO con separador ";"). */
    public static function num(float $v): string
    {
        return floor($v) === $v ? (string)(int)$v : rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
    }

    /** @param resource $fh @param array<int,mixed> $fila */
    public static function escribirFila($fh, array $fila): void
    {
        // Un texto que empiece por = + @ lo evaluaría Excel como fórmula.
        $fila = array_map(static fn ($c) => is_string($c) && $c !== '' && in_array($c[0], ['=', '+', '@'], true) ? "'" . $c : $c, $fila);
        fputcsv($fh, $fila, ';');
    }
}
