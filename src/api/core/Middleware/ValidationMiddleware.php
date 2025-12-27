<?php

namespace App\Core\Middleware;

use App\Core\Exceptions\ValidationException;

class ValidationMiddleware
{
    /**
     * Sanitize and validate request data
     */
    public static function validateRequest($rules = [])
    {
        $data = self::getRequestData();
        $sanitizedData = self::sanitizeData($data);
        $errors = self::validateData($sanitizedData, $rules);
        
        if (!empty($errors)) {
            throw new ValidationException('Validation failed', $errors);
        }
        
        return $sanitizedData;
    }

    /**
     * Get request data from all sources
     */
    private static function getRequestData(): array
    {
        $data = [];
        
        // GET parameters
        $data = array_merge($data, $_GET ?? []);
        
        // POST form data
        $data = array_merge($data, $_POST ?? []);
        
        // JSON body data
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'application/json') !== false) {
            $jsonInput = file_get_contents('php://input');
            $jsonData = json_decode($jsonInput, true);
            if (is_array($jsonData)) {
                $data = array_merge($data, $jsonData);
            }
        }
        
        return $data;
    }

    /**
     * Basic input sanitization
     */
    private static function sanitizeData(array $data): array
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                // Trim whitespace
                $value = trim($value);
                
                // Remove null bytes
                $value = str_replace("\0", '', $value);
                
                // Basic XSS protection
                $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
                
            } elseif (is_array($value)) {
                // Recursively sanitize arrays
                $value = self::sanitizeData($value);
            }
            
            $sanitized[$key] = $value;
        }
        
        return $sanitized;
    }

    /**
     * Validate data against rules
     */
    private static function validateData(array $data, array $rules): array
    {
        $errors = [];
        
        foreach ($rules as $field => $ruleString) {
            $fieldRules = explode('|', $ruleString);
            $value = $data[$field] ?? null;
            
            foreach ($fieldRules as $rule) {
                $error = self::validateField($field, $value, $rule);
                if ($error) {
                    if (!isset($errors[$field])) {
                        $errors[$field] = [];
                    }
                    $errors[$field][] = $error;
                }
            }
        }
        
        return $errors;
    }

    /**
     * Validate individual field against a single rule
     */
    private static function validateField(string $field, $value, string $rule): ?string
    {
        // Parse rule (e.g., "min:3", "max:50")
        $ruleParts = explode(':', $rule, 2);
        $ruleName = $ruleParts[0];
        $ruleValue = $ruleParts[1] ?? null;
        
        switch ($ruleName) {
            case 'required':
                if (empty($value) && $value !== '0') {
                    return "$field is required";
                }
                break;
                
            case 'email':
                if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return "$field must be a valid email address";
                }
                break;
                
            case 'min':
                if (!empty($value) && strlen($value) < (int)$ruleValue) {
                    return "$field must be at least $ruleValue characters";
                }
                break;
                
            case 'max':
                if (!empty($value) && strlen($value) > (int)$ruleValue) {
                    return "$field must not exceed $ruleValue characters";
                }
                break;
                
            case 'numeric':
                if (!empty($value) && !is_numeric($value)) {
                    return "$field must be a number";
                }
                break;
                
            case 'integer':
                if (!empty($value) && !filter_var($value, FILTER_VALIDATE_INT)) {
                    return "$field must be an integer";
                }
                break;
                
            case 'url':
                if (!empty($value) && !filter_var($value, FILTER_VALIDATE_URL)) {
                    return "$field must be a valid URL";
                }
                break;
                
            case 'alpha':
                if (!empty($value) && !preg_match('/^[a-zA-Z]+$/', $value)) {
                    return "$field may only contain letters";
                }
                break;
                
            case 'alphanumeric':
                if (!empty($value) && !preg_match('/^[a-zA-Z0-9]+$/', $value)) {
                    return "$field may only contain letters and numbers";
                }
                break;
        }
        
        return null;
    }

    /**
     * Quick validation helper for controllers
     */
    public static function validate(array $rules): array
    {
        return self::validateRequest($rules);
    }
}
