<?php

/**
 * Acciones administrativas sobre DetaPlan en SIHOS: borrar el huérfano de
 * una nota sobre una factura de vigencia ANTERIOR (nunca debería tener
 * presupuesto — regla de negocio confirmada por el usuario) y construir el
 * que le falta a una nota sobre una factura de la MISMA vigencia (sección 3
 * del reporte, formula validada contra casos sanos reales: CodiPlan del
 * TipoUsua de la factura, Valor de la nota).
 *
 * La reconstrucción presupuestal (SaldPlan/ValoUsad/SaldDisp y demás
 * columnas de saldo acumulado en SIHOS) queda deliberadamente FUERA de
 * ambas acciones — es un paso manual que el usuario sigue haciendo en SIHOS
 * después, porque esa lógica es particular de cada instalación y no se
 * replica aquí.
 */
class SihosPresupuestoEliminacionService
{
    private SihosEmpresaConfigRepository $configRepository;
    private SihosUsuaDigiResolver $usuaDigiResolver;

    public function __construct()
    {
        $this->configRepository = new SihosEmpresaConfigRepository();
        $this->usuaDigiResolver = new SihosUsuaDigiResolver();
    }

    public function eliminarDetaPlan(int $empresaId, string $codiDocu, string $numeDocu): array
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
            $estado = $repositorioLectura->fetchEstadoParaEliminacionDetaPlan($codiDocu, $numeDocu);
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo consultar SIHOS: ' . $e->getMessage()];
        }

        if ($estado === null) {
            return ['ok' => false, 'message' => "No se encontró el documento {$codiDocu}-{$numeDocu} en SIHOS."];
        }

        if ((int)$estado['Anulado'] === 1) {
            return ['ok' => false, 'message' => 'El documento está anulado, no se modifica.'];
        }

        $facturaFecha = $estado['FacturaFecha'] ?? null;
        $fechaDocu = (string)$estado['FechDocu'];
        $sumaPresupuesto = (float)$estado['SumaPresupuesto'];

        $esElegible = $facturaFecha !== null
            && $fechaDocu !== ''
            && (int)substr((string)$facturaFecha, 0, 4) < (int)substr($fechaDocu, 0, 4)
            && abs($sumaPresupuesto) >= 0.01;

        if (!$esElegible) {
            return [
                'ok' => false,
                'message' => "El documento {$codiDocu}-{$numeDocu} ya no cumple la condición (nota sobre factura de vigencia anterior con presupuesto ≠ 0) — puede que ya se haya corregido.",
            ];
        }

        [$codiAno, $codiMes] = [substr($fechaDocu, 0, 4), substr($fechaDocu, 5, 2)];

        // El período cerrado bloquea a cualquier usuario normal, pero NO al
        // superadmin — pedido explícito del usuario: esta es una corrección
        // de un huérfano ya identificado y validado (sección 3b), y el
        // superadmin necesita poder aplicarla aun en un período que nadie
        // más va a reabrir en SIHOS solo para esto.
        try {
            if (!PermisoService::isSuperAdminSession() && $repositorioLectura->isPresupuestoCerrado($codiAno, $codiMes)) {
                return [
                    'ok' => false,
                    'message' => "El módulo Presupuesto está cerrado en SIHOS para {$codiMes}/{$codiAno}. Reábralo en SIHOS antes de continuar.",
                ];
            }
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

        try {
            $filasBorradas = $repositorioEscritura->eliminarDetaPlan($codiDocu, $numeDocu);
        } catch (SihosOperacionEnCursoException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo borrar en SIHOS: ' . $e->getMessage()];
        }

        if ($filasBorradas === []) {
            return [
                'ok' => false,
                'message' => "No había filas de DetaPlan para {$codiDocu}-{$numeDocu} — puede que ya se hayan borrado.",
            ];
        }

        $this->registrarAuditoria(
            $empresaId,
            'DELETE',
            'sihos.DetaPlan',
            "{$codiInst}-{$codiDocu}-{$numeDocu}",
            json_encode($filasBorradas, JSON_UNESCAPED_UNICODE),
            null,
            "Eliminación manual de DetaPlan huérfano en SIHOS (nota sobre factura de vigencia anterior), documento {$codiDocu}-{$numeDocu}"
        );

        return [
            'ok' => true,
            'message' => "Se borraron " . count($filasBorradas) . " línea(s) de DetaPlan de {$codiDocu}-{$numeDocu}. Recuerde correr la reconstrucción presupuestal en SIHOS para {$codiMes}/{$codiAno}.",
        ];
    }

    /**
     * Elimina (o reduce) SOLO la porción del DetaPlan de una nota atribuible
     * a UNA factura de vigencia anterior específica — para notas
     * consolidadas que referencian varias facturas a la vez, algunas de
     * vigencia actual (cuyo presupuesto debe quedar intacto). A diferencia
     * de eliminarDetaPlan() (borra TODO el documento — correcto solo cuando
     * la nota tiene una única factura), aquí se identifica y toca solo la
     * línea de DetaPlan que le corresponde a $codiDocuFactura/$numeDocuFactura
     * (por su rubro propio), y si esa línea vale MÁS que la porción a
     * quitar (comparte rubro con otra(s) factura(s) de vigencia actual), se
     * reduce en vez de borrarse — decisión explícita del usuario.
     */
    public function eliminarPortionDetaPlan(
        int $empresaId,
        string $codiDocuNota,
        string $numeDocuNota,
        string $codiDocuFactura,
        string $numeDocuFactura
    ): array {
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
            $prefijosCartera = $repositorioLectura->resolvePrefijosCuentaCarteraPorTipoUsuario();
            $estado = $repositorioLectura->fetchEstadoParaEliminacionPortionDetaPlan(
                $codiDocuNota,
                $numeDocuNota,
                $codiDocuFactura,
                $numeDocuFactura,
                $prefijosCartera
            );
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo consultar SIHOS: ' . $e->getMessage()];
        }

        if ($estado === null) {
            return ['ok' => false, 'message' => "No se encontró la nota {$codiDocuNota}-{$numeDocuNota} en SIHOS."];
        }

        if ($estado['Anulado'] === 1) {
            return ['ok' => false, 'message' => 'La nota está anulada, no se modifica.'];
        }

        if (!$estado['FacturaExiste']) {
            return ['ok' => false, 'message' => "No se encontró la factura {$codiDocuFactura}-{$numeDocuFactura} en SIHOS."];
        }

        if ($estado['ValorAtribuido'] < 0.01) {
            return [
                'ok' => false,
                'message' => "La nota {$codiDocuNota}-{$numeDocuNota} ya no tiene contabilidad atribuible a {$codiDocuFactura}-{$numeDocuFactura} — puede que ya se haya corregido.",
            ];
        }

        if ($estado['ConsDeta'] === null) {
            return [
                'ok' => false,
                'message' => "No se pudo identificar con certeza cuál línea de DetaPlan de {$codiDocuNota}-{$numeDocuNota} corresponde a {$codiDocuFactura}-{$numeDocuFactura} "
                    . '(el rubro de la factura es ambiguo o no coincide con ninguna línea de la nota) — corríjalo manualmente en SIHOS.',
            ];
        }

        $nuevoValor = round((float)$estado['ValorLineaActual'] - $estado['ValorAtribuido'], 2);
        if ($nuevoValor < -0.01) {
            return [
                'ok' => false,
                'message' => "El valor atribuido a {$codiDocuFactura}-{$numeDocuFactura} (" . number_format($estado['ValorAtribuido'], 0, ',', '.')
                    . ') supera el de la línea de DetaPlan (' . number_format((float)$estado['ValorLineaActual'], 0, ',', '.')
                    . ') — no se continúa por seguridad, revise manualmente en SIHOS.',
            ];
        }
        $nuevoValor = max(0.0, $nuevoValor);

        [$codiAno, $codiMes] = [substr($estado['FechDocu'], 0, 4), substr($estado['FechDocu'], 5, 2)];

        // Mismo criterio que eliminarDetaPlan(): el período cerrado bloquea a
        // cualquier usuario normal, pero no al superadmin.
        try {
            if (!PermisoService::isSuperAdminSession() && $repositorioLectura->isPresupuestoCerrado($codiAno, $codiMes)) {
                return [
                    'ok' => false,
                    'message' => "El módulo Presupuesto está cerrado en SIHOS para {$codiMes}/{$codiAno}. Reábralo en SIHOS antes de continuar.",
                ];
            }
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

        try {
            $filaAntes = $repositorioEscritura->eliminarPortionDetaPlan(
                $codiDocuNota,
                $numeDocuNota,
                $estado['ConsDeta'],
                $nuevoValor,
                $this->usuaDigiResolver->resolver((int)($_SESSION['user_id'] ?? 0), $repositorioLectura)
            );
        } catch (SihosOperacionEnCursoException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo escribir en SIHOS: ' . $e->getMessage()];
        }

        if ($filaAntes === []) {
            return [
                'ok' => false,
                'message' => "La línea de DetaPlan de {$codiDocuNota}-{$numeDocuNota} ya no existe — puede que ya se haya corregido.",
            ];
        }

        $seElimino = $nuevoValor <= 0.01;
        $this->registrarAuditoria(
            $empresaId,
            $seElimino ? 'DELETE' : 'UPDATE',
            'sihos.DetaPlan',
            "{$codiInst}-{$codiDocuNota}-{$numeDocuNota}-{$estado['ConsDeta']}",
            json_encode($filaAntes, JSON_UNESCAPED_UNICODE),
            $seElimino ? null : json_encode(['Valor' => $nuevoValor], JSON_UNESCAPED_UNICODE),
            "Corrección de la porción de DetaPlan de {$codiDocuNota}-{$numeDocuNota} atribuible a la factura de vigencia anterior {$codiDocuFactura}-{$numeDocuFactura}"
        );

        $mensajeAccion = $seElimino
            ? "Se eliminó la línea de DetaPlan de {$codiDocuNota}-{$numeDocuNota}"
            : "Se redujo la línea de DetaPlan de {$codiDocuNota}-{$numeDocuNota} en " . number_format($estado['ValorAtribuido'], 0, ',', '.')
                . ' (queda en ' . number_format($nuevoValor, 0, ',', '.') . ')';

        return [
            'ok' => true,
            'message' => "{$mensajeAccion}, porción atribuible a {$codiDocuFactura}-{$numeDocuFactura}. Recuerde correr la reconstrucción presupuestal en SIHOS para {$codiMes}/{$codiAno}.",
        ];
    }

    /**
     * Construye la línea DetaPlan que le falta a una nota (NCF) sobre una
     * factura de la MISMA vigencia (sección 3 del reporte). Bloquea si el
     * mes propio de la nota está cerrado en Presupuesto — mismo principio
     * de seguridad que eliminarDetaPlan, sin buscar un mes alterno.
     */
    public function construirDetaPlan(int $empresaId, string $codiDocu, string $numeDocu): array
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
            $estado = $repositorioLectura->fetchEstadoParaConstruirDetaPlan($codiDocu, $numeDocu);
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo consultar SIHOS: ' . $e->getMessage()];
        }

        if ($estado === null) {
            return ['ok' => false, 'message' => "No se encontró el documento {$codiDocu}-{$numeDocu} en SIHOS, o no referencia ninguna factura."];
        }

        if ((int)$estado['Anulado'] === 1) {
            return ['ok' => false, 'message' => 'El documento está anulado, no se modifica.'];
        }

        if ((int)$estado['TieneDetaPlan'] > 0) {
            return [
                'ok' => false,
                'message' => "El documento {$codiDocu}-{$numeDocu} ya tiene DetaPlan — puede que ya se haya construido.",
            ];
        }

        $facturaTipoUsua = $estado['FacturaTipoUsua'] ?? null;
        $fechaDocu = (string)$estado['FechDocu'];
        [$codiAno, $codiMes] = [substr($fechaDocu, 0, 4), substr($fechaDocu, 5, 2)];

        if ($facturaTipoUsua === null || $facturaTipoUsua === '') {
            return ['ok' => false, 'message' => "La factura {$estado['FacturaCodiDocu']}-{$estado['FacturaNumeDocu']} no tiene tipo de usuario, no se puede resolver el rubro."];
        }

        try {
            $codiPlan = $repositorioLectura->resolveCodiPlanTipoUsua((string)$facturaTipoUsua, $codiAno);
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo resolver el rubro presupuestal en SIHOS: ' . $e->getMessage()];
        }

        if ($codiPlan === null) {
            return [
                'ok' => false,
                'message' => "No hay ningún rubro configurado en TipoUsua para el tipo de usuario {$facturaTipoUsua} (año ≤ {$codiAno}).",
            ];
        }

        try {
            if ($repositorioLectura->isPresupuestoCerrado($codiAno, $codiMes)) {
                return [
                    'ok' => false,
                    'message' => "El módulo Presupuesto está cerrado en SIHOS para {$codiMes}/{$codiAno}. Reábralo en SIHOS antes de continuar.",
                ];
            }
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

        $valor = (float)$estado['ValoTota'];

        try {
            $filaInsertada = $repositorioEscritura->construirDetaPlanNotaVigenciaActual(
                $codiDocu,
                $numeDocu,
                $codiAno,
                $codiPlan,
                $valor,
                $this->usuaDigiResolver->resolver((int)($_SESSION['user_id'] ?? 0), $repositorioLectura)
            );
        } catch (SihosOperacionEnCursoException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo construir el DetaPlan en SIHOS: ' . $e->getMessage()];
        }

        if ($filaInsertada === []) {
            return [
                'ok' => false,
                'message' => "El documento {$codiDocu}-{$numeDocu} ya tiene DetaPlan — puede que ya se haya construido.",
            ];
        }

        $this->registrarAuditoria(
            $empresaId,
            'INSERT',
            'sihos.DetaPlan',
            "{$codiInst}-{$codiDocu}-{$numeDocu}",
            null,
            json_encode($filaInsertada, JSON_UNESCAPED_UNICODE),
            "Construcción manual de DetaPlan faltante en SIHOS (nota sobre factura de vigencia actual), documento {$codiDocu}-{$numeDocu}, rubro {$codiPlan}"
        );

        return [
            'ok' => true,
            'message' => "Se construyó el DetaPlan de {$codiDocu}-{$numeDocu} (rubro {$codiPlan}, valor " . number_format($valor, 0, ',', '.') . "). Recuerde correr la reconstrucción presupuestal en SIHOS para {$codiMes}/{$codiAno}.",
        ];
    }

    /**
     * Registro manual en la auditoría de SAVID: AuditingPDO solo cubre
     * escrituras en la BD propia de SAVID, no éstas contra SIHOS. Mismo
     * formato de columnas que usa AuditService::persistLog() para que se
     * vea igual de consultable en ?url=auditoria.
     *
     * 'accion' es un ENUM('INSERT','UPDATE','DELETE') en SAVID (el mismo que
     * usa AuditingPDO) — no texto libre. La descripción específica va en
     * sql_resumen/tabla. Un valor fuera del ENUM lanza excepción de
     * truncamiento y corta la respuesta aunque la escritura en SIHOS ya se
     * haya completado (bug real ya encontrado y corregido aquí y en
     * SihosCancelacionCuentaService::registrarAuditoria()).
     */
    private function registrarAuditoria(
        int $empresaId,
        string $accion,
        string $tabla,
        string $registroId,
        ?string $datosAnteriores,
        ?string $datosNuevos,
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
            $datosAnteriores,
            $datosNuevos,
            $sqlResumen,
            $_SESSION['user_id'] ?? null,
            $empresaId,
            $_SESSION['sede_id'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $_SERVER['REQUEST_URI'] ?? null,
        ]);
    }
}
