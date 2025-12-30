<?php

namespace App\Controllers;

use Dotenv\Dotenv;

class InitController extends BaseController
{
    private $dotenv;

    public function __construct()
    {
        parent::__construct();

        $this->dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
        $this->dotenv->load();
    }

    /**
     * Run database migration from /api/tables file
     */
    public function migrateFromFile()
    {
        try {
            // Define the tables file path
            $tablesFilePath = __DIR__ . '/../tables';

            // Check if tables file exists
            if (!file_exists($tablesFilePath)) {
                $this->sendError('Tables file not found at /api/tables', 404);
                return;
            }

            // Read SQL content from tables file
            $sqlContent = file_get_contents($tablesFilePath);

            if ($sqlContent === false) {
                $this->sendError('Failed to read tables file', 500);
                return;
            }

            // Execute SQL migration
            $result = $this->executeSQLMigration($sqlContent, 'tables');

            if ($result['success']) {
                $this->sendSuccess('Migration completed successfully', $result);
            } else {
                $this->sendError('Migration failed: ' . $result['error'], 500, $result);
            }

        } catch (\Exception $e) {
            $this->sendServerError('Migration failed: ' . $e->getMessage());
        }
    }

    /**
     * Execute SQL migration using batch execution
     */
    private function executeSQLMigration(string $sqlContent, string $filename): array
    {
        try {
            // Wrap migration SQL with FK disable/enable for safe execution
            $wrappedSql = "SET FOREIGN_KEY_CHECKS=0;\n"
                . $sqlContent . "\n"
                . "SET FOREIGN_KEY_CHECKS=1;";

            // Execute entire SQL file as batch using PDO::exec()
            // This preserves all comments and handles multiple statements natively
            $affectedRows = $this->conn->exec($wrappedSql);

            return [
                'success' => true,
                'message' => 'Migration executed successfully',
                'filename' => $filename,
                'affected_rows' => $affectedRows
            ];

        } catch (\PDOException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
