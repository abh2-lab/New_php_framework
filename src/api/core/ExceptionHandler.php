<?php

namespace App\Core;

use App\Core\Exceptions\BaseException;
use Throwable;
use Dotenv\Dotenv;

/**
 * Global exception handler for the framework
 */
class ExceptionHandler
{
    /**
     * Register the exception handler
     */
    public static function register(): void
    {
        set_exception_handler([self::class, 'handle']);
        set_error_handler([self::class, 'handleError']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /**
     * Handle uncaught exceptions
     */
    public static function handle(Throwable $exception): void
    {
        // Log the exception only if LOG_ERRORS is enabled
        if (self::shouldLogErrors()) {
            self::logException($exception);
        }

        // Check if we should show detailed errors
        $showErrors = self::shouldShowErrors();

        // If it's our custom exception, use its data
        if ($exception instanceof BaseException) {
            $statusCode = $exception->getHttpStatusCode();
            $errorData = $exception->toArray();
            
            // Add more debug info if SHOW_ERRORS is true
            if ($showErrors) {
                $errorData['debug_info'] = [
                    'exception_class' => get_class($exception),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'stack_trace' => explode("\n", $exception->getTraceAsString()),
                    'request_info' => [
                        'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
                        'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                    ]
                ];
            }
            
            sendJsonResponse(
                $statusCode,
                'error',
                $exception->getMessage(),
                $errorData
            );
        } else {
            // Handle generic PHP exceptions
            $statusCode = 500;
            $message = 'Internal server error';
            $data = ['error_type' => 'system_error'];

            // Show details if SHOW_ERRORS is enabled
            if ($showErrors) {
                $message = $exception->getMessage();
                $data = [
                    'error_type' => 'system_error',
                    'exception_class' => get_class($exception),
                    'message' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'stack_trace' => explode("\n", $exception->getTraceAsString()),
                    'request_info' => [
                        'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
                        'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                        'query_params' => $_GET ?? [],
                        'post_data' => $_POST ?? [],
                        'headers' => self::getRequestHeaders(),
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                    ],
                    'environment_info' => [
                        'php_version' => PHP_VERSION,
                        'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . ' MB',
                        'memory_peak' => round(memory_get_peak_usage() / 1024 / 1024, 2) . ' MB',
                        'execution_time' => round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2) . 'ms'
                    ]
                ];
            }

            sendJsonResponse($statusCode, 'error', $message, $data);
        }
    }

    /**
     * Handle PHP errors and convert them to exceptions
     */
    public static function handleError(int $severity, string $message, string $file, int $line): bool
    {
        // Don't handle suppressed errors (@)
        if (!(error_reporting() & $severity)) {
            return false;
        }

        // Convert error to exception
        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    /**
     * Handle fatal errors
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();
        
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            self::handle(new \ErrorException(
                $error['message'],
                0,
                $error['type'],
                $error['file'],
                $error['line']
            ));
        }
    }

    /**
     * Check if errors should be logged
     */
    private static function shouldLogErrors(): bool
    {
        // Try to get from $_ENV first
        if (isset($_ENV['LOG_ERRORS'])) {
            return ($_ENV['LOG_ERRORS'] === 'true' || $_ENV['LOG_ERRORS'] === '1');
        }
        
        // Fallback: try to load environment if not loaded
        try {
            $dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
            $dotenv->safeLoad();
            return !empty($_ENV['LOG_ERRORS']) && ($_ENV['LOG_ERRORS'] === 'true' || $_ENV['LOG_ERRORS'] === '1');
        } catch (\Exception $e) {
            // If environment loading fails, default to not logging
            return false;
        }
    }

    /**
     * Check if detailed errors should be shown
     */
    private static function shouldShowErrors(): bool
    {
        // Try to get from $_ENV first
        if (isset($_ENV['SHOW_ERRORS'])) {
            return ($_ENV['SHOW_ERRORS'] === 'true' || $_ENV['SHOW_ERRORS'] === '1');
        }
        
        // Fallback: try to load environment if not loaded
        try {
            $dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
            $dotenv->safeLoad();
            return !empty($_ENV['SHOW_ERRORS']) && ($_ENV['SHOW_ERRORS'] === 'true' || $_ENV['SHOW_ERRORS'] === '1');
        } catch (\Exception $e) {
            // If environment loading fails, default to not showing errors
            return false;
        }
    }

    /**
     * Log exception to file - only if LOG_ERRORS is enabled
     */
    private static function logException(Throwable $exception): void
    {
        $logMessage = sprintf(
            "[%s] %s: %s in %s:%d\n" .
            "Request: %s %s\n" .
            "User Agent: %s\n" .
            "IP: %s\n" .
            "Stack trace:\n%s\n" .
            "---------------------------------------------------------------------------------------\n",
            date('Y-m-d H:i:s'),
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            $_SERVER['REQUEST_URI'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $exception->getTraceAsString()
        );

        // Create logs directory if it doesn't exist
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        // Log to file with error handling
        try {
            file_put_contents($logDir . '/error.log', $logMessage, FILE_APPEND | LOCK_EX);
        } catch (\Exception $e) {
            // If we can't write to log file, at least try to log to PHP error log
            error_log("Failed to write to custom log file: " . $e->getMessage());
            error_log($logMessage);
        }
    }

    /**
     * Get request headers
     */
    private static function getRequestHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
                $headers[$header] = $value;
            }
        }
        return $headers;
    }
}
