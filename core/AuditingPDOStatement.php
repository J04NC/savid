<?php

/**
 * Envoltorio de PDOStatement con auditoría y trazabilidad en execute().
 * (PDOStatement no es extensible; se delega al statement nativo.)
 */
class AuditingPDOStatement
{
    private PDOStatement $inner;
    private string $sql;
    private AuditingPDO $connection;

    /** @var array<int|string, mixed> */
    private array $bound = [];

    public function __construct(PDOStatement $inner, string $sql, AuditingPDO $connection)
    {
        $this->inner = $inner;
        $this->sql = $sql;
        $this->connection = $connection;
    }

    public function execute(?array $params = null): bool
    {
        $effective = $params ?? $this->boundValuesList();
        $sql = $this->sql;

        if (TrackableColumnsService::isMutatingSql($sql)) {
            [$sql, $effective] = TrackableColumnsService::apply($this->connection, $sql, $effective);

            if ($sql !== $this->sql) {
                $reprepared = $this->connection->prepareNative($sql);
                if ($reprepared === false) {
                    return false;
                }
                $this->inner = $reprepared;
                $this->sql = $sql;
            }
        }

        $snapshot = null;

        if (AuditService::isEnabled() && AuditService::isMutatingSql($sql)) {
            $snapshot = AuditService::captureBeforeMutation($this->connection, $sql, $effective);
        }

        $ok = $this->inner->execute($effective);

        $capturedInsertId = null;
        if ($ok && preg_match('/^INSERT\b/i', ltrim($sql))) {
            $capturedInsertId = $this->connection->nativeLastInsertId();
        }

        if ($ok && AuditService::isEnabled() && AuditService::isMutatingSql($sql)) {
            AuditService::recordAfterMutation($this->connection, $sql, $effective, $snapshot);
        }

        if ($capturedInsertId !== null && $capturedInsertId !== '' && $capturedInsertId !== '0') {
            $this->connection->rememberBusinessLastInsertId((string)$capturedInsertId);
        }

        return $ok;
    }

    public function bindValue($param, $value, $type = PDO::PARAM_STR): bool
    {
        $this->bound[$param] = $value;

        return $this->inner->bindValue($param, $value, $type);
    }

    public function bindParam($param, &$var, $type = PDO::PARAM_STR, $maxLength = 0, $driverOptions = []): bool
    {
        $this->bound[$param] = $var;

        return $this->inner->bindParam($param, $var, $type, $maxLength, $driverOptions);
    }

    /**
     * @return list<mixed>
     */
    private function boundValuesList(): array
    {
        if ($this->bound === []) {
            return [];
        }

        if (array_key_exists(1, $this->bound) || array_key_exists(0, $this->bound)) {
            $max = max(array_map('intval', array_keys($this->bound)));
            $out = [];
            for ($i = 1; $i <= $max; $i++) {
                $out[] = $this->bound[$i] ?? null;
            }

            return $out;
        }

        return array_values($this->bound);
    }

    public function fetch($mode = PDO::FETCH_DEFAULT, $cursorOrientation = PDO::FETCH_ORI_NEXT, $cursorOffset = 0): mixed
    {
        return $this->inner->fetch($mode, $cursorOrientation, $cursorOffset);
    }

    public function fetchAll($mode = PDO::FETCH_DEFAULT, ...$args): array
    {
        return $this->inner->fetchAll($mode, ...$args);
    }

    public function fetchColumn($column = 0): mixed
    {
        return $this->inner->fetchColumn($column);
    }

    public function rowCount(): int
    {
        return $this->inner->rowCount();
    }

    public function setFetchMode($mode, ...$args): bool
    {
        return $this->inner->setFetchMode($mode, ...$args);
    }

    public function bindColumn($column, &$var, $type = PDO::PARAM_STR, $maxLength = 0, $driverOptions = []): bool
    {
        return $this->inner->bindColumn($column, $var, $type, $maxLength, $driverOptions);
    }

    public function closeCursor(): bool
    {
        return $this->inner->closeCursor();
    }

    public function nextRowset(): bool
    {
        return $this->inner->nextRowset();
    }

    public function errorCode(): ?string
    {
        return $this->inner->errorCode();
    }

    public function errorInfo(): array
    {
        return $this->inner->errorInfo();
    }

    public function __call(string $name, array $arguments): mixed
    {
        return $this->inner->{$name}(...$arguments);
    }
}
