<?php
$DB_HOST = 'localhost';
$DB_NAME = 'fortunnet_technologies';
$DB_USER = 'root';
$DB_PASS = '';

class Database {
    private $pdo;

    public function __construct() {
        global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;

        try {
            $this->pdo = new PDO(
                "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
                $DB_USER,
                $DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }

    public function getConnection() {
        return $this->pdo;
    }

    public function query($sql) {
        return $this->pdo->query($sql);
    }

    public function prepare($sql) {
        return $this->pdo->prepare($sql);
    }

    public function exec($sql) {
        return $this->pdo->exec($sql);
    }
}

/**
 * 🔥 THIS IS THE KEY PART 🔥
 * Makes $pdo available everywhere
 */
$db  = new Database();
$pdo = $db->getConnection();

