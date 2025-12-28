<?php

namespace App\Core\Utilities;

class SecurityUtility
{
    /**
     * Apply security headers
     */
    public static function applySecurityHeaders(): void
    {
        // Remove server signature
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            header_remove('Server');
        }

        // XSS Protection
        header('X-XSS-Protection: 1; mode=block');

        // Prevent MIME type sniffing
        header('X-Content-Type-Options: nosniff');

        // Clickjacking protection
        header('X-Frame-Options: DENY');

        // HSTS (HTTP Strict Transport Security) - only for HTTPS
        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        // Content Security Policy (basic)
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://code.jquery.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; img-src 'self' data:; font-src 'self' https://cdnjs.cloudflare.com; connect-src 'self' https://cdn.jsdelivr.net");


        // Referrer Policy
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }





    public static function handleCORS(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigins = self::getAllowedOrigins();

        // Check if wildcard (*) is allowed
        if (in_array('*', $allowedOrigins)) {
            header("Access-Control-Allow-Origin: *");
            // Note: Cannot use credentials with wildcard
            header("Access-Control-Allow-Credentials: false");
        } elseif (in_array($origin, $allowedOrigins) || empty($allowedOrigins)) {
            // Allow specific origin with credentials
            header("Access-Control-Allow-Origin: " . ($origin ?: '*'));
            header("Access-Control-Allow-Credentials: true");
        }

        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
        header("Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With, X-API-Key, Accept");
        header("Access-Control-Max-Age: 86400");

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit();
        }
    }

    /**
     * Rate limiting (basic implementation)
     */
    public static function rateLimiting(int $maxRequests = 100, int $timeWindow = 3600): void
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';

        // Exclude system/monitoring routes from rate limiting
        $excludedPaths = [
            '/monitoring',
            '/docs',
            '/service-test',
            '/env/'
        ];

        foreach ($excludedPaths as $path) {
            if (strpos($requestUri, $path) !== false) {
                return; // Skip rate limiting for these endpoints
            }
        }

        $clientIP = self::getClientIP();
        $cacheKey = "rate_limit_" . md5($clientIP);
        $cacheFile = sys_get_temp_dir() . '/' . $cacheKey;

        $requests = [];
        if (file_exists($cacheFile)) {
            $requests = json_decode(file_get_contents($cacheFile), true) ?? [];
        }

        $currentTime = time();
        // Remove old requests outside time window
        $requests = array_filter($requests, fn($time) => ($currentTime - $time) < $timeWindow);

        if (count($requests) >= $maxRequests) {
            http_response_code(429);
            header('Content-Type: application/json');
            header('Retry-After: ' . $timeWindow);
            echo json_encode([
                'status' => 'error',
                'message' => 'Rate limit exceeded. Too many requests.',
                'data' => [
                    'max_requests' => $maxRequests,
                    'time_window' => $timeWindow,
                    'retry_after' => $timeWindow
                ]
            ]);
            exit();
        }

        // Add current request
        $requests[] = $currentTime;
        file_put_contents($cacheFile, json_encode($requests));
    }


    /**
     * Get allowed origins from environment or default
     */
    private static function getAllowedOrigins(): array
    {
        $originsEnv = $_ENV['ALLOWED_ORIGINS'] ?? '';

        if (empty($originsEnv)) {
            return []; // Allow all origins
        }

        return array_map('trim', explode(',', $originsEnv));
    }

    /**
     * Check if connection is HTTPS
     */
    private static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || $_SERVER['SERVER_PORT'] == 443
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    }

    /**
     * Get real client IP address
     */
    private static function getClientIP(): string
    {
        $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];

        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ips = explode(',', $_SERVER[$key]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}
