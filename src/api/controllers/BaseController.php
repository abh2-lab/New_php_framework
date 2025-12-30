<?php
// src/api/Core/BaseController.php

namespace App\Core;

use App\Core\Database;
use App\Core\Utilities\ValidationUtility;
use Dotenv\Dotenv;
use App\Core\Security;
use App\Core\Exceptions\DatabaseException;

abstract class BaseController
{
    protected $conn; // Legacy PDO connection (backward compatibility)
    protected Database $db; // New Database class instance
    private static bool $envLoaded = false;

    public function __construct()
    {
        // Ensure environment variables are loaded first
        $this->ensureEnvironmentLoaded();

        // Security check
      // Skip security check for CLI scripts (testing, cron jobs, etc.)
if (php_sapi_name() !== 'cli') {
    Security::ensureSecure();
}


        // Initialize database connection
        require_once __DIR__ . '/../Core/Config/connection.php';
        
        // Set legacy connection (backward compatibility)
        $this->conn = $connpdo;
        
        // Initialize new Database class
        try {
            $this->db = new Database($connpdo);
        } catch (DatabaseException $e) {
            error_log("Failed to initialize Database class: " . $e->getMessage());
            // Fall back to just using $this->conn if Database class fails
            $this->db = null;
        }
    }

    /**
     * Validate request with rules
     * 
     * @param array $rules Validation rules
     * @return array Validation result
     */
    protected function validateRequest(array $rules): array
    {
        return ValidationUtility::validate($rules);
    }

    /**
     * Ensure environment variables are loaded before anything else
     */
    private function ensureEnvironmentLoaded(): void
    {
        if (!self::$envLoaded) {
            $dotenv = Dotenv::createImmutable(__DIR__ . '/../../..');
            $dotenv->safeLoad();
            self::$envLoaded = true;
        }
    }

    /**
     * Send success response using your global sendJsonResponse function
     * 
     * @param string $message Success message
     * @param mixed $data Response data
     * @param array $extra Extra fields to include
     */
    protected function sendSuccess(string $message, $data = null, array $extra = []): void
    {
        sendJsonResponse(200, 'success', $message, $data, $extra);
    }

    /**
     * Send error response using your global sendJsonResponse function
     * 
     * @param string $message Error message
     * @param int $statusCode HTTP status code
     * @param mixed $data Additional error data
     */
    protected function sendError(string $message, int $statusCode = 400, $data = null): void
    {
        sendJsonResponse($statusCode, 'error', $message, $data);
    }

    /**
     * Send validation error response
     * 
     * @param string $message Error message
     * @param array $errors Validation errors array
     */
    protected function sendValidationError(string $message, array $errors): void
    {
        $data = !empty($errors) ? ['validation_errors' => $errors] : null;
        sendJsonResponse(422, 'error', $message, $data);
    }

    /**
     * Send server error response
     * 
     * @param string $message Error message (default: "Internal server error")
     */
    protected function sendServerError(string $message = "Internal server error"): void
    {
        sendJsonResponse(500, 'error', $message);
    }

    /**
     * Validate required fields in request data
     * 
     * @param array $data Request data
     * @param array $required Required field names
     * @return array Missing field names
     */
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

    /**
     * Sanitize input data
     * 
     * @param mixed $input Input to sanitize
     * @return mixed Sanitized input
     */
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

    /**
     * Get request data (POST/PUT/PATCH)
     * 
     * @return array Request data
     */
    protected function getRequestData(): array
    {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // If JSON decode fails, try to get from $_POST
            $data = $_POST;
        }

        return $this->sanitizeInput($data ?? []);
    }

    /**
     * Get query parameters (GET)
     * 
     * @return array Query parameters
     */
    protected function getQueryParams(): array
    {
        return $this->sanitizeInput($_GET);
    }
}
