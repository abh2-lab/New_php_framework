<?php

namespace App\Core\Controllers;

use App\Core\BaseController;

class InitController extends BaseController
{
    public function __construct()
    {
        parent::__construct(); // env + db connection already handled in BaseController
    }

    /**
     * POST /runmigration
     * Runs all SQL migrations from: /src/api/databases/*.sql
     */
    public function migrateFromFile(): void
    {
        try {
            // FIX: Check $this->db instead of $this->conn
            if (!$this->db) {
                $this->sendError('Database connection not initialized', 500);
                return;
            }

            $migrationsDir = __DIR__ . '/../databases';
            if (!is_dir($migrationsDir)) {
                $this->sendError("Migrations directory not found: {$migrationsDir}", 404);
                return;
            }

            $files = glob($migrationsDir . '/*.sql');
            if (!$files || count($files) === 0) {
                $this->sendError("No .sql migration files found in: {$migrationsDir}", 404);
                return;
            }

            // Ensure deterministic execution order: 001_init.sql, 002_orders.sql, etc.
            sort($files, SORT_NATURAL);

            $results = [];
            $totalStatements = 0;
            $totalExecuted = 0;
            $allErrors = [];

            // Disable FK checks once for the whole batch (helps cross-file dependencies).
            // FIX: Access raw PDO via $this->db->getConnection()
            $this->db->getConnection()->exec('SET FOREIGN_KEY_CHECKS=0');

            foreach ($files as $filePath) {
                $filename = basename($filePath);

                $sqlContent = file_get_contents($filePath);
                if ($sqlContent === false || trim($sqlContent) === '') {
                    $allErrors[] = [
                        'file' => $filename,
                        'error' => 'File is empty or unreadable',
                    ];
                    $results[] = [
                        'file' => $filename,
                        'success' => false,
                        'statements_found' => 0,
                        'statements_executed' => 0,
                        'errors' => [['message' => 'File is empty or unreadable']],
                    ];
                    continue;
                }

                $fileResult = $this->executeSQLMigrationStatements($sqlContent, $filename);

                $results[] = $fileResult;
                $totalStatements += $fileResult['statements_found'] ?? 0;
                $totalExecuted  += $fileResult['statements_executed'] ?? 0;

                if (!empty($fileResult['errors'])) {
                    foreach ($fileResult['errors'] as $err) {
                        $allErrors[] = ['file' => $filename] + $err;
                    }
                }
            }

            // Re-enable FK checks
            // FIX: Access raw PDO via $this->db->getConnection()
            $this->db->getConnection()->exec('SET FOREIGN_KEY_CHECKS=1');

            if (empty($allErrors)) {
                $this->sendSuccess('All migrations executed successfully', [
                    'directory' => $migrationsDir,
                    'files_found' => count($files),
                    'total_statements_found' => $totalStatements,
                    'total_statements_executed' => $totalExecuted,
                    'files' => $results,
                ]);
                return;
            }

            $this->sendError('Migrations completed with errors', 500, [
                'directory' => $migrationsDir,
                'files_found' => count($files),
                'total_statements_found' => $totalStatements,
                'total_statements_executed' => $totalExecuted,
                'files' => $results,
                'errors' => $allErrors,
            ]);
        } catch (\Throwable $e) {
            // Best-effort FK restore
            // FIX: Check $this->db and use getConnection()
            try { 
                if ($this->db) {
                    $this->db->getConnection()->exec('SET FOREIGN_KEY_CHECKS=1');
                }
            } catch (\Throwable $t) {}
            
            $this->sendServerError('Migration failed: ' . $e->getMessage());
        }
    }

    /**
     * Splits SQL content into statements and executes each.
     * (More reliable than multi-statement exec for many PDO setups.)
     */
    private function executeSQLMigrationStatements(string $sqlContent, string $filename): array
    {
        try {
            // Remove simple comment/empty lines (keeps SQL mostly intact)
            $lines = preg_split("/\r\n|\n|\r/", $sqlContent);
            $cleaned = [];

            foreach ($lines as $line) {
                $trim = trim($line);
                if ($trim === '' || str_starts_with($trim, '--')) {
                    continue;
                }
                $cleaned[] = $line;
            }

            $cleanedSql = implode("\n", $cleaned);

            // Split into statements by semicolon
            $statements = array_values(array_filter(array_map('trim', explode(';', $cleanedSql))));
            $executed = 0;
            $errors = [];

            foreach ($statements as $i => $statement) {
                try {
                    // FIX: Access raw PDO via $this->db->getConnection()
                    $this->db->getConnection()->exec($statement);
                    $executed++;
                } catch (\PDOException $e) {
                    // ignore common re-run errors (duplicate key / table exists)
                    $code = (string) $e->getCode();
                    if ($code === '23000' || $code === '42S01') {
                        continue;
                    }
                    $errors[] = [
                        'statement' => $i + 1,
                        'code' => $code,
                        'message' => $e->getMessage(),
                    ];
                }
            }

            return [
                'file' => $filename,
                'success' => empty($errors),
                'statements_found' => count($statements),
                'statements_executed' => $executed,
                'errors' => $errors,
            ];
        } catch (\Throwable $e) {
            return [
                'file' => $filename,
                'success' => false,
                'statements_found' => 0,
                'statements_executed' => 0,
                'errors' => [[
                    'message' => $e->getMessage(),
                ]],
            ];
        }
    }
}
