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
        // Don't expose database details in production
        if (empty($_ENV['DEBUG_MODE']) || ($_ENV['DEBUG_MODE'] !== 'true' && $_ENV['DEBUG_MODE'] !== '1')) {
            $message = 'A database error occurred';
        }
        
        parent::__construct($message, $code, $previous);
    }
}
