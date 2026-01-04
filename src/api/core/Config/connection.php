<?php
// src/api/Core/Config/connection.php

use App\Core\Security;
use App\Core\Config\DatabaseConnection;
use PDO;
use PDOException;


// Skip security check for CLI scripts (testing, cron jobs, etc.)
$isCLI = (php_sapi_name() === 'cli');
if (!$isCLI) {
    Security::ensureSecure();
}

// ============================================================================
// DATABASE CONNECTION CONFIGURATION (Environment-based)
// NOTE: Actual PDO creation is now handled by DatabaseConnection::pdo().
// We still compute $servername/$port/$dbname for debug/error context.
// ============================================================================

$appEnv = strtolower(trim($_ENV['APP_ENV'] ?? 'production'));

// Determine connection parameters for debug display / compatibility
if ($isCLI) {
    $servername = $_ENV['DB_HOST_CLI'] ?? "127.0.0.1";
    $port = $_ENV['DB_PORT_CLI'] ?? "3306";
    $username = $_ENV['DB_USER'] ?? "root";
    $password = $_ENV['DB_PASSWORD'] ?? "abcd";
    $dbname = $_ENV['DB_NAME'] ?? "testdb";
} elseif (in_array($appEnv, ['development', 'dev', 'local'], true)) {
    // Development/Local/Docker Mode
    $servername = $_ENV['DB_HOST'] ?? "db";
    $port = $_ENV['DB_PORT'] ?? "3306";
    $username = $_ENV['DB_USER'] ?? "root";
    $password = $_ENV['DB_PASSWORD'] ?? "abcd";
    $dbname = $_ENV['DB_NAME'] ?? "testdb";
} else {
    // Production Mode
    $servername = $_ENV['DB_HOST'] ?? "phpmyadmin.coolify.vps.boomlive.in";
    $port = $_ENV['DB_PORT'] ?? "3303";
    $username = $_ENV['DB_USER'] ?? "root";
    $password = $_ENV['DB_PASSWORD'] ?? "abcd";
    $dbname = $_ENV['DB_NAME'] ?? "connect_ping2";
}

// Create (cached) PDO connection using the new factory
try {
    $connpdo = DatabaseConnection::pdo();
} catch (\Throwable $e) {
    // Keep old behavior: return JSON + die (so nothing breaks during migration)
    $mode = $isCLI ? "CLI" : strtoupper($appEnv ?: 'production');

    if (!$isCLI) {
        http_response_code(500);
        header('Content-Type: application/json');
    }

    echo json_encode([
        'error' => 'Database connection failed',
        'mode' => $mode,
        'host' => $servername,
        'port' => $port,
        'database' => $dbname,
        'message' => $e->getMessage(),
    ]);

    die();
}

// ============================================================================
// TRANSACTION HELPER FUNCTIONS (NEW - For Database Class)
// ============================================================================

/**
 * Begin a database transaction
 *
 * @param PDO $conn Database connection
 * @return bool Success status
 */
function transBegin(PDO $conn): bool
{
    try {
        if ($conn->inTransaction()) {
            error_log("Warning: Transaction already in progress");
            return false;
        }
        return $conn->beginTransaction();
    } catch (PDOException $e) {
        error_log("Transaction Begin Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Commit a database transaction
 *
 * @param PDO $conn Database connection
 * @return bool Success status
 */
function transCommit(PDO $conn): bool
{
    try {
        if (!$conn->inTransaction()) {
            error_log("Warning: No active transaction to commit");
            return false;
        }
        return $conn->commit();
    } catch (PDOException $e) {
        error_log("Transaction Commit Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Rollback a database transaction
 *
 * @param PDO $conn Database connection
 * @return bool Success status
 */
function transRollback(PDO $conn): bool
{
    try {
        if (!$conn->inTransaction()) {
            error_log("Warning: No active transaction to rollback");
            return false;
        }
        return $conn->rollBack();
    } catch (PDOException $e) {
        error_log("Transaction Rollback Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if currently in a transaction
 *
 * @param PDO $conn Database connection
 * @return bool Transaction status
 */
function transStatus(PDO $conn): bool
{
    return $conn->inTransaction();
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Enhanced interpolateQuery function
 * Interpolates query parameters for debugging
 *
 * @param PDO $conn Database connection
 * @param string $query SQL query with placeholders
 * @param array $params Parameters array
 * @return string Interpolated SQL query
 */
function interpolateQuery(PDO $conn, string $query, array $params = []): string
{
    $keys = array_keys($params);
    $values = array_values($params);

    return array_reduce($keys, function ($interpolatedQuery, $key) use ($conn, $values, $keys) {
        $value = $values[array_search($key, $keys)];

        if (is_string($value)) {
            $value = $conn->quote($value);
        } elseif (is_array($value)) {
            $value = implode(',', array_map([$conn, 'quote'], $value));
        } elseif (is_null($value)) {
            $value = 'NULL';
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        return str_replace($key, $value, $interpolatedQuery);
    }, $query);
}

// ============================================================================
// LEGACY RunQuery FUNCTION
// @deprecated Will be removed in future versions. Use Database class instead.
// Kept for backward compatibility with existing controllers.
// ============================================================================

/**
 * RunQuery function - Array-only parameter style (Standard)
 *
 * @deprecated Use the new Database class instead: $this->db->query()
 *
 * @param array $config Configuration array
 * @return mixed Query results, success array, SQL string, or error array
 */
function RunQuery(array $config)
{
    $conn = $config['conn'] ?? null;
    $query = $config['query'] ?? null;
    $parameterArray = $config['params'] ?? [];
    $dataAsASSOC = $config['fetchAssoc'] ?? true;
    $withSUCCESS = $config['withSuccess'] ?? false;
    $returnSql = $config['returnSql'] ?? false;

    if (!$conn) {
        return ['error' => 'Database connection (conn) is required'];
    }

    if (!$query) {
        return ['error' => 'SQL query (query) is required'];
    }

    try {
        if ($returnSql) {
            return interpolateQuery($conn, $query, $parameterArray);
        }

        $conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $stmt = $conn->prepare($query);

        if (!$stmt->execute($parameterArray)) {
            return ['error' => implode(', ', $stmt->errorInfo())];
        }

        $rows = $stmt->fetchAll($dataAsASSOC ? PDO::FETCH_ASSOC : PDO::FETCH_NUM);

        if ($withSUCCESS) {
            $result = [
                'success' => true,
                'affected_rows' => $stmt->rowCount(),
                'id' => null,
                'data' => $rows
            ];

            if (stripos(trim($query), 'insert') === 0) {
                $result['id'] = $conn->lastInsertId();
            }

            if (stripos(trim($query), 'update') === 0 && isset($parameterArray[':id'])) {
                $result['id'] = $parameterArray[':id'];
            }

            return $result;
        }

        return $rows;

    } catch (PDOException $e) {
        if (!empty($_ENV['LOG_ERRORS']) && ($_ENV['LOG_ERRORS'] === 'true' || $_ENV['LOG_ERRORS'] === '1')) {
            error_log("RunQuery Error: " . $e->getMessage() . " | Query: " . $query);
        }

        return ['error' => $e->getMessage()];
    }
}

// ============================================================================
// CONNECTION VALIDATION (Debug Mode Only)
// ============================================================================

if (!empty($_ENV['DEBUG_MODE']) && $_ENV['DEBUG_MODE'] === 'true' && $isCLI) {
    try {
        $connpdo->query('SELECT 1');
        error_log("✓ Database connection successful (CLI mode)");
        error_log("  Host: $servername:$port");
        error_log("  Database: $dbname");
    } catch (PDOException $e) {
        error_log("✗ Database connection test failed: " . $e->getMessage());
    }
}
