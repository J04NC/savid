<?php

/**
 * Configuracion de conexion PDO.
 *
 * En desarrollo: el proyecto carga este archivo si no existe config/Database.php.
 * En tu maquina: copia a config/Database.php y ajusta (esa carpeta esta en .gitignore).
 *
 * Variables de entorno soportadas (o archivo .env en la raiz del proyecto o en config/):
 * DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD, DB_CHARSET
 */
class Database
{
    private $host;
    private $dbname;
    private $username;
    private $password;
    private $charset;

    private $pdo;

    public function __construct()
    {
        self::loadEnv();

        $this->host = getenv('DB_HOST') ?: 'localhost';
        $this->dbname = getenv('DB_DATABASE') ?: 'savid';
        $this->username = getenv('DB_USERNAME') ?: 'root';
        $this->password = getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '';
        $this->charset = getenv('DB_CHARSET') ?: 'utf8mb4';
    }

    /**
     * Carga .env desde config/.env, .env en raiz, o variables ya definidas en el servidor.
     */
    public static function bootstrapEnv(): void
    {
        self::loadEnv();
    }

    /**
     * Carga .env desde config/.env, .env en raiz, o variables ya definidas en el servidor.
     */
    private static function loadEnv()
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;

        $base = dirname(__DIR__);
        foreach ([$base . '/config/.env', $base . '/.env'] as $path) {
            if (!is_readable($path)) {
                continue;
            }
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || (isset($line[0]) && $line[0] === '#')) {
                    continue;
                }
                if (strpos($line, '=') === false) {
                    continue;
                }
                [$name, $value] = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);
                if ($name !== '') {
                    putenv("$name=$value");
                    $_ENV[$name] = $value;
                }
            }
            break;
        }
    }

    public function connect()
    {
        if ($this->pdo === null) {
            self::loadEnv();

            $dsn = "mysql:host={$this->host};dbname={$this->dbname};charset={$this->charset}";

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                /*
                 * Con el segundo argumento en false (sin autoload) esta comprobacion
                 * solo veia AuditingPDO si alguien la habia requerido antes a mano:
                 * la web lo hacia (public/index.php) y la CLI no, asi que los scripts
                 * escribian sin auditoria y no reproducian los fallos de la capa
                 * auditada. Se permite el autoload para que ambos entornos coincidan.
                 */
                $pdoClass = class_exists('AuditingPDO') ? 'AuditingPDO' : 'PDO';
                $this->pdo = new $pdoClass($dsn, $this->username, $this->password, $options);
            } catch (PDOException $e) {
                die('Error de conexion a la base de datos: ' . $e->getMessage());
            }

            $this->aplicarWaitTimeout();
        }

        return $this->pdo;
    }

    /**
     * Acota cuanto sobrevive una conexion web inactiva.
     *
     * El wait_timeout del servidor son 8 horas. Si un worker de PHP-FPM queda
     * colgado con una transaccion abierta, esa conexion se queda en Sleep
     * reteniendo los locks de sus filas todo ese tiempo y bloqueando al resto
     * (se observo justo ese caso: Sleep, 67 filas bloqueadas). Con un plazo
     * corto MySQL la cierra y deshace la transaccion, liberando los locks.
     *
     * Solo aplica a peticiones web: un script CLI (import, cron) puede pasar
     * legitimamente mucho rato sin tocar la BD y no debe perder la conexion.
     * Ajustable con DB_WAIT_TIMEOUT; 0 o vacio lo deja como este en el servidor.
     */
    private function aplicarWaitTimeout(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $segundos = (int)(getenv('DB_WAIT_TIMEOUT') ?: 300);

        if ($segundos <= 0) {
            return;
        }

        try {
            $this->pdo->exec('SET SESSION wait_timeout = ' . $segundos);
        } catch (PDOException $e) {
            // Un servidor que no permita ajustarlo no debe impedir la conexion.
        }
    }
}
