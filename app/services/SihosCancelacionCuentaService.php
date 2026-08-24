<?php

/**
 * Acción administrativa: crear en SIHOS una Nota Contabilidad (NC) que
 * cancela (débito) una línea contable "fuera de lo esperado" de una
 * factura (sección 5a del reporte de cruce) contra (crédito) la(s)
 * línea(s) 4312 que esa misma factura ya tiene, repartido
 * proporcionalmente si hay más de una. Solo contabilidad — nunca toca
 * DetaPlan ni presupuesto.
 *
 * Mismo patrón que SihosPresupuestoEliminacionService: conexión de
 * escritura separada de la de solo lectura, re-verificación en fresco
 * antes de escribir, chequeo de cierre de período, y registro manual en
 * `auditoria` de SAVID (AuditingPDO no cubre esta escritura externa).
 */
class SihosCancelacionCuentaService
{
    private SihosEmpresaConfigRepository $configRepository;
    private SihosUsuaDigiResolver $usuaDigiResolver;

    public function __construct()
    {
        $this->configRepository = new SihosEmpresaConfigRepository();
        $this->usuaDigiResolver = new SihosUsuaDigiResolver();
    }

    public function reversarCuentaInesperada(int $empresaId, string $codiDocuFactura, string $numeDocuFactura, int $consDeta): array
    {
        $configFila = $this->configRepository->findByEmpresaId($empresaId);
        if ($configFila === null || $configFila['host'] === '' || $configFila['base_datos'] === '') {
            return ['ok' => false, 'message' => 'Esta empresa no tiene conexión a SIHOS configurada.'];
        }

        $codiInst = trim((string)($configFila['codi_inst'] ?? ''));
        $usuarioEscritura = trim((string)($configFila['usuario_escritura'] ?? ''));
        if ($codiInst === '' || $usuarioEscritura === '' || empty($configFila['password_escritura_cifrado'])) {
            return [
                'ok' => false,
                'message' => 'Configure las credenciales de escritura de esta empresa en Conexión SIHOS antes de usar esta acción.',
            ];
        }

        $repositorioLectura = new SihosExternalRepository([
            'host' => $configFila['host'],
            'port' => (string)$configFila['puerto'],
            'database' => $configFila['base_datos'],
            'username' => $configFila['usuario'],
            'codiInst' => $codiInst,
            'password' => SihosCredentialCipher::decrypt($configFila['password_cifrado'] ?? null),
            'charset' => $configFila['charset'],
        ]);

        try {
            $estado = $repositorioLectura->fetchEstadoParaReversionCuentaInesperada($codiDocuFactura, $numeDocuFactura, $consDeta);
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo consultar SIHOS: ' . $e->getMessage()];
        }

        if ($estado === null) {
            return ['ok' => false, 'message' => "No se encontró el documento {$codiDocuFactura}-{$numeDocuFactura} en SIHOS, o la línea ya no existe."];
        }

        if ($estado['Anulado'] === 1) {
            return ['ok' => false, 'message' => 'La factura está anulada, no se modifica.'];
        }

        if ($estado['Lineas4312'] === []) {
            return [
                'ok' => false,
                'message' => "La factura {$codiDocuFactura}-{$numeDocuFactura} ya no tiene ninguna línea 4312 contra la cual repartir — puede que ya se haya corregido.",
            ];
        }

        try {
            $ajustePrevio = $repositorioLectura->fetchAjustePrevio($codiDocuFactura, $numeDocuFactura, $estado['LineaInesperadaCodiCont']);
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo verificar si ya existe un ajuste previo: ' . $e->getMessage()];
        }

        if ($ajustePrevio !== null) {
            return [
                'ok' => false,
                'message' => "Ya existe un ajuste para la cuenta {$estado['LineaInesperadaCodiCont']} de esta factura: "
                    . "{$ajustePrevio['CodiDocu']}-{$ajustePrevio['NumeDocu']} en SIHOS. No se crea otro.",
            ];
        }

        try {
            $maneNIIF = $repositorioLectura->fetchManeNIIF();
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo verificar la configuración NIIF de SIHOS: ' . $e->getMessage()];
        }

        if ($maneNIIF) {
            $codigosCuenta = array_values(array_unique([
                $estado['LineaInesperadaCodiCont'],
                ...array_column($estado['Lineas4312'], 'CodiCont'),
            ]));

            try {
                $homologaciones = $repositorioLectura->fetchHomologacionesNIIF($codigosCuenta);
            } catch (PDOException $e) {
                return ['ok' => false, 'message' => 'No se pudo verificar la homologación NIIF en SIHOS: ' . $e->getMessage()];
            }

            $faltantes = array_diff($codigosCuenta, array_keys($homologaciones));
            if ($faltantes !== []) {
                return [
                    'ok' => false,
                    'message' => 'Esta institución maneja NIIF y falta homologación (HomoNIIF) para: ' . implode(', ', $faltantes)
                        . ' — configúrela en SIHOS antes de continuar.',
                ];
            }
        }

        try {
            $fechaResuelta = $this->resolverFechaNotaContable($repositorioLectura, $estado['FechDocu']);
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo verificar el cierre de periodos en SIHOS: ' . $e->getMessage()];
        }

        if ($fechaResuelta === null) {
            return [
                'ok' => false,
                'message' => 'No se encontró un mes contable abierto en SIHOS en un rango razonable — revise los cierres del módulo de Contabilidad.',
            ];
        }

        [$fecha, $codiAno, $codiMes] = $fechaResuelta;

        if ($fecha < $estado['FechDocu']) {
            return [
                'ok' => false,
                'message' => "La fecha resuelta ({$fecha}) sería anterior a la de la factura ({$estado['FechDocu']}) — no se continúa por seguridad.",
            ];
        }

        try {
            $codiDocuNota = $repositorioLectura->resolveCodigoNotaContableGenerica();
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo resolver el tipo de documento en SIHOS: ' . $e->getMessage()];
        }

        if ($codiDocuNota === null) {
            return [
                'ok' => false,
                'message' => 'No se encontró el tipo de documento "Nota Contabilidad" (DocuApli=3) configurado en SIHOS para esta empresa.',
            ];
        }

        $repositorioEscritura = new SihosExternalWriteRepository([
            'host' => $configFila['host'],
            'port' => (string)$configFila['puerto'],
            'database' => $configFila['base_datos'],
            'username' => $usuarioEscritura,
            'codiInst' => $codiInst,
            'password' => SihosCredentialCipher::decrypt($configFila['password_escritura_cifrado']),
            'charset' => $configFila['charset'],
        ]);

        try {
            $resultado = $repositorioEscritura->crearNotaCancelacionCuentaInesperada(
                $codiDocuFactura,
                $numeDocuFactura,
                $consDeta,
                $codiDocuNota,
                $fecha,
                $codiAno,
                $codiMes,
                $maneNIIF,
                $this->usuaDigiResolver->resolver((int)($_SESSION['user_id'] ?? 0), $repositorioLectura)
            );
        } catch (SihosOperacionEnCursoException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo escribir en SIHOS: ' . $e->getMessage()];
        }

        if (!$resultado['ok']) {
            return ['ok' => false, 'message' => $resultado['motivo'] ?? 'No se pudo completar la acción en SIHOS.'];
        }

        $this->registrarAuditoria($empresaId, $codiInst, $codiDocuFactura, $numeDocuFactura, $resultado);

        return [
            'ok' => true,
            'message' => "Se creó {$resultado['codiDocuNota']}-{$resultado['numeDocuNota']} en SIHOS ({$fecha}) cancelando "
                . number_format($resultado['valorTotal'], 0, ',', '.') . " de la cuenta {$estado['LineaInesperadaCodiCont']} contra 4312.",
        ];
    }

    /**
     * Reclasifica la cuenta 4312 de una nota (NCF) que referencia una
     * factura de vigencia anterior (sección 5b) — decide automáticamente
     * entre editar en sitio (mes abierto, `DetaCont.CodiCont` cambia sin
     * dejar rastro de documento nuevo) o crear una nota de ajuste (mes
     * cerrado, no se reescribe un período cerrado). El usuario solo elige
     * la cuenta destino (una de `CodiInst.CuenGlos/CuenDeAn/CuenCaAn` — las
     * 3 variantes de VIGENCIA ANTERIOR: "Aceptación de Glosas Vigencia
     * Anterior", "Devolución de Facturas Vigencias Anteriores" y
     * "Conciliación Vigencias Anteriores" respectivamente, confirmado
     * contra el código fuente de SIHOS; `CuenGlosAct`/`CuenCast` son de
     * vigencia ACTUAL y no aplican aquí); el sistema decide la rama según
     * `isContabilidadCerrada(Modulo=22)` sobre el mes de la propia nota.
     */
    public function reclasificarCuentaVigenciaAnterior(int $empresaId, string $codiDocuNota, string $numeDocuNota, string $cuentaDestino): array
    {
        $configFila = $this->configRepository->findByEmpresaId($empresaId);
        if ($configFila === null || $configFila['host'] === '' || $configFila['base_datos'] === '') {
            return ['ok' => false, 'message' => 'Esta empresa no tiene conexión a SIHOS configurada.'];
        }

        $codiInst = trim((string)($configFila['codi_inst'] ?? ''));
        $usuarioEscritura = trim((string)($configFila['usuario_escritura'] ?? ''));
        if ($codiInst === '' || $usuarioEscritura === '' || empty($configFila['password_escritura_cifrado'])) {
            return [
                'ok' => false,
                'message' => 'Configure las credenciales de escritura de esta empresa en Conexión SIHOS antes de usar esta acción.',
            ];
        }

        $repositorioLectura = new SihosExternalRepository([
            'host' => $configFila['host'],
            'port' => (string)$configFila['puerto'],
            'database' => $configFila['base_datos'],
            'username' => $configFila['usuario'],
            'codiInst' => $codiInst,
            'password' => SihosCredentialCipher::decrypt($configFila['password_cifrado'] ?? null),
            'charset' => $configFila['charset'],
        ]);

        try {
            $estado = $repositorioLectura->fetchEstadoParaReclasificacionVigenciaAnterior($codiDocuNota, $numeDocuNota);
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo consultar SIHOS: ' . $e->getMessage()];
        }

        if ($estado === null) {
            return ['ok' => false, 'message' => "No se encontró el documento {$codiDocuNota}-{$numeDocuNota} en SIHOS."];
        }

        if ($estado['Anulado'] === 1) {
            return ['ok' => false, 'message' => 'La nota está anulada, no se modifica.'];
        }

        if ($estado['Lineas4312'] === []) {
            return [
                'ok' => false,
                'message' => "La nota {$codiDocuNota}-{$numeDocuNota} ya no tiene líneas 4312 de vigencia anterior por corregir — puede que ya se haya corregido.",
            ];
        }

        try {
            $cuentasInstitucion = $repositorioLectura->fetchCuentasInstitucion();
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo consultar la configuración de SIHOS: ' . $e->getMessage()];
        }

        $cuentasValidas = array_values(array_unique(array_filter([
            trim((string)($cuentasInstitucion['CuenGlos'] ?? '')),
            trim((string)($cuentasInstitucion['CuenDeAn'] ?? '')),
            trim((string)($cuentasInstitucion['CuenCaAn'] ?? '')),
        ], static fn (string $c): bool => $c !== '')));

        if (!in_array($cuentaDestino, $cuentasValidas, true)) {
            return [
                'ok' => false,
                'message' => 'La cuenta destino no es una de las cuentas de vigencia anterior configuradas en SIHOS (CuenGlos/CuenDeAn/CuenCaAn).',
            ];
        }

        $cuentasACorregir = array_values(array_unique(array_column($estado['Lineas4312'], 'CodiCont')));

        foreach ($cuentasACorregir as $cuenta) {
            try {
                $ajustePrevio = $repositorioLectura->fetchAjustePrevioNota($codiDocuNota, $numeDocuNota, $cuenta);
            } catch (PDOException $e) {
                return ['ok' => false, 'message' => 'No se pudo verificar si ya existe un ajuste previo: ' . $e->getMessage()];
            }

            if ($ajustePrevio !== null) {
                return [
                    'ok' => false,
                    'message' => "Ya existe un ajuste para la cuenta {$cuenta} de esta nota: "
                        . "{$ajustePrevio['CodiDocu']}-{$ajustePrevio['NumeDocu']} en SIHOS. No se crea otro.",
                ];
            }
        }

        try {
            $maneNIIF = $repositorioLectura->fetchManeNIIF();
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo verificar la configuración NIIF de SIHOS: ' . $e->getMessage()];
        }

        if ($maneNIIF) {
            $codigosCuenta = array_values(array_unique([$cuentaDestino, ...$cuentasACorregir]));

            try {
                $homologaciones = $repositorioLectura->fetchHomologacionesNIIF($codigosCuenta);
            } catch (PDOException $e) {
                return ['ok' => false, 'message' => 'No se pudo verificar la homologación NIIF en SIHOS: ' . $e->getMessage()];
            }

            $faltantes = array_diff($codigosCuenta, array_keys($homologaciones));
            if ($faltantes !== []) {
                return [
                    'ok' => false,
                    'message' => 'Esta institución maneja NIIF y falta homologación (HomoNIIF) para: ' . implode(', ', $faltantes)
                        . ' — configúrela en SIHOS antes de continuar.',
                ];
            }
        }

        $codiAno = substr($estado['FechDocu'], 0, 4);
        $codiMes = (string)(int)substr($estado['FechDocu'], 5, 2);

        try {
            $cerrado = $repositorioLectura->isContabilidadCerrada($codiAno, $codiMes);
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo verificar el cierre del período en SIHOS: ' . $e->getMessage()];
        }

        $repositorioEscritura = new SihosExternalWriteRepository([
            'host' => $configFila['host'],
            'port' => (string)$configFila['puerto'],
            'database' => $configFila['base_datos'],
            'username' => $usuarioEscritura,
            'codiInst' => $codiInst,
            'password' => SihosCredentialCipher::decrypt($configFila['password_escritura_cifrado']),
            'charset' => $configFila['charset'],
        ]);

        if (!$cerrado) {
            try {
                $resultado = $repositorioEscritura->reclasificarCuentaEnSitio(
                    $codiDocuNota,
                    $numeDocuNota,
                    $cuentaDestino,
                    $codiAno,
                    $codiMes,
                    $maneNIIF,
                    $this->usuaDigiResolver->resolver((int)($_SESSION['user_id'] ?? 0), $repositorioLectura)
                );
            } catch (SihosOperacionEnCursoException $e) {
                return ['ok' => false, 'message' => $e->getMessage()];
            } catch (PDOException $e) {
                return ['ok' => false, 'message' => 'No se pudo escribir en SIHOS: ' . $e->getMessage()];
            }

            if (!$resultado['ok']) {
                return ['ok' => false, 'message' => $resultado['motivo'] ?? 'No se pudo completar la acción en SIHOS.'];
            }

            $this->registrarAuditoriaReclasificacion(
                $empresaId,
                'UPDATE',
                'sihos.DetaCont',
                "{$codiInst}-{$codiDocuNota}-{$numeDocuNota}",
                $resultado,
                "Reclasificación en sitio (mes abierto) de cuenta(s) 4312 de vigencia anterior en {$codiDocuNota}-{$numeDocuNota} hacia {$cuentaDestino}."
            );

            return [
                'ok' => true,
                'message' => "Se reclasificó la cuenta de {$codiDocuNota}-{$numeDocuNota} hacia {$cuentaDestino} directamente en SIHOS (mes abierto).",
            ];
        }

        try {
            $fechaResuelta = $this->resolverFechaNotaContable($repositorioLectura, $estado['FechDocu']);
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo verificar el cierre de periodos en SIHOS: ' . $e->getMessage()];
        }

        if ($fechaResuelta === null) {
            return [
                'ok' => false,
                'message' => 'No se encontró un mes contable abierto en SIHOS en un rango razonable — revise los cierres del módulo de Contabilidad.',
            ];
        }

        [$fecha, $codiAnoNota, $codiMesNota] = $fechaResuelta;

        try {
            $codiDocuNotaNueva = $repositorioLectura->resolveCodigoNotaContableGenerica();
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo resolver el tipo de documento en SIHOS: ' . $e->getMessage()];
        }

        if ($codiDocuNotaNueva === null) {
            return [
                'ok' => false,
                'message' => 'No se encontró el tipo de documento "Nota Contabilidad" (DocuApli=3) configurado en SIHOS para esta empresa.',
            ];
        }

        try {
            $resultado = $repositorioEscritura->crearNotaAjusteVigenciaAnterior(
                $codiDocuNota,
                $numeDocuNota,
                $cuentaDestino,
                $codiDocuNotaNueva,
                $fecha,
                $codiAnoNota,
                $codiMesNota,
                $maneNIIF,
                $this->usuaDigiResolver->resolver((int)($_SESSION['user_id'] ?? 0), $repositorioLectura)
            );
        } catch (SihosOperacionEnCursoException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo escribir en SIHOS: ' . $e->getMessage()];
        }

        if (!$resultado['ok']) {
            return ['ok' => false, 'message' => $resultado['motivo'] ?? 'No se pudo completar la acción en SIHOS.'];
        }

        $this->registrarAuditoriaReclasificacion(
            $empresaId,
            'INSERT',
            'sihos.EncaCont',
            "{$codiInst}-{$resultado['codiDocuNota']}-{$resultado['numeDocuNota']}",
            $resultado,
            "Nota de ajuste (mes cerrado) que reclasifica cuenta(s) 4312 de vigencia anterior de {$codiDocuNota}-{$numeDocuNota} hacia {$cuentaDestino}."
        );

        return [
            'ok' => true,
            'message' => "Se creó {$resultado['codiDocuNota']}-{$resultado['numeDocuNota']} en SIHOS ({$fecha}) reclasificando "
                . number_format($resultado['valorTotal'], 0, ',', '.') . " de {$codiDocuNota}-{$numeDocuNota} hacia {$cuentaDestino}.",
        ];
    }

    /**
     * Registro manual en la auditoría de SAVID para la acción de
     * reclasificación de cuenta de vigencia anterior — parametrizado porque
     * puede ser un `UPDATE` (rama de mes abierto) o un `INSERT` (rama de
     * mes cerrado). Mismo formato de columnas que registrarAuditoria().
     */
    private function registrarAuditoriaReclasificacion(
        int $empresaId,
        string $accion,
        string $tabla,
        string $registroId,
        array $resultado,
        string $sqlResumen
    ): void {
        $database = new Database();
        $pdo = $database->connect();

        $stmt = $pdo->prepare('
            INSERT INTO auditoria (
                accion, tabla, registro_id,
                datos_anteriores, datos_nuevos, campos_cambiados,
                sql_resumen, usuario_id, empresa_id, sede_id,
                ip, user_agent, request_url
            ) VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?)
        ');

        $stmt->execute([
            $accion,
            $tabla,
            $registroId,
            $accion === 'UPDATE' ? json_encode($resultado['lineasAntes'] ?? null, JSON_UNESCAPED_UNICODE) : null,
            json_encode($accion === 'UPDATE' ? ($resultado['lineasDespues'] ?? null) : $resultado, JSON_UNESCAPED_UNICODE),
            $sqlResumen,
            $_SESSION['user_id'] ?? null,
            $empresaId,
            $_SESSION['sede_id'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $_SERVER['REQUEST_URI'] ?? null,
        ]);
    }

    /**
     * Primer día del mes contable (Modulo=22) que esté abierto, empezando a
     * buscar desde el año/mes de la factura hacia adelante (tope 36 meses).
     * No es una reconstrucción ni un cálculo retroactivo: es la misma
     * pregunta que se le haría a SIHOS antes de digitar un comprobante hoy.
     *
     * @return array{0:string,1:string,2:string}|null [fecha 'Y-m-01', codiAno, codiMes]
     */
    private function resolverFechaNotaContable(SihosExternalRepository $repositorioLectura, string $fechaFactura): ?array
    {
        $anno = (int)substr($fechaFactura, 0, 4);
        $mes = (int)substr($fechaFactura, 5, 2);

        for ($i = 0; $i < 36; $i++) {
            $codiAno = (string)$anno;
            $codiMes = (string)$mes;

            if (!$repositorioLectura->isContabilidadCerrada($codiAno, $codiMes)) {
                $fecha = sprintf('%04d-%02d-01', $anno, $mes);

                return [$fecha, $codiAno, $codiMes];
            }

            $mes++;
            if ($mes > 12) {
                $mes = 1;
                $anno++;
            }
        }

        return null;
    }

    /**
     * Registro manual en la auditoría de SAVID: AuditingPDO solo cubre
     * escrituras en la BD propia de SAVID, no ésta contra SIHOS. Mismo
     * formato de columnas que SihosPresupuestoEliminacionService — aquí es
     * `datos_nuevos` (no `datos_anteriores`), porque es una creación.
     */
    private function registrarAuditoria(int $empresaId, string $codiInst, string $codiDocuFactura, string $numeDocuFactura, array $resultado): void
    {
        $database = new Database();
        $pdo = $database->connect();

        $stmt = $pdo->prepare('
            INSERT INTO auditoria (
                accion, tabla, registro_id,
                datos_anteriores, datos_nuevos, campos_cambiados,
                sql_resumen, usuario_id, empresa_id, sede_id,
                ip, user_agent, request_url
            ) VALUES (?, ?, ?, NULL, ?, NULL, ?, ?, ?, ?, ?, ?, ?)
        ');

        // 'accion' es un ENUM('INSERT','UPDATE','DELETE') en SAVID (el mismo
        // que usa AuditingPDO para sus propias escrituras) — no texto libre.
        // La descripción específica de qué se hizo va en sql_resumen/tabla,
        // no en accion. Verificado con un caso real: un valor fuera del
        // ENUM lanza una excepción de truncamiento DESPUÉS de que SIHOS ya
        // había comprometido la escritura, cortando la respuesta al
        // navegador sin que el usuario supiera que sí se había creado.
        $stmt->execute([
            'INSERT',
            'sihos.EncaCont',
            "{$codiInst}-{$resultado['codiDocuNota']}-{$resultado['numeDocuNota']}",
            json_encode($resultado, JSON_UNESCAPED_UNICODE),
            "Creación de Nota Contabilidad en SIHOS que cancela una cuenta fuera de lo esperado contra 4312, "
                . "sobre la factura {$codiDocuFactura}-{$numeDocuFactura}",
            $_SESSION['user_id'] ?? null,
            $empresaId,
            $_SESSION['sede_id'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $_SERVER['REQUEST_URI'] ?? null,
        ]);
    }
}
