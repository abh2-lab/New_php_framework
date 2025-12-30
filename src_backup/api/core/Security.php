<?php

namespace App\Core;

/**
 * Security class to handle framework security checks
 */
class Security
{
    private static bool $initialized = false;

    /**
     * Initialize security - should be called once at application start
     */
    public static function initialize(): void
    {
        if (self::$initialized) {
            return; // Already initialized
        }

        // Define the security constant
        if (!defined('GOODREQ')) {
            define('GOODREQ', true);
        }

        self::$initialized = true;
    }

    /**
     * Check if the security constant is properly set
     */
    public static function isSecure(): bool
    {
        return defined('GOODREQ') && GOODREQ === true;
    }

    /**
     * Ensure security is initialized, throw exception if not
     */
    public static function ensureSecure(): void
    {
        if (!self::isSecure()) {
            throw new \Exception('Security check failed - unauthorized access attempt');
        }
    }
}
