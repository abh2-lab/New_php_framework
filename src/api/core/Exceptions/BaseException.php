<?php

namespace App\Core\Exceptions;

use Exception;
use App\Core\Logger; // Import the Logger

/**
 * Base exception class for the framework
 */
abstract class BaseException extends Exception
{
    protected int $httpStatusCode = 500;
    protected string $errorType = 'error';

    public function __construct(string $message = "", int $code = 0, \Throwable $previous = null)
    {
        // 1. Call parent constructor FIRST so $this->getFile() and getLine() are populated
        parent::__construct($message, $code, $previous);
        
        // 2. Automatically log the error the moment it happens.
        // It will safely log the message, file, and line without breaking child classes.
        Logger::exception($this);
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    public function getErrorType(): string
    {
        return $this->errorType;
    }

    /**
     * Convert exception to array for JSON response
     */
    public function toArray(): array
    {
        $data = [
            'error_type' => $this->getErrorType(),
            'message' => $this->getMessage(),
        ];

        if (!empty($_ENV['SHOW_ERRORS']) && ($_ENV['SHOW_ERRORS'] === 'true' || $_ENV['SHOW_ERRORS'] === '1')) {
            $data['debug_info'] = [
                'exception_class' => get_class($this),
                'file' => $this->getFile(),
                'line' => $this->getLine(),
                'stack_trace' => explode("\n", $this->getTraceAsString()),
            ];
        }

        return $data;
    }
}
