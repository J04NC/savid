<?php

/**
 * Orquesta el procesamiento (escritura real en SIHOS) de UNA fila de
 * resultado o UNA solicitud candidata a la vez — usado tanto por el botón
 * "Procesar seleccionados" (AJAX, una petición por fila) como por el script
 * CLI de cron (scripts/sihos_interlab_procesar.php), sin duplicar lógica.
 *
 * Puerto unificado de docs/sihos/interlabunion/interlabu/interfaz_resultados.php
 * e interfaz_solicitudes.php — ver SihosInterlabWriteRepository para el
 * detalle de qué se corrigió/condicionó frente al script original.
 *
 * Excepción deliberada a la regla general de solo-lectura clínica en SIHOS
 * (ver memoria sihos-readonly) — autorizada explícitamente por el usuario
 * para esta funcionalidad puntual.
 */
class SihosInterlabProcesarService
{
    private SihosEmpresaConfigRepository $configRepository;

    public function __construct()
    {
        $this->configRepository = new SihosEmpresaConfigRepository();
    }

    /**
     * @return array{ok:bool, error?:string, repo?:SihosInterlabWriteRepository, codiInst?:string}
     */
    private function prepararRepositorio(int $empresaId): array
    {
        $fila = $this->configRepository->findByEmpresaId($empresaId);
        if ($fila === null || $fila['host'] === '' || $fila['base_datos'] === '') {
            return ['ok' => false, 'error' => 'Esta empresa no tiene conexión a SIHOS configurada.'];
        }

        $codiInst = trim((string)($fila['codi_inst'] ?? ''));
        $usuarioInterlab = trim((string)($fila['usuario_interlab'] ?? ''));
        if ($codiInst === '' || $usuarioInterlab === '' || empty($fila['password_interlab_cifrado'])) {
            return [
                'ok' => false,
                'error' => 'Configure la credencial de escritura de Interfaz Laboratorio de esta empresa en Conexión SIHOS antes de procesar.',
            ];
        }

        $repo = new SihosInterlabWriteRepository([
            'host' => $fila['host'],
            'port' => (string)$fila['puerto'],
            'database' => $fila['base_datos'],
            'username' => $usuarioInterlab,
            'password' => SihosCredentialCipher::decrypt($fila['password_interlab_cifrado']),
            'charset' => $fila['charset'],
            'codiInst' => $codiInst,
        ]);

        return ['ok' => true, 'repo' => $repo, 'codiInst' => $codiInst];
    }

    // =====================================================================
    // RESULTADOS
    // =====================================================================

    /**
     * @return array{ok:bool, message:string, enviada?:int}
     */
    public function procesarResultadoUno(int $empresaId, int $id): array
    {
        $prep = $this->prepararRepositorio($empresaId);
        if (!$prep['ok']) {
            return ['ok' => false, 'message' => $prep['error']];
        }
        $repo = $prep['repo'];
        $codiInst = $prep['codiInst'];

        try {
            $resultado = $repo->conBloqueo('resultado:' . $id, function () use ($repo, $codiInst, $id): array {
                return $this->procesarResultadoBloqueado($repo, $codiInst, $id);
            });
        } catch (SihosOperacionEnCursoException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (PDOException|RuntimeException $e) {
            return ['ok' => false, 'message' => 'Error al procesar en SIHOS: ' . $e->getMessage()];
        }

        if ($resultado['escritura'] ?? true) {
            $this->registrarAuditoria($empresaId, $codiInst, 'Interfaz_resultados_Roche', (string)$id, $resultado);
        }

        unset($resultado['escritura']);

        return $resultado;
    }

    /** @see procesarResultadoUno (ya con el bloqueo tomado) */
    private function procesarResultadoBloqueado(SihosInterlabWriteRepository $repo, string $codiInst, int $id): array
    {
        $fila = $repo->fetchResultadoPendiente($id);
        if ($fila === null) {
            return ['ok' => false, 'message' => 'La fila ya no existe en Interfaz_resultados_Roche.'];
        }

        // ConsAdmi/ConsOrde crudos, TAL COMO los envía Roche — nunca se
        // reasignan; se usan para cualquier consulta contra las tablas de
        // interfaz (lección del bug depurado en esta misma sesión: el
        // ConsAdmi ya resuelto de SIHOS no significa nada para esas tablas).
        $consAdmiRoche = (string)$fila['ConsAdmi'];
        $consOrdeRoche = (string)$fila['ConsOrde'];
        $item = (string)$fila['Item'];
        $codiPrueCups = (string)$fila['CodiPrue'];
        $codAnalito = (string)$fila['CodAnalito'];
        $historia = (string)$fila['Historia'];
        $usuarioValida = (string)$fila['Usuario_Valida'];

        $usuaDigi = $repo->resolverLoginUsuario($usuarioValida);

        [$valor, $indiAdic] = $this->calcularValorEIndiAdicInicial($fila);

        $datosOrden = $repo->resolverConsAdmiYDatosOrden($codiInst, $consAdmiRoche, $consOrdeRoche, $item, $codiPrueCups, $historia);

        if ($datosOrden['consAdmi'] === '') {
            $repo->actualizarEstadoResultado($id, 7, 0);

            return ['ok' => true, 'message' => 'Paciente/liquidación no relacionada — Enviada=7.', 'enviada' => 7];
        }

        $numeUsuaAdmision = $repo->fetchNumeUsuaAdmision($codiInst, $datosOrden['consAdmi']);
        if ($historia !== (string)$numeUsuaAdmision) {
            return ['ok' => true, 'escritura' => false, 'message' => 'La historia no coincide con la admisión resuelta — sin cambios (igual que el script original).'];
        }

        $consAdmi = $datosOrden['consAdmi'];
        $consOrde = $datosOrden['consOrde'];
        $codiProc = (string)$datosOrden['codiProc'];

        $hoja = $repo->resolverHojaProc($codiInst, $consAdmi, $consOrde, $item, $codiProc);
        $consHoPr = $hoja['consHoPr'];

        // Homologación virtual "9999" (condicional — solo actúa si esta
        // empresa tiene esas filas en su catálogo, ver docblock del repo).
        $descripcion9999 = $repo->resolverDescripcionAgrupada9999($codiInst, $codiPrueCups, $consAdmiRoche, $consOrdeRoche, $item);
        if ($descripcion9999 !== null) {
            $indiAdic = $descripcion9999;
        }

        if (!$hoja['existia']) {
            if ($repo->existeHomologacionParaCups($codiPrueCups)) {
                $repo->crearHojaProc([
                    'codiInst' => $codiInst, 'consAdmi' => $consAdmi, 'consHoPr' => $consHoPr, 'codiModu' => '10',
                    'codiProc' => $codiProc, 'codiFina' => $datosOrden['codiFina'], 'indiAdic' => $indiAdic, 'usuaDigi' => $usuaDigi,
                    'cantProc' => '1', 'consOrde' => $consOrde, 'item' => $item, 'codiDocu' => $datosOrden['codiDocu'],
                    'numeLiqu' => $datosOrden['numeLiqu'], 'consDeFa' => $datosOrden['consDeFa'], 'cantFact' => $datosOrden['cantFact'],
                    'codiServ' => $datosOrden['codiServ'], 'codiDiag' => $datosOrden['codiDiag'],
                    'codiRel1' => $datosOrden['codiRel1'], 'codiRel2' => $datosOrden['codiRel2'], 'codiRel3' => $datosOrden['codiRel3'],
                ]);
                $this->marcarLiquidacionUOrden($repo, $codiInst, $datosOrden);
            }
        } else {
            $repo->actualizarHojaProc($codiInst, $consAdmi, $consHoPr, $codiProc, '10', $usuaDigi);
            if ($indiAdic !== '') {
                $repo->actualizarHojaProc($codiInst, $consAdmi, $consHoPr, $codiProc, '10', $usuaDigi, $indiAdic);
                $repo->actualizarEstadoResultado($id, 1, 0);
            }
            $this->marcarLiquidacionUOrden($repo, $codiInst, $datosOrden);
        }

        $codiPrueHomologado = $repo->resolverCodiPrueHomologado($codiPrueCups, $codAnalito);
        if ($codiPrueHomologado === null) {
            $repo->actualizarEstadoResultado($id, 5, 0);

            return ['ok' => true, 'message' => 'Analito no homologado — Enviada=5.', 'enviada' => 5];
        }

        $consPrue = (string)$id;
        if ($repo->existeDetaPrue($codiInst, $consAdmi, $consHoPr, $codiPrueHomologado, $consPrue)) {
            $repo->actualizarDetaPrue($codiInst, $consAdmi, $consHoPr, $consPrue, $valor, $usuaDigi);
            $repo->actualizarEstadoResultado($id, 1, 0);

            return ['ok' => true, 'message' => 'DetaPrue actualizado — Enviada=1.', 'enviada' => 1];
        }

        if ($repo->existeCodiPrueParametrizado($codiProc, $codiPrueHomologado)) {
            $repo->crearDetaPrue($codiInst, $consAdmi, $consHoPr, $consPrue, $codiProc, $codiPrueHomologado, $valor, $usuaDigi, (string)$fila['OrdenLIS']);
            $repo->actualizarEstadoResultado($id, 1, 0);

            return ['ok' => true, 'message' => 'DetaPrue creado — Enviada=1.', 'enviada' => 1];
        }

        $envi = ($codiPrueHomologado === '9999') ? 3 : 6;
        $repo->actualizarEstadoResultado($id, $envi, 0);

        return [
            'ok' => true,
            'message' => $envi === 3
                ? 'Homologado a 9999 (agrupado en IndiAdic, sin fila individual en DetaPrue) — Enviada=3.'
                : 'CodiPrue no parametrizado para este CodiProc — Enviada=6.',
            'enviada' => $envi,
        ];
    }

    private function marcarLiquidacionUOrden(SihosInterlabWriteRepository $repo, string $codiInst, array $datosOrden): void
    {
        if ($datosOrden['liquidado']) {
            $repo->marcarLiquidacionRealizada($codiInst, (string)$datosOrden['numeLiqu'], (string)$datosOrden['item'], (string)$datosOrden['cantFact'], (string)$datosOrden['consAdmi']);

            return;
        }
        $repo->marcarOrdenRealizada($codiInst, (string)$datosOrden['consAdmi'], (string)$datosOrden['consOrde'], (string)$datosOrden['item'], (string)$datosOrden['cantSumi']);
    }

    /**
     * @return array{0:string,1:string} [Valor, IndiAdic]
     */
    private function calcularValorEIndiAdicInicial(array $fila): array
    {
        $valor = (string)$fila['Resultado'];
        $indiAdic = '';
        $antibiotico = trim((string)($fila['Antibiotico'] ?? ''));

        if ($antibiotico !== '') {
            $valor = $fila['CMI'] . '&nbsp;&nbsp;&nbsp;' . $fila['Sensibilidad'] . '**%**' . $fila['Antibiotico'];
            $indiAdic = $fila['Descripcion_Examen'] . '<br>' . $fila['Comentario'] . '<br><br>' . $fila['MicroOrganismo'] . '<br>' . $fila['ComentarioMicroorg'];
        } elseif ($valor === 'MEMO' || $valor === 'COMENTA') {
            if ((string)$fila['Comentario'] !== '') {
                $valor = (string)$fila['Comentario'];
            }
        } elseif ((string)$fila['Comentario'] !== '') {
            $valor = $fila['Resultado'] . '%%*%%' . $fila['Comentario'];
        }

        if ((string)$fila['Comentario'] !== '') {
            $indiAdic = (string)$fila['Comentario'];
        }

        return [$valor, $indiAdic];
    }

    // =====================================================================
    // SOLICITUDES
    // =====================================================================

    /**
     * @param array{CodiInst:string,ConsAdmi:string,ConsOrde:string,Item:string,CodiModu:string,CodiProc:string,ObseProc:?string,ConsDeFa:?string,FechDigi:string,HoraDigi:string,UsuaDigi:string,TipoDocu:?string,NumeUsua:string,NumeLiqu:?string,TipoOrde:?string,TipoInterfaz:string} $candidata
     * @return array{ok:bool, message:string}
     */
    public function procesarSolicitudUna(int $empresaId, array $candidata): array
    {
        $prep = $this->prepararRepositorio($empresaId);
        if (!$prep['ok']) {
            return ['ok' => false, 'message' => $prep['error']];
        }
        $repo = $prep['repo'];
        $codiInst = $prep['codiInst'];

        $esLiquidacion = $candidata['TipoInterfaz'] === '2';
        $consAdmiClave = $esLiquidacion ? (string)$candidata['NumeLiqu'] : (string)$candidata['ConsAdmi'];
        $consOrdeClave = $esLiquidacion ? (string)$candidata['NumeLiqu'] : (string)$candidata['ConsOrde'];
        $itemClave = $esLiquidacion ? (string)$candidata['ConsDeFa'] : (string)$candidata['Item'];
        $claveLock = 'solicitud:' . $consAdmiClave . ':' . $consOrdeClave . ':' . $itemClave;

        try {
            $resultado = $repo->conBloqueo($claveLock, function () use ($repo, $codiInst, $candidata, $consAdmiClave, $consOrdeClave, $itemClave): array {
                if ($repo->existeSolicitudInterfaz($codiInst, $consAdmiClave, $consOrdeClave, $itemClave)) {
                    return ['ok' => true, 'escritura' => false, 'message' => 'Ya estaba registrada en la interfaz — sin cambios.'];
                }

                $datos = $repo->enriquecerCandidataSolicitud($candidata);
                if ($datos === null) {
                    return ['ok' => true, 'escritura' => false, 'message' => 'No es un tipo de servicio de laboratorio — se omite.'];
                }

                $repo->insertarSolicitud($datos);

                return ['ok' => true, 'message' => 'Solicitud registrada en la interfaz.'];
            });
        } catch (SihosOperacionEnCursoException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (PDOException|RuntimeException $e) {
            return ['ok' => false, 'message' => 'Error al procesar en SIHOS: ' . $e->getMessage()];
        }

        if ($resultado['escritura'] ?? true) {
            $this->registrarAuditoria($empresaId, $codiInst, 'Interfaz_solicitudes_SIHOS', "{$consAdmiClave}-{$consOrdeClave}-{$itemClave}", $resultado);
        }

        unset($resultado['escritura']);

        return $resultado;
    }

    // =====================================================================
    // HOMOLOGACIÓN (CRUD del catálogo — no es dato clínico de paciente,
    // pero usa la misma credencial/conexión de escritura de este módulo)
    // =====================================================================

    /** @return array{ok:bool, message:string} */
    public function crearHomologacion(int $empresaId, string $codiCups, string $codiPrue, string $analito): array
    {
        return $this->conHomologacion($empresaId, function (SihosInterlabWriteRepository $repo) use ($codiCups, $codiPrue, $analito): array {
            $repo->crearHomologacion($codiCups, $codiPrue, $analito);

            return ['ok' => true, 'message' => 'Homologación creada.'];
        }, 'INSERT', "{$codiCups}-{$codiPrue}-{$analito}", ['CodiCups' => $codiCups, 'CodiPrue' => $codiPrue, 'Analito' => $analito]);
    }

    /** @return array{ok:bool, message:string} */
    public function actualizarHomologacion(int $empresaId, string $codiCupsAnterior, string $codiPrueAnterior, string $analitoAnterior, string $codiCups, string $codiPrue, string $analito): array
    {
        return $this->conHomologacion(
            $empresaId,
            function (SihosInterlabWriteRepository $repo) use ($codiCupsAnterior, $codiPrueAnterior, $analitoAnterior, $codiCups, $codiPrue, $analito): array {
                $repo->actualizarHomologacion($codiCupsAnterior, $codiPrueAnterior, $analitoAnterior, $codiCups, $codiPrue, $analito);

                return ['ok' => true, 'message' => 'Homologación actualizada.'];
            },
            'UPDATE',
            "{$codiCupsAnterior}-{$codiPrueAnterior}-{$analitoAnterior}",
            ['anterior' => [$codiCupsAnterior, $codiPrueAnterior, $analitoAnterior], 'nuevo' => [$codiCups, $codiPrue, $analito]]
        );
    }

    /** @return array{ok:bool, message:string} */
    public function eliminarHomologacion(int $empresaId, string $codiCups, string $codiPrue, string $analito): array
    {
        return $this->conHomologacion($empresaId, function (SihosInterlabWriteRepository $repo) use ($codiCups, $codiPrue, $analito): array {
            $repo->eliminarHomologacion($codiCups, $codiPrue, $analito);

            return ['ok' => true, 'message' => 'Homologación eliminada.'];
        }, 'DELETE', "{$codiCups}-{$codiPrue}-{$analito}", ['CodiCups' => $codiCups, 'CodiPrue' => $codiPrue, 'Analito' => $analito]);
    }

    /**
     * @param callable(SihosInterlabWriteRepository):array{ok:bool,message:string} $accion
     * @return array{ok:bool, message:string}
     */
    private function conHomologacion(int $empresaId, callable $accion, string $tipoAuditoria, string $registroId, array $datos): array
    {
        $prep = $this->prepararRepositorio($empresaId);
        if (!$prep['ok']) {
            return ['ok' => false, 'message' => $prep['error']];
        }

        try {
            $resultado = $prep['repo']->conBloqueo('homologacion:' . $registroId, static fn () => $accion($prep['repo']));
        } catch (SihosOperacionEnCursoException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'Error al escribir en SIHOS: ' . $e->getMessage()];
        }

        $this->registrarAuditoria($empresaId, $prep['codiInst'], 'Interfaz_homologacion_lab', $registroId, $datos, $tipoAuditoria);

        return $resultado;
    }

    /**
     * Registro manual en la auditoría de SAVID: estas escrituras van contra
     * SIHOS (BD externa), AuditingPDO no las cubre. Mismo patrón que
     * SihosCancelacionCuentaService::registrarAuditoria().
     */
    private function registrarAuditoria(int $empresaId, string $codiInst, string $tabla, string $registroId, array $resultado, string $accionEnum = 'UPDATE'): void
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

        $stmt->execute([
            $accionEnum,
            'sihos.' . $tabla,
            "{$codiInst}-{$registroId}",
            json_encode($resultado, JSON_UNESCAPED_UNICODE),
            'Procesamiento de Interfaz Laboratorio (' . $tabla . ') para CodiInst ' . $codiInst,
            $_SESSION['user_id'] ?? null,
            $empresaId,
            $_SESSION['sede_id'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $_SERVER['REQUEST_URI'] ?? null,
        ]);
    }
}
