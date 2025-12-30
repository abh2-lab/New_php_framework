<?php

namespace App\Core\Exceptions;

class ValidationException extends BaseException
{
    protected int $httpStatusCode = 422;
    protected string $errorType = 'validation_error';
    private array $validationErrors;

    public function __construct(string $message, array $validationErrors = [])
    {
        parent::__construct($message);
        $this->validationErrors = $validationErrors;
    }

    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    public function toArray(): array
    {
        $data = parent::toArray();
        $data['validation_errors'] = $this->validationErrors;
        return $data;
    }
}
