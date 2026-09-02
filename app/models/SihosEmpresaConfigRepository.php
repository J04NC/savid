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
     * Municipio de la empresa (empresa → tercero → municipio, catálogo
     * territorial de SAVID), en MAYÚSCULAS y sin tildes (conserva la Ñ, que
     * no es una tilde) — mismo formato que tenía el valor fijo que
     * reemplaza ('LA UNION', sin tilde) en la columna 'Ciu' del reporte
     * PILA (SihosNominaPilaService/SihosNominaPilaCorreccionService, ver
     * SihosExternalRepository::fetchNominaPila()): el operador de aportes en
     * línea es estricto con el formato de ese archivo, así que se normaliza
     * aquí en vez de confiar en cómo esté digitado el municipio en el
     * catálogo territorial.
     *
     * @return string vacío si la empresa no tiene tercero o el tercero no
     *     tiene municipio asignado — el llamador decide qué hacer con eso
     *     (hoy: dejar la columna vacía en vez de inventar un valor).
     */
    public function municipioEmpresa(int $empresaId): string
    {
        $stmt = $this->pdo->prepare('
            SELECT m.nombre
            FROM empresa e
            INNER JOIN tercero t ON t.id = e.tercero_id
            INNER JOIN municipio m ON m.id = t.municipio_id
            WHERE e.id = ?
            LIMIT 1
        ');
        $stmt->execute([$empresaId]);
        $nombre = $stmt->fetchColumn();

        if ($nombre === false || trim((string)$nombre) === '') {
            return '';
        }

        $nombre = mb_strtoupper(trim((string)$nombre), 'UTF-8');

        return strtr($nombre, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U']);
    }

    /**
     * Salario mínimo mensual vigente de la empresa para un año (tabla
     * `sihos_salario_minimo`, motor CRUD genérico, ítem "SIHOS > REPORTES >
     * NÓMINA > SALARIO MÍNIMO") — usado por
     * SihosExternalRepository::fetchNominaPila() para el piso de 1 SMLDV
     * del retroactivo de vacaciones (ver docblock de esa sección).
     *
     * @return float 0.0 si no hay valor configurado para esa empresa/año —
     *     el llamador lo pasa tal cual a fetchNominaPila(), donde 0.0 es un
     *     no-op explícito (nunca null, ver docblock de esa función).
     */
    public function salarioMinimoMensual(int $empresaId, int $ano): float
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'sihos_salario_minimo');

        $stmt = $this->pdo->prepare("
            SELECT valor
            FROM sihos_salario_minimo
            WHERE empresa_id = ? AND ano = ? {$nd}
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$empresaId, $ano]);
        $valor = $stmt->fetchColumn();

        return $valor === false ? 0.0 : (float)$valor;
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
