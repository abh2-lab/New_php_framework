<?php

namespace App\Core\Exceptions;

/**
 * Exception for not found errors (404)
 */
class NotFoundException extends BaseException
{
    protected int $httpStatusCode = 404;
    protected string $errorType = 'not_found';
}
