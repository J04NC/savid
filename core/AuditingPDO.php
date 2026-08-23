<?php

/**
 * PDO que envuelve sentencias mutantes para registrar auditoría.
 */
class AuditingPDO extends PDO
{
    /** ID devuelto por el último INSERT de negocio (la auditoría inserta después y pisa LAST_INSERT_ID). */
    private ?string $pendingLastInsertId = null;

    /**
     * prepare nativo (sin envoltorio) para re-preparar SQL modificado en execute().
     */
    public function prepareNative(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare($query, $options);
    }

    /**
     * Envuelve el statement para auditoría. No se puede declarar PDOStatement|false
     * porque AuditingPDOStatement no extiende PDOStatement.
     *
     * @return AuditingPDOStatement|false
     */
    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = [])
    {
        $stmt = parent::prepare($query, $options);

        if ($stmt === false) {
            return false;
        }

        return new AuditingPDOStatement($stmt, (string)$query, $this);
    }

    /**
     * Conserva el autoincrement del INSERT de negocio antes de que persistLog escriba en auditoria.
     */
    public function rememberBusinessLastInsertId(string $id): void
    {
        if ($id !== '' && $id !== '0') {
            $this->pendingLastInsertId = $id;
        }
    }

    /**
     * LAST_INSERT_ID() real de MySQL (sin cola de negocio para el llamador).
     */
    public function nativeLastInsertId(): string
    {
        return (string) parent::lastInsertId();
    }

    #[\ReturnTypeWillChange]
    public function lastInsertId($name = null)
    {
        if ($this->pendingLastInsertId !== null) {
            $id = $this->pendingLastInsertId;
            $this->pendingLastInsertId = null;

            return $id;
        }

        return parent::lastInsertId($name);
    }

    public function exec($statement): int|false
    {
        $sql = trim((string)$statement);

        if (AuditService::isEnabled() && AuditService::isMutatingSql($sql)) {
            AuditService::recordBeforeExec($this, $sql);
        }

        // Faltaba ejecutar la sentencia: $result quedaba indefinido (null), asi
        // que exec() no hacia nada y ademas violaba su propio tipo de retorno
        // int|false (TypeError). Solo no se notaba porque en la web nadie la
        // llamaba y en CLI la conexion no era AuditingPDO.
        $result = parent::exec($statement);

        $capturedInsertId = null;
        if ($result !== false && preg_match('/^INSERT\b/i', $sql)) {
            $capturedInsertId = parent::lastInsertId();
        }

        if ($result !== false && AuditService::isEnabled() && AuditService::isMutatingSql($sql)) {
            AuditService::recordAfterExec($this, $sql);
        }

        if ($capturedInsertId !== null && $capturedInsertId !== '' && $capturedInsertId !== '0') {
            $this->rememberBusinessLastInsertId((string)$capturedInsertId);
        }

        return $result;
    }
}
