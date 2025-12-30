<?php
// src/api/Core/Config/connection.php

use App\Core\Security;

// Skip security check for CLI scripts (testing, cron jobs, etc.)
if (php_sapi_name() !== 'cli') {
    Security::ensureSecure();
}

// ============================================================================
// DATABASE CONNECTION CONFIGURATION (Environment-based)
// ============================================================================

// Detect environment
$isCLI = (php_sapi_name() === 'cli');
$currentHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';

// Determine connection parameters based on environment
if ($isCLI) {
    // CLI Mode - Connect via localhost (requires exposed port in docker-compose)
    $servername = $_ENV['DB_HOST_CLI'] ?? "127.0.0.1";
    $port = $_ENV['DB_PORT_CLI'] ?? "3306";
    $username = $_ENV['DB_USER'] ?? "root";
    $password = $_ENV['DB_PASSWORD'] ?? "abcd";
    $dbname = $_ENV['DB_NAME'] ?? "testdb";
} elseif (strpos($currentHost, 'localhost') !== false || strpos($currentHost, '127.0.0.1') !== false) {
    // Docker/Local Web Mode - Use Docker service name
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

// PDO connection
try {
    $dsn = "mysql:host=$servername;port=$port;dbname=$dbname;charset=utf8mb4";
    $connpdo = new PDO($dsn, $username, $password);
    $connpdo->exec("SET time_zone = 'Asia/Kolkata'");
    $connpdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $connpdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $connpdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch (PDOException $e) {
    $env = $isCLI ? "CLI" : ($currentHost ?? "Docker");
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Database connection failed',
        'mode' => $env,
        'host' => $servername,
        'port' => $port,
        'database' => $dbname,
        'message' => $e->getMessage()
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
 * @deprecated This function is deprecated and will be removed in future versions.
 *             Use the new Database class instead: $this->db->query()
 * 
 * Usage Examples:
 * 
 * // Basic SELECT
 * RunQuery([
 *     'conn' => $conn,
 *     'query' => 'SELECT * FROM users WHERE id = :id',
 *     'params' => [':id' => 1]
 * ]);
 * 
 * // INSERT with success info
 * RunQuery([
 *     'conn' => $conn,
 *     'query' => 'INSERT INTO users (name, email) VALUES (:name, :email)',
 *     'params' => [':name' => 'John', ':email' => 'john@example.com'],
 *     'withSuccess' => true
 * ]);
 * 
 * // Return SQL string for debugging
 * RunQuery([
 *     'conn' => $conn,
 *     'query' => 'SELECT * FROM users WHERE status = :status',
 *     'params' => [':status' => 'active'],
 *     'returnSql' => true
 * ]);
 * 
 * @param array $config Configuration array with following keys:
 *   - 'conn' (required): PDO database connection
 *   - 'query' (required): SQL query string
 *   - 'params' (optional): Array of parameters for prepared statement (default: [])
 *   - 'fetchAssoc' (optional): Return associative array (default: true)
 *   - 'withSuccess' (optional): Return success info with affected rows and ID (default: false)
 *   - 'returnSql' (optional): Return interpolated SQL string for debugging (default: false)
 * 
 * @return mixed Query results, success array, SQL string, or error array
 */
function RunQuery(array $config)
{
    // Extract parameters with defaults
    $conn = $config['conn'] ?? null;
    $query = $config['query'] ?? null;
    $parameterArray = $config['params'] ?? [];
    $dataAsASSOC = $config['fetchAssoc'] ?? true;
    $withSUCCESS = $config['withSuccess'] ?? false;
    $returnSql = $config['returnSql'] ?? false;

    // Validate required parameters
    if (!$conn) {
        return ['error' => 'Database connection (conn) is required'];
    }

    if (!$query) {
        return ['error' => 'SQL query (query) is required'];
    }

    try {
        // Return interpolated SQL for debugging if requested
        if ($returnSql) {
            return interpolateQuery($conn, $query, $parameterArray);
        }

        // Prepare and execute query
        $conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $stmt = $conn->prepare($query);

        if (!$stmt->execute($parameterArray)) {
            return ['error' => implode(', ', $stmt->errorInfo())];
        }

        // Fetch results
        $rows = $stmt->fetchAll($dataAsASSOC ? PDO::FETCH_ASSOC : PDO::FETCH_NUM);

        // Return detailed success information if requested
        if ($withSUCCESS) {
            $result = [
                'success' => true,
                'affected_rows' => $stmt->rowCount(),
                'id' => null,
                'data' => $rows
            ];

            // Get last insert ID for INSERT queries
            if (stripos(trim($query), 'insert') === 0) {
                $result['id'] = $conn->lastInsertId();
            }

            // Get ID from params for UPDATE queries
            if (stripos(trim($query), 'update') === 0 && isset($parameterArray[':id'])) {
                $result['id'] = $parameterArray[':id'];
            }

            return $result;
        }

        // Return simple row data
        return $rows;
        
    } catch (PDOException $e) {
        // Log the error only if LOG_ERRORS is enabled
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
