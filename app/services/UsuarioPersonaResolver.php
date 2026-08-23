<?php

/**
 * Resolución única de tercero + identificación para guardado usuario (spec §6).
 */
class UsuarioPersonaResolver
{
    private PDO $pdo;
    private UsuarioPersonaLinkService $personaLink;

    public function __construct(?PDO $pdo = null)
    {
        if ($pdo !== null) {
            $this->pdo = $pdo;
        } else {
            $this->pdo = (new Database())->connect();
        }

        $this->personaLink = new UsuarioPersonaLinkService($this->pdo);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $usuarioColumnNames
     */
    public function resolveAndPersist(array &$data, UsuarioSaveContext $ctx, array $usuarioColumnNames): void
    {
        if (!$this->tableExists('tercero')) {
            return;
        }

        $nombres = trim((string)($data['nombres'] ?? ''));
        $apellidos = trim((string)($data['apellidos'] ?? ''));

        // nombres/apellidos van a `tercero`, que los exige en mayúscula. Este
        // resolver corre fuera del bucle de normalización de CrudService::save(),
        // así que sin esto la regla dependía solo del JS del navegador.
        $upper = UppercaseColumnService::applyToMap($this->pdo, 'tercero', [
            'nombres' => $nombres,
            'apellidos' => $apellidos,
        ]);
        $nombres = (string)$upper['nombres'];
        $apellidos = (string)$upper['apellidos'];

        $email = trim((string)($data['email'] ?? ''));
        $foto = trim((string)($data['foto_ruta'] ?? ''));
        $firma = trim((string)($data['firma_ruta'] ?? ''));
        $tipoDoc = isset($data['tipodocumento_id']) && $data['tipodocumento_id'] !== ''
            ? (int)$data['tipodocumento_id']
            : null;
        $numero = trim((string)($data['numero_documento'] ?? ''));
        $dv = $this->resolveDocumentoDv($data, $tipoDoc, $numero);

        $usuarioId = $ctx->usuarioId;
        $tid = $this->resolveTerceroId($data, $usuarioColumnNames, $ctx);
        $insertAttempted = false;

        if ($tid <= 0) {
            $tid = $this->insertTercero($data, $nombres, $apellidos, $email, $foto, $firma);
            $insertAttempted = true;
        } else {
            $this->updateTercero($tid, $nombres, $apellidos, $email, $foto, $firma, $data);
        }

        if ($tid <= 0 || !$this->terceroRowExists($tid)) {
            unset($data['tercero_id'], $data['terceroidentificacion_id']);
            $tid = $this->resolveTerceroIdByDocumento($data);
        }

        if ($tid <= 0 || !$this->terceroRowExists($tid)) {
            if ($insertAttempted && $tid > 0) {
                UsuarioSaveMessages::throwJson([
                    'general' => UsuarioSaveMessages::PERSONA_NOT_FOUND
                        . ' Detalle: se creó la persona (id interno '
                        . $tid
                        . ') pero el sistema no puede leerla; contacte al administrador de base de datos.',
                ]);
            }
            if (!$insertAttempted) {
                $tid = $this->insertTercero($data, $nombres, $apellidos, $email, $foto, $firma);
                $insertAttempted = true;
            }
        }

        if ($tid <= 0 || !$this->terceroRowExists($tid)) {
            $this->failPersonaNotFound($data, $tid, $tipoDoc, $numero);
        }

        $identId = $this->syncIdentificacion($data, $tid, $tipoDoc, $numero, $dv);

        if ($identId > 0) {
            $this->personaLink->assignPersonaLink($data, $identId, $usuarioColumnNames);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $usuarioColumnNames
     */
    private function resolveTerceroId(array &$data, array $usuarioColumnNames, UsuarioSaveContext $ctx): int
    {
        if ($ctx->isEdicion() && $ctx->usuarioId !== null) {
            $this->hydratePersonaLinkFromUsuario($data, $ctx->usuarioId, $usuarioColumnNames);
        }

        if ($ctx->isAlta()) {
            $tidByDoc = $this->resolveTerceroIdByDocumento($data);
            if ($tidByDoc > 0) {
                return $tidByDoc;
            }

            unset($data['tercero_id'], $data['terceroidentificacion_id']);
        }

        $this->acceptValidPostPersonaLinks($data, $usuarioColumnNames);

        $tid = isset($data['tercero_id']) && $data['tercero_id'] !== '' && $data['tercero_id'] !== null
            ? (int)$data['tercero_id']
            : 0;

        if ($tid <= 0) {
            $tid = $this->personaLink->resolveTerceroIdFromData($data, $usuarioColumnNames) ?? 0;
        }

        if ($tid > 0 && !$this->terceroRowExists($tid)) {
            $tid = 0;
            unset($data['tercero_id']);
            if ($this->personaLink->usesIdentificacionLink($usuarioColumnNames)) {
                unset($data['terceroidentificacion_id']);
            }
        }

        if ($tid > 0 && $this->personaLink->usesIdentificacionLink($usuarioColumnNames)) {
            $identId = $this->personaLink->linkValueFromData($data, 'terceroidentificacion_id');
            if ($identId !== null && $identId > 0 && $this->terceroidentificacionRowExists($identId, $tid)) {
                $data['terceroidentificacion_id'] = $identId;
            } else {
                unset($data['terceroidentificacion_id']);
            }
        }

        return $tid;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $usuarioColumnNames
     */
    private function acceptValidPostPersonaLinks(array &$data, array $usuarioColumnNames): void
    {
        $tid = isset($data['tercero_id']) && $data['tercero_id'] !== '' && $data['tercero_id'] !== null
            ? (int)$data['tercero_id']
            : 0;

        if ($tid > 0 && !$this->terceroRowExists($tid)) {
            unset($data['tercero_id'], $data['terceroidentificacion_id']);

            return;
        }

        if (!$this->personaLink->usesIdentificacionLink($usuarioColumnNames)) {
            return;
        }

        $identId = $this->personaLink->linkValueFromData($data, 'terceroidentificacion_id');
        if ($identId === null || $identId <= 0) {
            return;
        }

        if ($tid <= 0 || !$this->terceroidentificacionRowExists($identId, $tid)) {
            unset($data['terceroidentificacion_id']);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $usuarioColumnNames
     */
    private function hydratePersonaLinkFromUsuario(array &$data, int $usuarioId, array $usuarioColumnNames): void
    {
        $linkColumn = $this->personaLink->personaLinkColumn($usuarioColumnNames);
        if ($linkColumn === null) {
            return;
        }

        $formLink = $this->personaLink->linkValueFromData($data, $linkColumn);
        if ($formLink !== null && $this->formPersonaLinkIsValid($data, $formLink, $linkColumn, $usuarioColumnNames)) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'SELECT `' . str_replace('`', '', $linkColumn) . '` FROM `usuario` WHERE `id` = ? LIMIT 1'
        );
        $stmt->execute([$usuarioId]);
        $storedLink = $stmt->fetchColumn();
        if ($storedLink === false || $storedLink === null || (int)$storedLink <= 0) {
            return;
        }

        $data[$linkColumn] = (int)$storedLink;

        if ($linkColumn === 'terceroidentificacion_id' && in_array('tercero_id', $usuarioColumnNames, true)) {
            $tid = $this->personaLink->resolveTerceroId((int)$storedLink, $linkColumn);
            if ($tid !== null && $tid > 0 && $this->terceroRowExists($tid)) {
                $data['tercero_id'] = $tid;
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $usuarioColumnNames
     */
    private function formPersonaLinkIsValid(array $data, int $formLink, string $linkColumn, array $usuarioColumnNames): bool
    {
        if ($linkColumn === 'terceroidentificacion_id') {
            $tidFromIdent = $this->personaLink->resolveTerceroId($formLink, $linkColumn);

            return $tidFromIdent !== null
                && $tidFromIdent > 0
                && $this->terceroRowExists($tidFromIdent);
        }

        if ($linkColumn === 'tercero_id') {
            return $this->terceroRowExists($formLink);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveTerceroIdByDocumento(array &$data): int
    {
        $tipoDoc = isset($data['tipodocumento_id']) && $data['tipodocumento_id'] !== ''
            ? (int)$data['tipodocumento_id']
            : 0;
        $numero = trim((string)($data['numero_documento'] ?? ''));

        if ($tipoDoc <= 0 || $numero === '' || !$this->tableExists('terceroidentificacion')) {
            return 0;
        }

        if (!$this->tipodocumentoRowExists($tipoDoc)) {
            return 0;
        }

        $sql = '
            SELECT ti.id AS ident_id, ti.tercero_id
            FROM terceroidentificacion ti
            INNER JOIN tercero t ON t.id = ti.tercero_id
            WHERE ti.tipodocumento_id = ? AND TRIM(ti.numero) = TRIM(?)
        ';
        if (SoftDeleteService::supports($this->pdo, 'tercero')) {
            $sql .= SoftDeleteService::sqlAndNotDeleted($this->pdo, 'tercero', 't');
        }
        $sql .= ' ORDER BY ti.principal DESC, ti.id ASC LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tipoDoc, $numero]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return $this->resolveTerceroIdByDocumentoIncludingSoftDeleted($data, $tipoDoc, $numero);
        }

        $tid = (int)($row['tercero_id'] ?? 0);
        $identId = (int)($row['ident_id'] ?? 0);

        if ($tid <= 0 || !$this->terceroRowExists($tid)) {
            return $this->resolveTerceroIdByDocumentoIncludingSoftDeleted($data, $tipoDoc, $numero);
        }

        $data['tercero_id'] = $tid;
        if ($identId > 0) {
            $data['terceroidentificacion_id'] = $identId;
        }

        return $tid;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveTerceroIdByDocumentoIncludingSoftDeleted(array &$data, int $tipoDoc, string $numero): int
    {
        $stmt = $this->pdo->prepare('
            SELECT ti.id AS ident_id, ti.tercero_id, t.deleted_at
            FROM terceroidentificacion ti
            INNER JOIN tercero t ON t.id = ti.tercero_id
            WHERE ti.tipodocumento_id = ? AND TRIM(ti.numero) = TRIM(?)
            ORDER BY ti.principal DESC, ti.id ASC
            LIMIT 1
        ');
        $stmt->execute([$tipoDoc, $numero]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return 0;
        }

        $tid = (int)($row['tercero_id'] ?? 0);
        $identId = (int)($row['ident_id'] ?? 0);

        if ($tid <= 0) {
            return 0;
        }

        if ($row['deleted_at'] !== null && $row['deleted_at'] !== '') {
            $this->pdo->prepare('UPDATE `tercero` SET `deleted_at` = NULL, `deleted_by` = NULL WHERE `id` = ?')
                ->execute([$tid]);
        }

        if (!$this->terceroRowExists($tid)) {
            return 0;
        }

        $data['tercero_id'] = $tid;
        if ($identId > 0) {
            $data['terceroidentificacion_id'] = $identId;
        }

        return $tid;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveDocumentoDv(array &$data, ?int $tipoDoc, string $numero): ?int
    {
        $dvRaw = trim((string)($data['documento_dv'] ?? ''));
        $dv = $dvRaw === '' ? null : (int)$dvRaw;

        if ($tipoDoc === null || $tipoDoc <= 0 || !$this->tableExists('tipodocumento')) {
            return $dv;
        }

        $stc = $this->pdo->prepare('SELECT UPPER(TRIM(codigo)), nombre FROM tipodocumento WHERE id = ? LIMIT 1');
        $stc->execute([$tipoDoc]);
        $rowTipo = $stc->fetch(PDO::FETCH_NUM);

        $tipoCodigo = null;
        $tipoNombre = null;
        if ($rowTipo) {
            $tipoCodigo = $rowTipo[0] !== null && $rowTipo[0] !== '' ? (string)$rowTipo[0] : null;
            $tipoNombre = isset($rowTipo[1]) ? (string)$rowTipo[1] : null;
        }

        $esNit = ($tipoCodigo === 'NIT')
            || ($tipoNombre !== null && stripos($tipoNombre, 'NIT') !== false);

        if ($esNit && $numero !== '') {
            $dvCalc = CrudService::colombianNitDvFromNumber($numero);
            $data['documento_dv'] = (string)$dvCalc;

            return $dvCalc;
        }

        if (!$esNit) {
            $data['documento_dv'] = '';

            return null;
        }

        return $dv;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insertTercero(
        array $data,
        string $nombres,
        string $apellidos,
        string $email,
        string $foto,
        string $firma
    ): int {
        $nombreFallback = trim((string)($data['username'] ?? ''));
        if ($nombreFallback === '') {
            $nombreFallback = 'Usuario';
        }
        if ($nombres === '') {
            $nombres = $nombreFallback;
        }

        // Se normaliza en el punto de escritura (no solo en resolveAndPersist)
        // para cubrir a cualquier llamador y también al fallback de arriba.
        // Es idempotente.
        $upper = UppercaseColumnService::applyToMap($this->pdo, 'tercero', [
            'nombres' => $nombres,
            'apellidos' => $apellidos,
        ]);
        $nombres = (string)$upper['nombres'];
        $apellidos = (string)$upper['apellidos'];

        $tCols = $this->getTableColumnNames('tercero');
        $insertCols = ['tipopersona_id', 'estado_id'];
        $insertVals = [1, 1];

        if (in_array('nombres', $tCols, true)) {
            $insertCols[] = 'nombres';
            $insertVals[] = $nombres !== '' ? $nombres : null;
        }
        if (in_array('apellidos', $tCols, true)) {
            $insertCols[] = 'apellidos';
            $insertVals[] = $apellidos !== '' ? $apellidos : null;
        }
        if (in_array('email', $tCols, true)) {
            $insertCols[] = 'email';
            $insertVals[] = $email !== '' ? $email : null;
        }
        if (in_array('foto_ruta', $tCols, true)) {
            $insertCols[] = 'foto_ruta';
            $insertVals[] = $foto !== '' ? $foto : null;
        }
        if (in_array('firma_ruta', $tCols, true)) {
            $insertCols[] = 'firma_ruta';
            $insertVals[] = $firma !== '' ? $firma : null;
        }

        $quoted = array_map(fn ($c) => '`' . str_replace('`', '', $c) . '`', $insertCols);
        $sqlIns = 'INSERT INTO `tercero` (' . implode(',', $quoted) . ') VALUES ('
            . implode(',', array_fill(0, count($insertVals), '?')) . ')';
        try {
            $this->pdo->prepare($sqlIns)->execute($insertVals);
        } catch (PDOException $e) {
            UsuarioSaveMessages::throwJson([
                'general' => UsuarioSaveMessages::PERSONA_NOT_FOUND
                    . ' Detalle: error al crear la persona en base de datos ('
                    . $e->getMessage()
                    . ').',
            ]);
        }
        $tid = (int)$this->pdo->lastInsertId();

        if ($tid <= 0) {
            UsuarioSaveMessages::throwJson(['general' => UsuarioSaveMessages::PERSONA_INSERT_FAILED]);
        }

        $data['tercero_id'] = $tid;

        return $tid;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function updateTercero(
        int $tid,
        string $nombres,
        string $apellidos,
        string $email,
        string $foto,
        string $firma,
        array $data
    ): void {
        // Se normaliza en el punto de escritura (no solo en resolveAndPersist)
        // para cubrir a cualquier llamador. Es idempotente.
        $upper = UppercaseColumnService::applyToMap($this->pdo, 'tercero', [
            'nombres' => $nombres,
            'apellidos' => $apellidos,
        ]);
        $nombres = (string)$upper['nombres'];
        $apellidos = (string)$upper['apellidos'];

        $tCols = $this->getTableColumnNames('tercero');
        $sets = [];
        $updParams = [];

        if (in_array('nombres', $tCols, true)) {
            $sets[] = '`nombres`=?';
            $updParams[] = $nombres !== '' ? $nombres : null;
        }
        if (in_array('apellidos', $tCols, true)) {
            $sets[] = '`apellidos`=?';
            $updParams[] = $apellidos !== '' ? $apellidos : null;
        }
        if (in_array('email', $tCols, true)) {
            $overwriteOk = trim((string)($data['usuario_email_overwrite_ok'] ?? '')) === '1';
            $currentEmail = null;
            $stEm = $this->pdo->prepare('SELECT email FROM tercero WHERE id = ? LIMIT 1');
            $stEm->execute([$tid]);
            $currentEmail = $stEm->fetchColumn();
            $currentEmail = $currentEmail !== false && $currentEmail !== null ? trim((string)$currentEmail) : '';
            $maySetEmail = $email === ''
                || $currentEmail === ''
                || strcasecmp($currentEmail, $email) === 0
                || $overwriteOk;
            if ($maySetEmail) {
                $sets[] = '`email`=?';
                $updParams[] = $email !== '' ? $email : null;
            }
        }
        if (in_array('foto_ruta', $tCols, true)) {
            $sets[] = '`foto_ruta`=?';
            $updParams[] = $foto !== '' ? $foto : null;
        }
        if (in_array('firma_ruta', $tCols, true)) {
            $sets[] = '`firma_ruta`=?';
            $updParams[] = $firma !== '' ? $firma : null;
        }

        if ($sets !== []) {
            $updParams[] = $tid;
            $this->pdo->prepare('UPDATE `tercero` SET ' . implode(',', $sets) . ' WHERE `id`=?')->execute($updParams);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function syncIdentificacion(array &$data, int $terceroId, ?int $tipoDoc, string $numero, ?int $dv): int
    {
        if (!$this->tableExists('terceroidentificacion')) {
            return 0;
        }

        $tiCols = $this->getTableColumnNames('terceroidentificacion');
        if (!in_array('tercero_id', $tiCols, true)
            || !in_array('tipodocumento_id', $tiCols, true)
            || !in_array('numero', $tiCols, true)
        ) {
            return 0;
        }

        if ($tipoDoc === null || $tipoDoc <= 0) {
            if ($numero !== '') {
                UsuarioSaveMessages::throwJson([
                    'tipodocumento_id' => 'Indique el tipo de documento si informa el número.',
                ]);
            }

            return 0;
        }

        if (!$this->tipodocumentoRowExists($tipoDoc)) {
            UsuarioSaveMessages::throwJson([
                'tipodocumento_id' => 'El tipo de documento seleccionado no es válido. Elija otro tipo en el formulario.',
            ]);
        }

        $tipoVal = $tipoDoc;
        $numVal = $numero !== '' ? $numero : null;

        $globalIdent = ($numVal !== null) ? $this->findIdentificacionByDocumentoGlobal($tipoVal, $numVal) : null;
        if ($globalIdent !== null) {
            $globalTerceroId = (int)($globalIdent['tercero_id'] ?? 0);
            if ($globalTerceroId > 0) {
                if (!empty($globalIdent['deleted_at'])) {
                    $this->pdo->prepare('UPDATE `tercero` SET `deleted_at` = NULL, `deleted_by` = NULL WHERE `id` = ?')
                        ->execute([$globalTerceroId]);
                }
                if ($this->terceroRowExists($globalTerceroId)) {
                    $data['tercero_id'] = $globalTerceroId;
                    $data['terceroidentificacion_id'] = (int)$globalIdent['id'];

                    return (int)$globalIdent['id'];
                }
            }
        }

        $identId = 0;
        if ($numVal !== null) {
            $stMatch = $this->pdo->prepare('
                SELECT `id` FROM `terceroidentificacion`
                WHERE `tercero_id`=? AND `tipodocumento_id`=? AND TRIM(`numero`)=TRIM(?)
                ORDER BY `principal` DESC, `id` ASC
                LIMIT 1
            ');
            $stMatch->execute([$terceroId, $tipoVal, $numVal]);
            $identId = (int)($stMatch->fetchColumn() ?: 0);
        }

        if ($identId <= 0) {
            $stFind = $this->pdo->prepare('SELECT `id` FROM `terceroidentificacion` WHERE `tercero_id`=? AND `principal`=1 LIMIT 1');
            $stFind->execute([$terceroId]);
            $identId = (int)($stFind->fetchColumn() ?: 0);
        }

        $identAccion = trim((string)($data['usuario_identificacion_accion'] ?? 'update_principal'));

        if ($identId > 0 && $identAccion === 'new_row') {
            if (in_array('principal', $tiCols, true)) {
                $this->pdo->prepare('UPDATE `terceroidentificacion` SET `principal`=0 WHERE `tercero_id`=?')->execute([$terceroId]);
            }

            return $this->insertIdentificacionRow($tiCols, $terceroId, $tipoVal, $numVal, $dv);
        }

        if ($identId > 0) {
            $uSets = ['`tipodocumento_id`=?', '`numero`=?'];
            $uPar = [$tipoVal, $numVal];
            if (in_array('dv', $tiCols, true)) {
                $uSets[] = '`dv`=?';
                $uPar[] = $dv;
            }
            $uPar[] = $identId;
            $this->pdo->prepare('UPDATE `terceroidentificacion` SET ' . implode(',', $uSets) . ' WHERE `id`=?')->execute($uPar);

            return $identId;
        }

        if ($numVal === null) {
            return 0;
        }

        return $this->insertIdentificacionRow($tiCols, $terceroId, $tipoVal, $numVal, $dv);
    }

    /**
     * @param list<string> $tiCols
     */
    private function insertIdentificacionRow(array $tiCols, int $terceroId, int $tipoVal, ?string $numVal, ?int $dv): int
    {
        $insC = ['tercero_id', 'tipodocumento_id', 'numero', 'principal', 'estado_id'];
        $insV = [$terceroId, $tipoVal, $numVal, 1, 1];
        if (in_array('dv', $tiCols, true)) {
            $insC[] = 'dv';
            $insV[] = $dv;
        }
        $qc = array_map(fn ($c) => '`' . str_replace('`', '', $c) . '`', $insC);
        $this->pdo->prepare(
            'INSERT INTO `terceroidentificacion` (' . implode(',', $qc) . ') VALUES ('
            . implode(',', array_fill(0, count($insV), '?')) . ')'
        )->execute($insV);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @return array{id: int, tercero_id: int, deleted_at: ?string}|null
     */
    private function findIdentificacionByDocumentoGlobal(int $tipoDoc, string $numero): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT ti.id, ti.tercero_id, t.deleted_at
            FROM terceroidentificacion ti
            INNER JOIN tercero t ON t.id = ti.tercero_id
            WHERE ti.tipodocumento_id = ? AND TRIM(ti.numero) = TRIM(?)
            ORDER BY ti.principal DESC, ti.id ASC
            LIMIT 1
        ');
        $stmt->execute([$tipoDoc, $numero]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return [
            'id' => (int)($row['id'] ?? 0),
            'tercero_id' => (int)($row['tercero_id'] ?? 0),
            'deleted_at' => isset($row['deleted_at']) && $row['deleted_at'] !== '' ? (string)$row['deleted_at'] : null,
        ];
    }

    private function tipodocumentoRowExists(int $tipodocumentoId): bool
    {
        if ($tipodocumentoId <= 0 || !$this->tableExists('tipodocumento')) {
            return false;
        }

        $stmt = $this->pdo->prepare('SELECT 1 FROM tipodocumento WHERE id = ? LIMIT 1');
        $stmt->execute([$tipodocumentoId]);

        return (bool)$stmt->fetchColumn();
    }

    private function terceroidentificacionRowExists(int $identId, int $expectedTerceroId): bool
    {
        if ($identId <= 0 || !$this->tableExists('terceroidentificacion')) {
            return false;
        }

        $stmt = $this->pdo->prepare('SELECT tercero_id FROM terceroidentificacion WHERE id = ? LIMIT 1');
        $stmt->execute([$identId]);
        $tid = (int)($stmt->fetchColumn() ?: 0);

        return $tid > 0 && $tid === $expectedTerceroId && $this->terceroRowExists($tid);
    }

    private function terceroRowExists(int $terceroId): bool
    {
        if ($terceroId <= 0 || !$this->tableExists('tercero')) {
            return false;
        }

        $sql = 'SELECT 1 FROM `tercero` WHERE `id` = ?';
        if (SoftDeleteService::supports($this->pdo, 'tercero')) {
            $sql .= SoftDeleteService::sqlAndNotDeleted($this->pdo, 'tercero');
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$terceroId]);

        return (bool)$stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function failPersonaNotFound(array $data, int $tid, ?int $tipoDoc, string $numero): void
    {
        $detalle = [];

        if ($tipoDoc === null || $tipoDoc <= 0) {
            $detalle[] = 'no llegó el tipo de documento al servidor (vuelva a elegir «Cédula de Ciudadanía»)';
        } elseif (!$this->tipodocumentoRowExists($tipoDoc)) {
            $detalle[] = 'el tipo de documento enviado no es válido';
        }

        if ($numero === '') {
            $detalle[] = 'falta el número de documento';
        }

        if ($tid > 0 && !$this->terceroRowExists($tid)) {
            $detalle[] = 'el formulario tenía una referencia interna de persona antigua (pulse Limpiar)';
        } elseif ($tid <= 0) {
            $detalle[] = 'no se pudo crear ni enlazar el registro de persona en la base de datos';
        }

        $msg = UsuarioSaveMessages::PERSONA_NOT_FOUND;
        if ($detalle !== []) {
            $msg .= ' Detalle: ' . implode('; ', $detalle) . '.';
        }

        UsuarioSaveMessages::throwJson(['general' => $msg]);
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare('
            SELECT 1 FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            LIMIT 1
        ');
        $stmt->execute([$table]);

        return (bool)$stmt->fetchColumn();
    }

    /**
     * @return list<string>
     */
    private function getTableColumnNames(string $table): array
    {
        $stmt = $this->pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');

        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    }
}
