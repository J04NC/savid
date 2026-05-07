<?php

class Database
{
    private $host = 'localhost';
    private $dbname = 'savid';
    private $username = 'root';       // <-- Cambia si usas otro usuario
    private $password = 'Smp*0831';           // <-- Coloca tu contraseña de MySQL
    private $charset = 'utf8mb4';

    private $pdo;

    public function connect()
    {
        if ($this->pdo === null) {

            $dsn = "mysql:host={$this->host};dbname={$this->dbname};charset={$this->charset}";

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Mostrar errores reales
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Retornar arrays asociativos
                PDO::ATTR_EMULATE_PREPARES   => false,                  // Usar prepared statements reales
            ];

            try {
                $this->pdo = new PDO($dsn, $this->username, $this->password, $options);
            } catch (PDOException $e) {
                die("Error de conexión a la base de datos: " . $e->getMessage());
            }
        }

        return $this->pdo;
    }
}
