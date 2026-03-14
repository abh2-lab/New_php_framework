<?php

namespace App\Core\Exceptions;

/**
 * Exception for database errors
 */
class DatabaseException extends BaseException
{
    protected int $httpStatusCode = 500;
    protected string $errorType = 'database_error';

    public function __construct(string $message, int $code = 0, \Throwable $previous = null)
    {
        // Pass the REAL SQL error message to the parent so the Logger can record exactly what went wrong
        parent::__construct($message, $code, $previous);
    }

    /**
     * Override toArray to hide the real database error from the frontend API response in production
     */
    public function toArray(): array
    {
        // Get the base array (which contains the real message initially)
        $data = parent::toArray();

        // Mask the error message for the API response if not in debug mode
        if (empty($_ENV['DEBUG_MODE']) || ($_ENV['DEBUG_MODE'] !== 'true' && $_ENV['DEBUG_MODE'] !== '1')) {
            $data['message'] = 'A database error occurred';
        }

        return $data;
    }
}
