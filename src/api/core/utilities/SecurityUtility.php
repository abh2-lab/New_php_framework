<?php

namespace App\Core\Utilities;

class SecurityUtility
{
    public static function applySecurityHeaders(): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            header_remove('Server');
        }

        header('X-XSS-Protection: 1; mode=block');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');

        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        // TEMPORARY: More permissive CSP for debugging
        header(
            "Content-Security-Policy: " .
            "default-src 'self'; " .
            "script-src 'self' 'unsafe-inline' https://code.jquery.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; " .
            "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; " .
            "img-src 'self' data:; " .
            "font-src 'self' https://cdnjs.cloudflare.com; " .
            // Allow ALL connections temporarily for debugging
            "connect-src *;"
        );

        header('Referrer-Policy: strict-origin-when-cross-origin');
    }


    public static function handleCORS_old(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigins = self::getAllowedOrigins();

        if (in_array('*', $allowedOrigins, true)) {
            header("Access-Control-Allow-Origin: *");
            header("Access-Control-Allow-Credentials: false");
        } elseif (in_array($origin, $allowedOrigins, true) || empty($allowedOrigins)) {
            header("Access-Control-Allow-Origin: " . ($origin ?: '*'));
            header("Access-Control-Allow-Credentials: true");
        }

        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
        header("Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With, X-API-Key, Accept");
        header("Access-Control-Max-Age: 86400");

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(200);
            exit();
        }
    }

    public static function rateLimiting(int $maxRequests = 100, int $timeWindow = 3600): void
    {
        $maxRequests = (int) ($_ENV['RATE_LIMIT_REQUESTS'] ?? $maxRequests);
        $timeWindow = (int) ($_ENV['RATE_LIMIT_WINDOW'] ?? $timeWindow);

        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';

        $excludedPaths = [
            '/monitoring',
            '/docs',
            '/service-test',
            '/env',
            '/test',
        ];

        foreach ($excludedPaths as $p) {
            if (strpos($path, $p) === 0) {
                return;
            }
        }

        $clientIP = self::getClientIP();
        $cacheKey = "rate_limit_" . md5($clientIP);
        $cacheFile = sys_get_temp_dir() . '/' . $cacheKey;

        $requests = [];
        if (is_file($cacheFile)) {
            $requests = json_decode((string) file_get_contents($cacheFile), true) ?? [];
        }

        $currentTime = time();
        $requests = array_values(array_filter(
            $requests,
            fn($t) => ($currentTime - (int) $t) < $timeWindow
        ));

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

        $requests[] = $currentTime;
        file_put_contents($cacheFile, json_encode($requests), LOCK_EX);
    }

    private static function getAllowedOrigins(): array
    {
        $originsEnv = $_ENV['ALLOWED_ORIGINS'] ?? '';

        if (trim($originsEnv) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $originsEnv))));
    }

    private static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443)
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    }

    private static function getClientIP(): string
    {
        $ipKeys = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];

        foreach ($ipKeys as $key) {
            if (empty($_SERVER[$key]))
                continue;

            $parts = array_map('trim', explode(',', (string) $_SERVER[$key]));
            foreach ($parts as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '127.0.0.1';
    }




    public static function handleCORS(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigins = self::getAllowedOrigins();

        // 1. If the request's origin is in our allowed list (.env), explicitly allow it
        if ($origin && in_array($origin, $allowedOrigins, true)) {
            header("Access-Control-Allow-Origin: $origin");
            header("Access-Control-Allow-Credentials: true"); // CRITICAL for cookies!
            header("Vary: Origin");
        }
        // 2. Fallback for public API endpoints without cookies (e.g. Mobile Apps / Server-to-Server)
        else {
            header("Access-Control-Allow-Origin: *");
            // Browsers throw errors if Credentials=true when Origin=*
            header("Access-Control-Allow-Credentials: false");
        }

        // 3. Allow standard headers + Content-Type (required for JSON)
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
        header("Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With, Accept");
        header("Access-Control-Max-Age: 86400"); // Cache the preflight for 24 hours

        // 4. Handle the Preflight OPTIONS request
        // The browser sends this before actual requests to check permissions.
        // We MUST exit here so the preflight doesn't trigger the Router or Controllers.
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204); // 204 No Content is the standard response for OPTIONS
            exit(0);
        }
    }

}
