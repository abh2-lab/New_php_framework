<?php
// src/api/Core/Config/DatabaseConnection.php

namespace App\Core\Config;

use PDO;
use PDOException;
use App\Core\Exceptions\DatabaseException;

final class DatabaseConnection
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        // Per-request cache: if already created, reuse it
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $cfg = self::resolveConfig();

        $dsn = sprintf(
            "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
            $cfg['host'],
            $cfg['port'],
            $cfg['db']
        );

        try {
            $pdo = new PDO($dsn, $cfg['user'], $cfg['pass']);

            // Same defaults as your existing connection.php
            $pdo->exec("SET time_zone = 'Asia/Kolkata'");
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

            self::$pdo = $pdo;
            return self::$pdo;

        } catch (PDOException $e) {
            throw new DatabaseException(
                'Database connection failed: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    // Optional: useful for tests / long-running workers
    public static function reset(): void
    {
        self::$pdo = null;
    }

    private static function resolveConfig(): array
    {
        $isCLI = (php_sapi_name() === 'cli');

        // normalize APP_ENV
        $appEnv = strtolower(trim($_ENV['APP_ENV'] ?? 'production'));

        // 1) CLI still gets its own variables (same as your current file)
        if ($isCLI) {
            return [
                'host' => $_ENV['DB_HOST_CLI'] ?? '127.0.0.1',
                'port' => $_ENV['DB_PORT_CLI'] ?? '3306',
                'user' => $_ENV['DB_USER'] ?? 'root',
                'pass' => $_ENV['DB_PASSWORD'] ?? 'abcd',
                'db' => $_ENV['DB_NAME'] ?? 'testdb',
            ];
        }

        // 2) Development / local
        if (in_array($appEnv, ['development', 'dev', 'local'], true)) {
            return [
                'host' => $_ENV['DB_HOST'] ?? 'db',
                'port' => $_ENV['DB_PORT'] ?? '3306',
                'user' => $_ENV['DB_USER'] ?? 'root',
                'pass' => $_ENV['DB_PASSWORD'] ?? 'abcd',
                'db' => $_ENV['DB_NAME'] ?? 'testdb',
            ];
        }

        // 3) Production (default)
        return [
            'host' => $_ENV['DB_HOST'] ?? 'phpmyadmin.coolify.vps.boomlive.in',
            'port' => $_ENV['DB_PORT'] ?? '3303',
            'user' => $_ENV['DB_USER'] ?? 'root',
            'pass' => $_ENV['DB_PASSWORD'] ?? 'abcd',
            'db' => $_ENV['DB_NAME'] ?? 'connect_ping2',
        ];
    }

}
