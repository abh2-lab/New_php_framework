<?php

namespace App\Core\Exceptions;

use Exception;

/**
 * Base exception class for the framework
 */
abstract class BaseException extends Exception
{
    protected int $httpStatusCode = 500;
    protected string $errorType = 'error';

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

        // Add debug info only if SHOW_ERRORS is enabled
        if (!empty($_ENV['SHOW_ERRORS']) && ($_ENV['SHOW_ERRORS'] === 'true' || $_ENV['SHOW_ERRORS'] === '1')) {
            $data['debug_info'] = [
                'exception_class' => get_class($this),
                'file' => $this->getFile(),
                'line' => $this->getLine(),
                'stack_trace' => explode("\n", $this->getTraceAsString())
            ];
        }

        return $data;
    }
}
