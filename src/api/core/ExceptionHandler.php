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
        // Only log if it's NOT a BaseException, because BaseExceptions already log themselves on creation
        if (!($exception instanceof BaseException)) {
            Logger::exception($exception);
        }

        $showErrors = self::shouldShowErrors();

        if ($exception instanceof BaseException) {
            $statusCode = $exception->getHttpStatusCode();
            $errorData = $exception->toArray();

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
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    ],
                ];
            }

            sendJsonResponse(
                $statusCode,
                'error',
                $errorData['message'] ?? 'An error occurred',
                $errorData
            );

            return;
        }

        $statusCode = 500;
        $message = 'Internal server error';
        $data = [
            'error_type' => 'system_error',
        ];

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
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ],
                'environment_info' => [
                    'php_version' => PHP_VERSION,
                    'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . ' MB',
                    'memory_peak' => round(memory_get_peak_usage() / 1024 / 1024, 2) . ' MB',
                    'execution_time' => round(
                        (microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000,
                        2
                    ) . 'ms',
                ],
            ];
        }

        sendJsonResponse($statusCode, 'error', $message, $data);
    }

    /**
     * Handle PHP errors and convert them to exceptions
     */
    public static function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    /**
     * Handle fatal errors
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
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
     * Check if detailed errors should be shown
     */
    private static function shouldShowErrors(): bool
    {
        if (isset($_ENV['SHOW_ERRORS'])) {
            return $_ENV['SHOW_ERRORS'] === 'true' || $_ENV['SHOW_ERRORS'] === '1';
        }

        try {
            $dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
            $dotenv->safeLoad();

            return !empty($_ENV['SHOW_ERRORS'])
                && ($_ENV['SHOW_ERRORS'] === 'true' || $_ENV['SHOW_ERRORS'] === '1');
        } catch (\Throwable $e) {
            return false;
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
                $header = str_replace(
                    ' ',
                    '-',
                    ucwords(str_replace('_', ' ', strtolower(substr($key, 5))))
                );
                $headers[$header] = $value;
            }
        }

        return $headers;
    }
}
