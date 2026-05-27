<?php

/**
 * PDO que envuelve sentencias mutantes para registrar auditoría.
 */
class AuditingPDO extends PDO
{
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

    public function exec($statement): int|false
    {
        $sql = trim((string)$statement);

        if (AuditService::isEnabled() && AuditService::isMutatingSql($sql)) {
            AuditService::recordBeforeExec($this, $sql);
        }

        $result = parent::exec($statement);

        if ($result !== false && AuditService::isEnabled() && AuditService::isMutatingSql($sql)) {
            AuditService::recordAfterExec($this, $sql);
        }

        return $result;
    }
}
