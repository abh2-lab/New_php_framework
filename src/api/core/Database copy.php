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
     * @param PDO $connection PDO connection instance (required)
     */
    public function __construct(PDO $connection)
    {
        $this->conn = $connection;

        $this->debug = !empty($_ENV['DEBUG_MODE']) &&
            ($_ENV['DEBUG_MODE'] === 'true' || $_ENV['DEBUG_MODE'] === '1');
    }

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

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function getTransactionLog(): array
    {
        return $this->transactionLog;
    }

    public function getConnection(): PDO
    {
        return $this->conn;
    }

    public function select(string $query, array $params = []): array
    {
        return $this->query([
            'query' => $query,
            'params' => $params
        ]);
    }

    public function insert(string $query, array $params = []): array
    {
        return $this->query([
            'query' => $query,
            'params' => $params,
            'withSuccess' => true
        ]);
    }

    public function update(string $query, array $params = []): array
    {
        return $this->query([
            'query' => $query,
            'params' => $params,
            'withSuccess' => true
        ]);
    }

    public function delete(string $query, array $params = []): array
    {
        return $this->query([
            'query' => $query,
            'params' => $params,
            'withSuccess' => true
        ]);
    }

    public function first(string $query, array $params = []): ?array
    {
        $results = $this->select($query, $params);
        return $results[0] ?? null;
    }

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
