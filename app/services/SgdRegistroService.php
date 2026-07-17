<?php

class SgdRegistroService
{
    private SgdRepository $repo;
    private SgdScopeService $scope;
    private SgdDocumentoCodigoService $codigoService;
    private SgdRegistroSnapshotService $snapshotService;

    public function __construct()
    {
        $this->repo = new SgdRepository();
        $this->scope = new SgdScopeService();
        $this->codigoService = new SgdDocumentoCodigoService();
        $this->snapshotService = new SgdRegistroSnapshotService();
    }

    /**
     * @return array<string, mixed>
     */
    public function getListPageData(array $query): array
    {
        $scope = $this->scope->buildScope($query);
        $empresaId = $scope['empresaId'] ?? null;
        $documentoId = isset($query['documento_id']) && ctype_digit((string)$query['documento_id'])
            ? (int)$query['documento_id']
            : 0;

        $registros = [];
        $documento = null;
        $codigoDisplay = '';

        if ($empresaId) {
            $registros = $this->repo->listRegistros($empresaId, $documentoId > 0 ? $documentoId : null);
            if ($documentoId > 0) {
                $documento = $this->repo->findDocumentoById($empresaId, $documentoId);
                if ($documento) {
                    $codigoDisplay = $this->codigoService->buildForDocument($empresaId, $documento, $this->repo);
                }
            }
        }

        return [
            'scope' => $scope,
            'empresaId' => $empresaId,
            'documentoId' => $documentoId,
            'documento' => $documento,
            'codigoDisplay' => $codigoDisplay,
            'registros' => $registros,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getDiligenciarPageData(array $query): array
    {
        $scope = $this->scope->buildScope($query);
        $empresaId = $scope['empresaId'] ?? null;
        $registroId = isset($query['id']) && ctype_digit((string)$query['id']) ? (int)$query['id'] : 0;

        $registro = null;
        $documento = null;
        $codigoDisplay = '';
        $esquema = ['version' => 2, 'arquetipo' => 'libre', 'bloques' => [], 'campos' => []];
        $datos = ['bloques' => [], 'campos_sueltos' => []];
        $editable = false;

        if ($empresaId && $registroId > 0) {
            $registro = $this->repo->findRegistroById($empresaId, $registroId);
            if ($registro) {
                $documento = $this->repo->findDocumentoById($empresaId, (int)$registro['documento_id']);
                if ($documento) {
                    $codigoDisplay = $this->codigoService->buildForDocument($empresaId, $documento, $this->repo);
                }
                $fv = $this->repo->findFormularioVersionById($empresaId, (int)$registro['formulario_version_id']);
                if ($fv) {
                    $formSvc = new SgdFormularioService();
                    $esquema = $formSvc->decodeEsquemaJson($fv['esquema_json'] ?? null);
                }
                $datos = $this->decodeJson($registro['datos_json'] ?? null);
                if (!isset($datos['bloques'])) {
                    $datos = ['bloques' => is_array($datos) ? $datos : [], 'campos_sueltos' => []];
                }
                $editable = in_array((string)($registro['estado'] ?? ''), ['borrador', 'en_firma'], true);
            }
        }

        return [
            'scope' => $scope,
            'empresaId' => $empresaId,
            'registroId' => $registroId,
            'registro' => $registro,
            'documento' => $documento,
            'codigoDisplay' => $codigoDisplay,
            'esquema' => $esquema,
            'esquemaJson' => json_encode($esquema, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP),
            'datos' => $datos,
            'datosJson' => json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP),
            'editable' => $editable,
        ];
    }

    /**
     * @return array{success: bool, message: string, id?: int}
     */
    public function crearRegistro(array $query, array $post): array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        if (!$this->scope->canAccessEmpresa($empresaId, $query)) {
            return ['success' => false, 'message' => 'Sin permiso para esta empresa.'];
        }

        $documentoId = (int)($post['documento_id'] ?? $query['documento_id'] ?? 0);
        if ($documentoId <= 0) {
            return ['success' => false, 'message' => 'Documento no indicado.'];
        }

        $documento = $this->repo->findDocumentoById($empresaId, $documentoId);
        if ($documento === null) {
            return ['success' => false, 'message' => 'Documento no encontrado.'];
        }

        $elab = new SgdElaboracionService();
        $modoDoc = $elab->resolveModoDocumento($empresaId, $documento);
        if (($modoDoc['modo_efectivo'] ?? '') === 'maestro') {
            return ['success' => false, 'message' => 'Solo formatos operativos (F/R) admiten registros diligenciados.'];
        }

        $formulario = $this->repo->findFormularioByDocumento($empresaId, $documentoId, 'operativo');
        if ($formulario === null) {
            return ['success' => false, 'message' => 'El documento no tiene plantilla operativa. Diseñe y publique la plantilla primero.'];
        }

        $formVersion = $this->repo->findFormularioVersionVigente($empresaId, (int)$formulario['id']);
        if ($formVersion === null) {
            return ['success' => false, 'message' => 'No hay plantilla operativa vigente. Publique una versión en el diseñador.'];
        }

        $docVersion = $this->repo->findDocumentoVersionVigente($empresaId, $documentoId);
        $esquema = $this->decodeJson($formVersion['esquema_json'] ?? null);
        $arquetipo = trim((string)($esquema['arquetipo'] ?? 'libre'));
        $datosIniciales = $this->seedDatosFromEsquema($esquema);

        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $titulo = trim((string)($post['titulo'] ?? ''));
        if ($titulo === '') {
            $titulo = $this->codigoService->buildForDocument($empresaId, $documento, $this->repo)
                . ' — ' . date('Y-m-d H:i');
        }

        try {
            $id = $this->repo->createRegistro($empresaId, [
                'documento_id' => $documentoId,
                'formulario_version_id' => (int)$formVersion['id'],
                'documento_version_id' => $docVersion ? (int)$docVersion['id'] : null,
                'expediente_id' => (int)($post['expediente_id'] ?? 0) ?: null,
                'arquetipo' => $arquetipo,
                'titulo' => $titulo,
                'datos_json' => $datosIniciales,
                'created_by' => $userId,
            ]);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        return [
            'success' => true,
            'message' => 'Registro creado. Puede diligenciarlo ahora.',
            'id' => $id,
        ];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function guardarDatos(array $post, array $query): array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $registroId = (int)($post['registro_id'] ?? 0);
        if ($registroId <= 0) {
            return ['success' => false, 'message' => 'Registro no válido.'];
        }

        $registro = $this->repo->findRegistroById($empresaId, $registroId);
        if ($registro === null) {
            return ['success' => false, 'message' => 'Registro no encontrado.'];
        }

        if (!in_array((string)($registro['estado'] ?? ''), ['borrador', 'en_firma'], true)) {
            return ['success' => false, 'message' => 'El registro no está en edición.'];
        }

        $raw = trim((string)($post['datos_json'] ?? ''));
        $datos = json_decode($raw, true);
        if (!is_array($datos)) {
            return ['success' => false, 'message' => 'Datos del formulario inválidos.'];
        }

        if (!isset($datos['bloques']) || !is_array($datos['bloques'])) {
            $datos = ['bloques' => $datos, 'campos_sueltos' => []];
        }

        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $titulo = trim((string)($post['titulo'] ?? ''));
        $this->repo->saveRegistroDatos($empresaId, $registroId, $datos, $userId, $titulo !== '' ? $titulo : null);

        return ['success' => true, 'message' => 'Registro guardado.'];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function cerrarRegistro(array $post, array $query): array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $registroId = (int)($post['registro_id'] ?? 0);
        if ($registroId <= 0) {
            return ['success' => false, 'message' => 'Registro no válido.'];
        }

        $registro = $this->repo->findRegistroById($empresaId, $registroId);
        if ($registro === null) {
            return ['success' => false, 'message' => 'Registro no encontrado.'];
        }

        if ((string)($registro['estado'] ?? '') === 'cerrado') {
            return ['success' => false, 'message' => 'El registro ya está cerrado.'];
        }

        $fv = $this->repo->findFormularioVersionById($empresaId, (int)$registro['formulario_version_id']);
        $esquema = $fv ? $this->decodeJson($fv['esquema_json'] ?? null) : ['bloques' => [], 'campos' => []];
        $datos = $this->decodeJson($registro['datos_json'] ?? null);

        $publicado = $this->snapshotService->buildContenidoPublicado($datos, $esquema);
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

        $this->repo->cerrarRegistro($empresaId, $registroId, $publicado, $userId);
        $this->syncCompromisos($empresaId, $registroId, $datos, $userId);

        return ['success' => true, 'message' => 'Registro cerrado. Contenido publicado congelado.'];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function anularRegistro(array $post, array $query): array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $registroId = (int)($post['registro_id'] ?? 0);
        if ($registroId <= 0) {
            return ['success' => false, 'message' => 'Registro no válido.'];
        }

        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $this->repo->setRegistroEstado($empresaId, $registroId, 'anulado', $userId);

        return ['success' => true, 'message' => 'Registro anulado.'];
    }

    /**
     * @param array<string, mixed> $esquema
     * @return array{bloques: array<string, mixed>, campos_sueltos: list<mixed>}
     */
    private function seedDatosFromEsquema(array $esquema): array
    {
        $esquemaSvc = new SgdFormularioEsquemaService();
        $bloques = [];
        $camposSueltos = [];
        foreach ($esquemaSvc->elementosActivos($esquema) as $el) {
            if (($el['tipo'] ?? '') === 'bloque') {
                $codigo = (string)($el['seccion_codigo'] ?? '');
                if ($codigo === '') {
                    continue;
                }
                $widget = (string)($el['widget'] ?? 'grupo_campos');
                $secCodigo = (string)($el['seccion_codigo'] ?? '');
                if (SgdChecklistConfigService::isChecklistBlock($secCodigo)) {
                    $def = is_array($el['definicion'] ?? null) ? $el['definicion'] : [];
                    $config = is_array($el['config'] ?? null)
                        ? SgdChecklistConfigService::normalizeConfig($el['config'], $def)
                        : SgdChecklistConfigService::buildDefaultConfig($def);
                    $bloques[$codigo] = ($config['modo'] ?? '') === 'filas_fijas' ? (object)[] : [];
                } elseif ($widget === 'tabla_repetible' || $widget === 'lista_repetible') {
                    $bloques[$codigo] = [];
                } elseif ($widget === 'grupo_campos') {
                    $bloques[$codigo] = [];
                } else {
                    $bloques[$codigo] = null;
                }
            } elseif (($el['tipo'] ?? '') === 'campo') {
                $id = (string)($el['id'] ?? '');
                if ($id !== '') {
                    $camposSueltos[] = ['id' => $id, 'valor' => ''];
                }
            }
        }

        return ['bloques' => $bloques, 'campos_sueltos' => $camposSueltos];
    }

    /**
     * @param array<string, mixed> $datos
     */
    private function syncCompromisos(int $empresaId, int $registroId, array $datos, ?int $userId): void
    {
        $filas = null;
        foreach ($datos['bloques'] ?? [] as $codigo => $valor) {
            if (str_contains(strtolower($codigo), 'compromiso') && is_array($valor)) {
                $filas = $valor;
                break;
            }
        }
        if ($filas === null) {
            return;
        }

        $this->repo->softDeleteCompromisosByRegistro($empresaId, $registroId, $userId);
        $orden = 0;
        foreach ($filas as $fila) {
            if (!is_array($fila)) {
                continue;
            }
            $desc = trim((string)($fila['compromiso'] ?? $fila['descripcion'] ?? ''));
            if ($desc === '') {
                continue;
            }
            $this->repo->insertRegistroCompromiso($empresaId, $registroId, [
                'orden' => $orden++,
                'descripcion' => $desc,
                'fecha_limite' => $this->normalizeDate($fila['plazo'] ?? $fila['fecha_limite'] ?? null),
                'observaciones' => trim((string)($fila['observaciones'] ?? '')),
                'responsable_usuario_id' => $this->parsePositiveInt($fila['responsable_usuario_id'] ?? null),
                'responsable_terceroidentificacion_id' => $this->parsePositiveInt(
                    $fila['responsable_terceroidentificacion_id'] ?? null
                ),
                'created_by' => $userId,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeDate(mixed $value): ?string
    {
        $s = trim((string)($value ?? ''));
        if ($s === '') {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
    }

    private function parsePositiveInt(mixed $value): ?int
    {
        $n = (int)$value;

        return $n > 0 ? $n : null;
    }
}
