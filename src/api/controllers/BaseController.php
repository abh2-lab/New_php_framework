<?php

namespace App\Controllers;

use App\Core\Security;
use Dotenv\Dotenv;
use App\Core\Middleware\ValidationMiddleware;
use App\Core\Exceptions\ValidationException;



abstract class BaseController
{
    protected $conn;
    private static $envLoaded = false;

    public function __construct()
    {
        // Ensure environment variables are loaded first
        $this->ensureEnvironmentLoaded();

        // Security check
        Security::ensureSecure();

        // Initialize database connection
        require_once __DIR__ .  '/../core/Config/connection.php';

        $this->conn = $connpdo;
    }


    /**
     * Validate request with rules
     */
    protected function validateRequest(array $rules): array
    {
        return ValidationMiddleware::validate($rules);
    }

    /**
     * Ensure environment variables are loaded before anything else
     */
    private function ensureEnvironmentLoaded()
    {
        if (!self::$envLoaded) {
            $dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
            $dotenv->safeLoad();
            self::$envLoaded = true;
        }
    }

    /**
     * Send success response using your global sendJsonResponse function
     */
    protected function sendSuccess(string $message, $data = null, array $extra = []): void
    {
        sendJsonResponse(200, 'success', $message, $data, $extra);
    }

    /**
     * Send error response using your global sendJsonResponse function  
     */
    protected function sendError(string $message, int $statusCode = 400, $data = null): void
    {
        sendJsonResponse($statusCode, 'error', $message, $data);
    }

    /**
     * Send validation error response
     */
    protected function sendValidationError(string $message, array $errors = []): void
    {
        $data = !empty($errors) ? ['validation_errors' => $errors] : null;
        sendJsonResponse(422, 'error', $message, $data);
    }

    /**
     * Send server error response
     */
    protected function sendServerError(string $message = 'Internal server error'): void
    {
        sendJsonResponse(500, 'error', $message);
    }

    /**
     * Validate required fields in request data
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
     * Get query parameters ($_GET)
     */
    protected function getQueryParams(): array
    {
        return $this->sanitizeInput($_GET);
    }

}
