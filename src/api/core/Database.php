<?php
// src/api/Core/Database.php

namespace App\Core;

use PDO;
use PDOException;
use App\Core\Exceptions\DatabaseException;

/**
 * Database Class - Modern PDO wrapper with transaction support
 * 
 * Provides a clean, chainable interface for database operations
 * with built-in transaction management and error handling.
 */
class Database
{
    private PDO $conn;
    private bool $inTransaction = false;
    private array $transactionLog = [];
    private bool $debug = false;

    /**
     * Constructor
     * 
     * @param PDO|null $connection PDO connection instance (uses global $connpdo if null)
     */
    public function __construct(?PDO $connection = null)
    {
        global $connpdo;
        
        if ($connection === null && !isset($connpdo)) {
            throw new DatabaseException('Database connection not available. Make sure connection.php is loaded.');
        }
        
        $this->conn = $connection ?? $connpdo;
        $this->debug = !empty($_ENV['DEBUG_MODE']) && 
                       ($_ENV['DEBUG_MODE'] === 'true' || $_ENV['DEBUG_MODE'] === '1');
    }

    /**
     * Execute a query with the same interface as RunQuery
     * 
     * @param array $config Query configuration
     * @return mixed Query results or success info
     * @throws DatabaseException On database errors
     */
    public function query(array $config)
    {
        $query = $config['query'] ?? null;
        $params = $config['params'] ?? [];
        $fetchAssoc = $config['fetchAssoc'] ?? true;
        $withSuccess = $config['withSuccess'] ?? false;
        $returnSql = $config['returnSql'] ?? false;

        if (!$query) {
            throw new DatabaseException('SQL query is required');
        }

        try {
            if ($returnSql) {
                return $this->interpolateQuery($query, $params);
            }

            $this->conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $stmt = $this->conn->prepare($query);

            if (!$stmt->execute($params)) {
                throw new DatabaseException(implode(', ', $stmt->errorInfo()));
            }

            // Log transaction queries
            if ($this->inTransaction) {
                $this->transactionLog[] = [
                    'query' => $query,
                    'params' => $params,
                    'time' => microtime(true)
                ];
            }

            $rows = $stmt->fetchAll($fetchAssoc ? PDO::FETCH_ASSOC : PDO::FETCH_NUM);

            if ($withSuccess) {
                $result = [
                    'success' => true,
                    'affected_rows' => $stmt->rowCount(),
                    'id' => null,
                    'data' => $rows
                ];

                if (stripos(trim($query), 'insert') === 0) {
                    $result['id'] = (int)$this->conn->lastInsertId();
                }

                if (stripos(trim($query), 'update') === 0 && isset($params[':id'])) {
                    $result['id'] = $params[':id'];
                }

                return $result;
            }

            return $rows;

        } catch (PDOException $e) {
            if ($this->debug) {
                error_log("Database Error: " . $e->getMessage() . " | Query: " . $query);
            }
            throw new DatabaseException($e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Begin a transaction
     * 
     * @return bool Success status
     * @throws DatabaseException If transaction already in progress
     */
    public function beginTransaction(): bool
    {
        if ($this->inTransaction) {
            throw new DatabaseException('Transaction already in progress');
        }

        try {
            $this->transactionLog = [];
            $this->inTransaction = $this->conn->beginTransaction();
            
            if ($this->debug) {
                error_log("Transaction started");
            }
            
            return $this->inTransaction;
        } catch (PDOException $e) {
            throw new DatabaseException('Failed to begin transaction: ' . $e->getMessage());
        }
    }

    /**
     * Commit a transaction
     * 
     * @return bool Success status
     * @throws DatabaseException If no active transaction
     */
    public function commit(): bool
    {
        if (!$this->inTransaction) {
            throw new DatabaseException('No active transaction to commit');
        }

        try {
            $result = $this->conn->commit();
            $this->inTransaction = false;
            
            if ($this->debug) {
                error_log('Transaction committed with ' . count($this->transactionLog) . ' queries');
            }
            
            $this->transactionLog = [];
            return $result;
        } catch (PDOException $e) {
            throw new DatabaseException('Failed to commit transaction: ' . $e->getMessage());
        }
    }

    /**
     * Rollback a transaction
     * 
     * @return bool Success status
     * @throws DatabaseException If no active transaction
     */
    public function rollback(): bool
    {
        if (!$this->inTransaction) {
            throw new DatabaseException('No active transaction to rollback');
        }

        try {
            $result = $this->conn->rollBack();
            $this->inTransaction = false;
            
            if ($this->debug) {
                error_log('Transaction rolled back. Queries executed: ' . count($this->transactionLog));
            }
            
            $this->transactionLog = [];
            return $result;
        } catch (PDOException $e) {
            throw new DatabaseException('Failed to rollback transaction: ' . $e->getMessage());
        }
    }

    /**
     * Execute a transaction with automatic commit/rollback
     * Similar to CI4's transStart/transComplete
     * 
     * @param callable $callback Function to execute within transaction
     * @return mixed Result from callback
     * @throws DatabaseException|\Exception On any error
     */
    public function transaction(callable $callback)
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Check if currently in a transaction
     * 
     * @return bool
     */
    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    /**
     * Get transaction log (for debugging)
     * 
     * @return array
     */
    public function getTransactionLog(): array
    {
        return $this->transactionLog;
    }

    /**
     * Get the PDO connection
     * 
     * @return PDO
     */
    public function getConnection(): PDO
    {
        return $this->conn;
    }

    /**
     * Quick helper: SELECT query
     * 
     * @param string $query SQL query
     * @param array $params Query parameters
     * @return array Result rows
     */
    public function select(string $query, array $params = []): array
    {
        return $this->query([
            'query' => $query,
            'params' => $params
        ]);
    }

    /**
     * Quick helper: INSERT query with success info
     * 
     * @param string $query SQL query
     * @param array $params Query parameters
     * @return array Success info with inserted ID
     */
    public function insert(string $query, array $params = []): array
    {
        return $this->query([
            'query' => $query,
            'params' => $params,
            'withSuccess' => true
        ]);
    }

    /**
     * Quick helper: UPDATE query with success info
     * 
     * @param string $query SQL query
     * @param array $params Query parameters
     * @return array Success info
     */
    public function update(string $query, array $params = []): array
    {
        return $this->query([
            'query' => $query,
            'params' => $params,
            'withSuccess' => true
        ]);
    }

    /**
     * Quick helper: DELETE query with success info
     * 
     * @param string $query SQL query
     * @param array $params Query parameters
     * @return array Success info
     */
    public function delete(string $query, array $params = []): array
    {
        return $this->query([
            'query' => $query,
            'params' => $params,
            'withSuccess' => true
        ]);
    }

    /**
     * Quick helper: Get first row only
     * 
     * @param string $query SQL query
     * @param array $params Query parameters
     * @return array|null First row or null
     */
    public function first(string $query, array $params = []): ?array
    {
        $results = $this->select($query, $params);
        return $results[0] ?? null;
    }

    /**
     * Interpolate query for debugging
     * 
     * @param string $query SQL query
     * @param array $params Parameters
     * @return string Interpolated query
     */
    private function interpolateQuery(string $query, array $params): string
    {
        $keys = array_keys($params);
        $values = array_values($params);

        return array_reduce($keys, function ($interpolatedQuery, $key) use ($values, $keys) {
            $value = $values[array_search($key, $keys)];

            if (is_string($value)) {
                $value = $this->conn->quote($value);
            } elseif (is_array($value)) {
                $value = implode(',', array_map([$this->conn, 'quote'], $value));
            } elseif (is_null($value)) {
                $value = 'NULL';
            } elseif (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            return str_replace($key, $value, $interpolatedQuery);
        }, $query);
    }
}
