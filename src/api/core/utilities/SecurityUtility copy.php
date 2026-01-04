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

        // FIX: CSP must contain plain sources, not markdown links
        // header(
        //     "Content-Security-Policy: " .
        //     "default-src 'self'; " .
        //     "script-src 'self' 'unsafe-inline' https://code.jquery.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; " .
        //     "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; " .
        //     "img-src 'self' data:; " .
        //     "font-src 'self' https://cdnjs.cloudflare.com; " .
        //     "connect-src 'self' https://cdn.jsdelivr.net"
        // );

        // NEW CODE
        header(
            "Content-Security-Policy: " .
            "default-src 'self'; " .
            "script-src 'self' 'unsafe-inline' https://code.jquery.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; " .
            "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; " .
            "img-src 'self' data:; " .
            "font-src 'self' https://cdnjs.cloudflare.com; " .
            // FIX: Added http://monitoring and ws://monitoring to allow SSE stream
            "connect-src 'self' https://cdn.jsdelivr.net http://monitoring ws://monitoring"
        );


        header('Referrer-Policy: strict-origin-when-cross-origin');
    }

    public static function handleCORS(): void
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
        // FIX: let .env override defaults / hardcoded caller values
        $maxRequests = (int) ($_ENV['RATELIMITREQUESTS'] ?? $maxRequests);
        $timeWindow = (int) ($_ENV['RATELIMITWINDOW'] ?? $timeWindow);

        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';

        $excludedPaths = [
            '/monitoring',
            '/docs',
            '/service-test',
            '/env',
            '/test', // add if you want tests never throttled
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
        file_put_contents($cacheFile, json_encode($requests), LOCK_EX); // FIX: avoid race
    }

    private static function getAllowedOrigins(): array
    {
        // FIX: match repo style key: ALLOWEDORIGINS (no underscore)
        $originsEnv = $_ENV['ALLOWEDORIGINS'] ?? '';

        if (trim($originsEnv) === '') {
            return []; // allow all
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
        // FIX: include Cloudflare + accept private IPs (important for docker/proxies)
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

            // X-Forwarded-For can contain multiple IPs: client, proxy1, proxy2...
            $parts = array_map('trim', explode(',', (string) $_SERVER[$key]));
            foreach ($parts as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '127.0.0.1';
    }
}
