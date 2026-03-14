<?php
// src/api/Core/Database.php

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
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

    public function __construct(PDO $connection)
    {
        $this->conn = $connection;
        // Simple debug check
        $this->debug = !empty($_ENV['DEBUG_MODE']) && ($_ENV['DEBUG_MODE'] === 'true' || $_ENV['DEBUG_MODE'] === '1');
    }

    /**
     * Internal Executor: Handles logging, timing, and error mapping
     */
    private function executeStatement(string $sql, array $params = []): PDOStatement
    {
        try {
            // Ensure parameters are not emulated (native types)
            $this->conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

            $stmt = $this->conn->prepare($sql);

            // Global Performance Tracking
            if (!isset($GLOBALS['query_count']))
                $GLOBALS['query_count'] = 0;
            if (!isset($GLOBALS['query_time']))
                $GLOBALS['query_time'] = 0;
            if (!isset($GLOBALS['max_query_time']))
                $GLOBALS['max_query_time'] = 0;

            $GLOBALS['query_count']++;
            $startTime = microtime(true);

            if (!$stmt->execute($params)) {
                throw new DatabaseException(implode(', ', $stmt->errorInfo()));
            }

            // Timing
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $GLOBALS['query_time'] += $duration;
            if ($duration > $GLOBALS['max_query_time']) {
                $GLOBALS['max_query_time'] = $duration;
            }

            // Slow Query Log
            if ($this->debug && $duration > 500) {
                error_log("SLOW QUERY ({$duration}ms): " . substr($sql, 0, 100));
            }

            // Transaction Logging
            if ($this->inTransaction) {
                $this->transactionLog[] = [
                    'query' => $sql,
                    'params' => $params,
                    'time' => microtime(true)
                ];
            }

            return $stmt;

        } catch (PDOException $e) {
            if ($this->debug) {
                error_log("Database Error: " . $e->getMessage() . " | Query: " . $sql);
            }
            throw new DatabaseException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * SELECT multiple rows
     * @return array[] Array of associative arrays
     */
    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->executeStatement($sql, $params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->trackDataSize($rows);
        return $rows;
    }

    /**
     * Alias for query()
     */
    public function select(string $sql, array $params = []): array
    {
        return $this->query($sql, $params);
    }

    /**
     * SELECT single row
     * @return array|null Associative array or null if not found
     */
    public function first(string $sql, array $params = []): ?array
    {
        if (stripos($sql, 'LIMIT') === false) {
            $sql .= " LIMIT 1";
        }
        $stmt = $this->executeStatement($sql, $params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->trackDataSize($rows);

        return $rows[0] ?? null;
    }

    /**
     * INSERT query
     * @return int The Last Insert ID
     */
    public function insert(string $sql, array $params = []): int
    {
        $this->executeStatement($sql, $params);
        return (int) $this->conn->lastInsertId();
    }

    /**
     * UPDATE query
     * @return int Number of affected rows
     */
    public function update(string $sql, array $params = []): int
    {
        $stmt = $this->executeStatement($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * DELETE query
     * @return int Number of affected rows
     */
    public function delete(string $sql, array $params = []): int
    {
        $stmt = $this->executeStatement($sql, $params);
        return $stmt->rowCount();
    }

    // --- Transaction Methods (Standard) ---

    public function beginTransaction(): bool
    {
        if ($this->inTransaction)
            throw new DatabaseException('Transaction already in progress');
        try {
            $this->inTransaction = $this->conn->beginTransaction();
            $this->transactionLog = [];
            return $this->inTransaction;
        } catch (PDOException $e) {
            throw new DatabaseException('Failed to begin transaction: ' . $e->getMessage());
        }
    }

    public function commit(): bool
    {
        if (!$this->inTransaction)
            throw new DatabaseException('No active transaction');
        try {
            $res = $this->conn->commit();
            $this->inTransaction = false;
            $this->transactionLog = [];
            return $res;
        } catch (PDOException $e) {
            throw new DatabaseException('Failed to commit: ' . $e->getMessage());
        }
    }

    public function rollback(): bool
    {
        if (!$this->inTransaction)
            throw new DatabaseException('No active transaction');
        try {
            $res = $this->conn->rollBack();
            $this->inTransaction = false;
            $this->transactionLog = [];
            return $res;
        } catch (PDOException $e) {
            throw new DatabaseException('Failed to rollback: ' . $e->getMessage());
        }
    }

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

    // --- Accessor for raw PDO (Required for Migrations) ---

    public function getConnection(): PDO
    {
        return $this->conn;
    }

    // --- Helpers ---

    private function trackDataSize(array $rows): void
    {
        if (!isset($GLOBALS['db_data_size']))
            $GLOBALS['db_data_size'] = 0;
        if (!isset($GLOBALS['max_db_data_size']))
            $GLOBALS['max_db_data_size'] = 0;
        $size = strlen(serialize($rows)) / 1024;
        $GLOBALS['db_data_size'] += $size;
        if ($size > $GLOBALS['max_db_data_size'])
            $GLOBALS['max_db_data_size'] = $size;
    }

    public function getRawSql(string $query, array $params): string
    {
        $keys = array_keys($params);
        $values = array_values($params);
        return array_reduce($keys, function ($interpolated, $key) use ($values, $keys) {
            $val = $values[array_search($key, $keys)];
            if (is_string($val))
                $val = $this->conn->quote($val);
            elseif (is_bool($val))
                $val = $val ? '1' : '0';
            elseif (is_null($val))
                $val = 'NULL';
            return str_replace($key, $val, $interpolated);
        }, $query);
    }
}
