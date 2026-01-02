<?php
declare(strict_types=1);

class DB {
    private static ?PDO $instance = null;

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            try {
                $dsn = sprintf(
                    'mysql:host=%s;dbname=%s;charset=utf8mb4',
                    $_ENV['DB_HOST'] ?? 'localhost',
                    $_ENV['DB_NAME'] ?? ''
                );

                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ];

                self::$instance = new PDO(
                    $dsn,
                    $_ENV['DB_USER'] ?? '',
                    $_ENV['DB_PASS'] ?? '',
                    $options
                );
                
            } catch (PDOException $e) {
                throw new Exception('Database connection failed: ' . $e->getMessage());
            }
        }

        return self::$instance;
    }
}