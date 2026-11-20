<?php

/**
 * Reporte SIHOS "Órdenes vs liquidación": qué se ordenó (EncaOrde/DetaOrde) y
 * qué se liquidó (DetaFact) en el hospital, y qué quedó sin liquidar o se
 * liquidó sin orden. El rango filtra por fecha de la orden (EncaOrde.Fecha) y,
 * para "liquidado sin orden", por fecha de la liquidación (EncaFact.FechFact).
 * Genera un CSV de detalle y devuelve los agregados de la pantalla. Solo
 * lectura; sin datos del paciente (únicamente el consecutivo de admisión).
 * Las reglas de cruce viven en SihosOrdenLiquidacionCruce.
 */
class SihosOrdenLiquidacionService
{
    public const COLUMNAS_DETALLE = [
        'Estado', 'Fecha', 'Admisión', 'Orden', 'Ítem', 'Cód. administradora', 'Administradora',
        'CUPS', 'Procedimiento', 'Tipo servicio (CUPS)', 'Actividad',
        'Cant. ordenada', 'Cant. realizada', 'Cant. facturada', 'Fecha suspensión',
        'Liquidación', 'Fecha liquidación', 'Cant. liquidada', 'Valor EAPB', 'Valor usuario', 'Valor total',
    ];

    private SihosEmpresaConfigRepository $configRepository;

    public function __construct()
    {
        $this->configRepository = new SihosEmpresaConfigRepository();
    }

    /**
     * @param resource $detalle handle abierto para escribir el CSV de detalle
     * @param list<string> $actividades vacío = todas (valores de SihosLiquidacionClasificador::ACTIVIDADES)
     * @param list<string> $eps CodiAdmi; vacío = todas
     * @param callable(int):void|null $progreso recibe las filas escritas
     * @return array<string,mixed>
     */
    public function generar(int $empresaId, string $desde, string $hasta, array $actividades, array $eps, $detalle, ?callable $progreso = null): array
    {
        $error = SihosLiquidacionService::validarRango($desde, $hasta);
        if ($error !== null) {
            return ['ok' => false, 'error' => $error];
        }
        $cfg = $this->configRepository->findByEmpresaId($empresaId);
        $codiInst = trim((string)($cfg['codi_inst'] ?? ''));
        if ($cfg === null || $codiInst === '' || $cfg['host'] === '' || $cfg['base_datos'] === '' || $cfg['usuario'] === '') {
            return ['ok' => false, 'error' => 'Esta empresa no tiene conexión a SIHOS configurada (o falta el CodiInst).'];
        }

        $pdo = (new SihosExternalRepository([
            'host' => $cfg['host'], 'port' => (string)$cfg['puerto'], 'database' => $cfg['base_datos'],
            'username' => $cfg['usuario'], 'codiInst' => $codiInst,
            'password' => SihosCredentialCipher::decrypt($cfg['password_cifrado'] ?? null), 'charset' => $cfg['charset'],
        ]))->connect();
        $repo = new SihosOrdenLiquidacionRepository($pdo, $codiInst);
        $clasif = new SihosLiquidacionClasificador((new SihosLiquidacionRepository($pdo, $codiInst))->fetchTiposServicio());
        $procs = $repo->fetchProcedimientos();
        $admins = $repo->fetchAdministradoras();
        $filtroAct = array_flip($actividades);
        $filtroEps = array_flip($eps);

        SihosLiquidacionService::escribirFila($detalle, self::COLUMNAS_DETALLE);
        $res = ['ok' => true, 'desde' => $desde, 'hasta' => $hasta, 'lineas' => 0, 'porEstado' => [], 'porActividad' => [], 'porEps' => []];

        foreach ($this->meses($desde, $hasta) as [$ini, $fin]) {
            $ordenesMes = $repo->fetchOrdenesRango($ini, $fin);
            $lineasMes = $repo->fetchLineasRango($ini, $fin);
            // Líneas sin admisión (liquidación libre): ConsAdmi vacío o 0 no sirve de llave (un IN con '' trae millones de filas).
            $sinAdmision = array_values(array_filter($lineasMes, static fn (array $l): bool => !self::admisionValida($l['ConsAdmi'])));
            $admisiones = array_values(array_unique(array_filter(
                [...array_column($ordenesMes, 'ConsAdmi'), ...array_column($lineasMes, 'ConsAdmi')],
                static fn ($a): bool => self::admisionValida($a)
            )));
            unset($lineasMes);
            $ordenes = $repo->fetchOrdenesAdmisiones($admisiones);
            $lineas = [...$repo->fetchLineasAdmisiones($admisiones), ...$sinAdmision];
            $cruce = SihosOrdenLiquidacionCruce::cruzar($ordenes, $lineas);

            $emitir = function (string $estado, array $fila) use (&$res, $detalle, $filtroAct, $filtroEps, $progreso): void {
                if (($filtroAct && !isset($filtroAct[$fila['actividad']])) || ($filtroEps && !isset($filtroEps[$fila['codiAdmi']]))) {
                    return;
                }
                SihosLiquidacionService::escribirFila($detalle, $fila['csv']);
                $v = [1, $fila['total']];
                self::sumar($res['porEstado'][$estado], $v);
                self::sumar($res['porActividad'][$fila['actividad']][$estado], $v);
                self::sumar($res['porEps'][$fila['codiAdmi'] . '|' . $fila['nombAdmi']][$estado], $v);
                $res['lineas']++;
                if ($progreso !== null && $res['lineas'] % 5000 === 0) {
                    $progreso($res['lineas']);
                }
            };

            foreach ($cruce['ordenes'] as $i => $c) {
                $o = $ordenes[$i];
                if ($o['Fecha'] < $ini || $o['Fecha'] > $fin) {
                    continue;
                }
                $p = $procs[$o['CodiProc']] ?? null;
                $tipo = $p['TipoServ'] ?? null;
                $actividad = $clasif->actividad($tipo, $tipo ?? 0, 1);
                $l = $c['linea'] !== null ? $lineas[$c['linea']] : null;
                $codiAdmi = trim((string)$o['CodiAdmi']);
                $nombAdmi = $admins[$codiAdmi] ?? '';
                $suspendida = SihosOrdenLiquidacionCruce::esFecha($o['FechSusp']) ? $o['FechSusp'] : '';
                $emitir($c['estado'], ['actividad' => $actividad, 'codiAdmi' => $codiAdmi, 'nombAdmi' => $nombAdmi, 'total' => $l !== null ? (float)$l['ValoTota'] : 0.0, 'csv' => [
                    $c['estado'], $o['Fecha'], $o['ConsAdmi'], $o['ConsOrde'], $o['Item'], $codiAdmi, $nombAdmi,
                    $p['CodiCups'] ?? $o['CodiProc'], $p['NombProc'] ?? '', $clasif->nombreTipoServicio($tipo), $actividad,
                    $o['CantSumi'], $o['CantReal'], $o['CantFact'], $suspendida,
                    ...self::columnasLinea($l),
                ]]);
            }
            foreach ($cruce['lineasSinOrden'] as $i) {
                $l = $lineas[$i];
                if ($l['FechFact'] < $ini || $l['FechFact'] > $fin) {
                    continue;
                }
                $p = $procs[$l['CodiProc']] ?? null;
                $tipo = $p['TipoServ'] ?? null;
                $actividad = $clasif->actividad($tipo, (int)$l['TipoServ'], (int)$l['TipoDeta']);
                $codiAdmi = trim((string)$l['CodiAdmi']);
                $nombAdmi = $admins[$codiAdmi] ?? '';
                $emitir(SihosOrdenLiquidacionCruce::SIN_ORDEN, ['actividad' => $actividad, 'codiAdmi' => $codiAdmi, 'nombAdmi' => $nombAdmi, 'total' => (float)$l['ValoTota'], 'csv' => [
                    SihosOrdenLiquidacionCruce::SIN_ORDEN, '', $l['ConsAdmi'], '', '', $codiAdmi, $nombAdmi,
                    $p['CodiCups'] ?? $l['CodiProc'], $p['NombProc'] ?? '', $clasif->nombreTipoServicio($tipo), $actividad,
                    '', '', '', '',
                    ...self::columnasLinea($l),
                ]]);
            }
        }

        ksort($res['porActividad']);
        ksort($res['porEps']);

        return $res;
    }

    private static function admisionValida(mixed $a): bool
    {
        $a = trim((string)$a);

        return $a !== '' && $a !== '0';
    }

    /** @param array<string,mixed>|null $l @return list<mixed> */
    private static function columnasLinea(?array $l): array
    {
        if ($l === null) {
            return ['', '', '', '', '', ''];
        }

        return [
            $l['CodiDocu'] . '-' . $l['NumeFact'], $l['FechFact'], SihosLiquidacionService::num((float)$l['CantServ']),
            SihosLiquidacionService::num((float)$l['ValoEAPB']), SihosLiquidacionService::num((float)$l['ValoUsua']), SihosLiquidacionService::num((float)$l['ValoTota']),
        ];
    }

    /** @param array<int,int|float>|null $acum @param array<int,int|float> $valores */
    private static function sumar(?array &$acum, array $valores): void
    {
        $acum ??= [0, 0.0];
        foreach ($valores as $i => $v) {
            $acum[$i] += $v;
        }
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
}
