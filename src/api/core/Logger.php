<?php

namespace App\Core;

class Logger
{
    private static function enabled(): bool
    {
        return !empty($_ENV['LOG_ERRORS']) && in_array($_ENV['LOG_ERRORS'], ['true', '1'], true);
    }

    private static function logFile(): string
    {
        $dir = __DIR__ . '/../logs';

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir . '/error.log';
    }

    /**
     * Centralized file writing with fallback to system error_log
     */
    private static function writeToFile(string $logMessage): void
    {
        try {
            file_put_contents(self::logFile(), $logMessage, FILE_APPEND | LOCK_EX);
        } catch (\Exception $e) {
            // If we can't write to log file, at least try to log to PHP error log
            error_log("Failed to write to custom log file: " . $e->getMessage());
            error_log($logMessage);
        }
    }

    /**
     * Formats and logs standard messages (error, warning, info)
     */
    private static function write(string $level, string $message, array $context = []): void
    {
        if (!self::enabled()) {
            return;
        }

        $logMessage = sprintf(
            "[%s] [%s] %s\n" .
            "Request: %s %s\n" .
            "User Agent: %s\n" .
            "IP: %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            $_SERVER['REQUEST_URI'] ?? 'CLI',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        );

        if (!empty($context)) {
            // Print context array cleanly if provided
            $logMessage .= "Context:\n" . print_r($context, true) . "\n";
        }

        $logMessage .= "---------------------------------------------------------------------------------------\n";

        self::writeToFile($logMessage);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    /**
     * Formats and logs exceptions in your exact requested style
     */
    public static function exception(\Throwable $e, array $context = []): void
    {
        if (!self::enabled()) {
            return;
        }

        $logMessage = sprintf(
            "[%s] %s: %s in %s:%d\n" .
            "Request: %s %s\n" .
            "User Agent: %s\n" .
            "IP: %s\n",
            date('Y-m-d H:i:s'),
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            $_SERVER['REQUEST_URI'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        );

        if (!empty($context)) {
            $logMessage .= "Context:\n" . print_r($context, true) . "\n";
        }

        $logMessage .= "Stack trace:\n" . $e->getTraceAsString() . "\n";
        $logMessage .= "---------------------------------------------------------------------------------------\n";

        self::writeToFile($logMessage);
    }
}
