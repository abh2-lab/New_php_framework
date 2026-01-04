<?php
// src/api/Core/BaseController.php

namespace App\Core;

use App\Core\Database;
use App\Core\Config\DatabaseConnection;
use App\Core\Utilities\ValidationUtility;
use Dotenv\Dotenv;
use App\Core\Security;

abstract class BaseController
{
    protected ?Database $db = null;     // Only keep this - remove $conn
    private static bool $envLoaded = false;

    public function __construct()
    {
        // Ensure environment variables are loaded first
        $this->ensureEnvironmentLoaded();

        // Skip security check for CLI scripts
        if (php_sapi_name() !== 'cli') {
            Security::ensureSecure();
        }

        // Initialize database using modern DatabaseConnection class
        try {
            $pdo = DatabaseConnection::pdo();
            $this->db = new Database($pdo);
        } catch (\Exception $e) {
            error_log('Database initialization failed: ' . $e->getMessage());
            $this->db = null;
        }
    }

    protected function validateRequest(array $rules): array
    {
        return ValidationUtility::validate($rules);
    }

    private function ensureEnvironmentLoaded(): void
    {
        if (!self::$envLoaded) {
            $dotenv = Dotenv::createImmutable(__DIR__ . '/../../..');
            $dotenv->safeLoad();
            self::$envLoaded = true;
        }
    }

    protected function sendSuccess(string $message, $data = null, array $extra = []): void
    {
        sendJsonResponse(200, 'success', $message, $data, $extra);
    }

    protected function sendError(string $message, int $statusCode = 400, $data = null): void
    {
        sendJsonResponse($statusCode, 'error', $message, $data);
    }

    protected function sendValidationError(string $message, array $errors): void
    {
        $data = !empty($errors) ? ['validation_errors' => $errors] : null;
        sendJsonResponse(422, 'error', $message, $data);
    }

    protected function sendServerError(string $message = "Internal server error"): void
    {
        sendJsonResponse(500, 'error', $message);
    }

    protected function validateRequired(array $data, array $required): array
    {
        $missing = [];
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
                $missing[] = $field;
            }
        }
        return $missing;
    }

    protected function sanitizeInput($input)
    {
        if (is_string($input)) {
            return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
        }
        if (is_array($input)) {
            return array_map([$this, 'sanitizeInput'], $input);
        }
        return $input;
    }

    protected function getRequestData(): array
    {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $data = $_POST;
        }

        return $this->sanitizeInput($data ?? []);
    }

    protected function getQueryParams(): array
    {
        return $this->sanitizeInput($_GET);
    }
}
