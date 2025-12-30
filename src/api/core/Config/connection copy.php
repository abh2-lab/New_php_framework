<?php

use App\Core\Security;
// Security check using the new Security class
Security::ensureSecure();

// Your existing database connection code stays the same...
$currentHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';

if (strpos($currentHost, 'localhost') !== false || strpos($currentHost, '127.0.0.1') !== false) {
    $servername = "db";
    $port = "3306";
    $username = "root";
    $password = "abcd";
    $dbname = "connect_ping2";
} else {
    $servername = "phpmyadmin.coolify.vps.boomlive.in";
    $port = "3303";
    $username = "root";
    $password = "abcd";
    $dbname = "connect_ping2";
}

// PDO connection
try {
    $dsn = "mysql:host=$servername;port=$port;dbname=$dbname";
    $connpdo = new PDO($dsn, $username, $password);
    $connpdo->exec("SET time_zone = 'Asia/Kolkata'");
    $connpdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
    die();
}






// Enhanced interpolateQuery function
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
        }

        return str_replace($key, $value, $interpolatedQuery);
    }, $query);
}


/**
 * RunQuery function - Array-only parameter style (Standard)
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
 * // Fetch numeric array instead of associative
 * RunQuery([
 *     'conn' => $conn,
 *     'query' => 'SELECT * FROM users',
 *     'fetchAssoc' => false
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
