<?php

/**
 * Acceso a `sihos_empresa_config` en la BD propia de SAVID (una fila por
 * empresa con los datos de conexión a la BD externa de SIHOS de esa empresa).
 * No confundir con SihosExternalRepository, que es la conexión de solo
 * lectura hacia SIHOS con esos datos ya resueltos.
 */
class SihosEmpresaConfigRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $database = new Database();
        $this->pdo = $database->connect();
    }

    public function findByEmpresaId(int $empresaId): ?array
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sihos_empresa_config');

        $stmt = $this->pdo->prepare("
            SELECT id, empresa_id, host, puerto, base_datos, usuario, codi_inst, password_cifrado, charset,
                   usuario_escritura, password_escritura_cifrado
            FROM sihos_empresa_config
            WHERE empresa_id = ? {$nd}
            LIMIT 1
        ");
        $stmt->execute([$empresaId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array{host:string,puerto:int,base_datos:string,usuario:string,codi_inst:string,password_cifrado:?string,charset:string,usuario_escritura:?string,password_escritura_cifrado:?string} $data
     */
    public function upsert(int $empresaId, array $data): void
    {
        $existing = $this->findByEmpresaId($empresaId);

        if ($existing !== null) {
            $sql = 'UPDATE sihos_empresa_config SET host = ?, puerto = ?, base_datos = ?, usuario = ?, codi_inst = ?, charset = ?, usuario_escritura = ?';
            $params = [
                $data['host'], $data['puerto'], $data['base_datos'], $data['usuario'],
                $data['codi_inst'], $data['charset'], $data['usuario_escritura'],
            ];

            if ($data['password_cifrado'] !== null) {
                $sql .= ', password_cifrado = ?';
                $params[] = $data['password_cifrado'];
            }

            if ($data['password_escritura_cifrado'] !== null) {
                $sql .= ', password_escritura_cifrado = ?';
                $params[] = $data['password_escritura_cifrado'];
            }

            $sql .= ' WHERE empresa_id = ?';
            $params[] = $empresaId;

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return;
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO sihos_empresa_config (empresa_id, host, puerto, base_datos, usuario, codi_inst, password_cifrado, charset, usuario_escritura, password_escritura_cifrado, estado_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ');
        $stmt->execute([
            $empresaId,
            $data['host'],
            $data['puerto'],
            $data['base_datos'],
            $data['usuario'],
            $data['codi_inst'],
            $data['password_cifrado'],
            $data['charset'],
            $data['usuario_escritura'],
            $data['password_escritura_cifrado'],
        ]);
    }
}
