<?php

/**
 * Conexión de ESCRITURA a la base de datos externa de SIHOS de una
 * empresa concreta. Deliberadamente separada de SihosExternalRepository
 * (que fuerza `SET SESSION TRANSACTION READ ONLY`): esta clase existe
 * SOLO para la acción explícita y auditada de borrar un DetaPlan huérfano
 * (nota sobre factura de vigencia anterior que no debería tener
 * presupuesto — ver SihosPresupuestoEliminacionService). No se usa para
 * los reportes, y los reportes nunca deben instanciar esta clase.
 *
 * La credencial de conexión (usuario_escritura/password_escritura_cifrado
 * en sihos_empresa_config) es distinta de la de solo lectura y opcional
 * por diseño: sin ella configurada, la acción de borrado no aparece.
 */
class SihosExternalWriteRepository
{
    private ?PDO $pdo = null;

    /**
     * @param array{host:string,port:string,database:string,username:string,password:string,charset:string,codiInst:string} $config
     */
    public function __construct(private readonly array $config)
    {
    }

    /**
     * @throws PDOException si no conecta
     */
    public function connect(): PDO
    {
        if ($this->pdo === null) {
            $host = $this->config['host'];
            $port = $this->config['port'];
            $dbname = $this->config['database'];
            $charset = $this->config['charset'];

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

            $this->pdo = new PDO($dsn, $this->config['username'], $this->config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        }

        return $this->pdo;
    }

    /**
     * Borra todas las líneas de DetaPlan de un documento puntual, dentro de
     * una transacción, y devuelve el snapshot de lo borrado (para el
     * registro de auditoría en SAVID). Si algo falla, revierte y relanza.
     *
     * @return array<int, array<string, mixed>> filas borradas
     * @throws PDOException
     */
    public function eliminarDetaPlan(string $codiDocu, string $numeDocu): array
    {
        $pdo = $this->connect();
        $codiInst = (string)($this->config['codiInst'] ?? '');

        $pdo->beginTransaction();

        try {
            $stmtSelect = $pdo->prepare(
                'SELECT * FROM DetaPlan WHERE CodiInst = ? AND CodiDocu = ? AND NumeDocu = ?'
            );
            $stmtSelect->execute([$codiInst, $codiDocu, $numeDocu]);
            $filas = $stmtSelect->fetchAll();

            if ($filas === []) {
                $pdo->rollBack();

                return [];
            }

            $stmtDelete = $pdo->prepare(
                'DELETE FROM DetaPlan WHERE CodiInst = ? AND CodiDocu = ? AND NumeDocu = ?'
            );
            $stmtDelete->execute([$codiInst, $codiDocu, $numeDocu]);

            $pdo->commit();

            return $filas;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }
}
