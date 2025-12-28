<?php
namespace App\Core\Middlewares;

use App\Services\MetricsLogger;

/**
 * MonitoringMiddleware - Captures request performance metrics
 * Logs data after response is sent to avoid impacting user experience
 */
class MonitoringMiddleware
{
    private static ?float $requestStartTime = null;
    private static ?int $requestStartMemory = null;
    private static ?MetricsLogger $logger = null;
    private static bool $enabled = false;

    /**
     * Initialize monitoring for current request
     */
    public static function start(): void
    {
        // Check if monitoring is enabled
        self::$enabled = !empty($_ENV['ENABLE_MONITORING']) &&
            ($_ENV['ENABLE_MONITORING'] === 'true' || $_ENV['ENABLE_MONITORING'] === '1');

        if (!self::$enabled) {
            return;
        }

        $GLOBALS['current_route_is_system'] = false;


        // Record start time and memory
        self::$requestStartTime = microtime(true);
        self::$requestStartMemory = memory_get_usage();

        // Initialize logger
        if (self::$logger === null) {
            self::$logger = new MetricsLogger();
        }

        // Register shutdown function to log after response is sent
        register_shutdown_function([self::class, 'logMetrics']);
    }

    /**
     * Log metrics after response is sent (called via shutdown function)
     */
    public static function logMetrics(): void
    {
        if (!self::$enabled || self::$requestStartTime === null) {
            return;
        }

        try {
            // Calculate metrics
            $responseTime = round((microtime(true) - self::$requestStartTime) * 1000, 2); // milliseconds
            $memoryUsed = round((memory_get_usage() - self::$requestStartMemory) / 1024 / 1024, 2); // MB
            $peakMemory = round(memory_get_peak_usage() / 1024 / 1024, 2); // MB

            // Get request details
            $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
            $uri = $_SERVER['REQUEST_URI'] ?? '/';
            $endpoint = parse_url($uri, PHP_URL_PATH);

            // Get response status code
            $statusCode = http_response_code();
            if ($statusCode === false) {
                $statusCode = 200; // Default if not set
            }

            // Check if this is a system route
            $isSystemRoute = self::isSystemRoute($endpoint);

            // Build metric data
            $metricData = [
                'timestamp' => date('Y-m-d H:i:s'),
                'method' => $method,
                'endpoint' => $endpoint,
                'full_uri' => $uri,
                'response_time' => $responseTime, // ms
                'memory_used' => $memoryUsed, // MB
                'peak_memory' => $peakMemory, // MB
                'status_code' => $statusCode,
                'ip' => self::getClientIp(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                'is_system_route' => (bool)($GLOBALS['current_route_is_system'] ?? false),

            ];

            // Add query parameters if present
            if (!empty($_SERVER['QUERY_STRING'])) {
                $metricData['query_string'] = $_SERVER['QUERY_STRING'];
            }

            // Add database query count if available (from connection.php)
            if (isset($GLOBALS['query_count'])) {
                $metricData['db_queries'] = $GLOBALS['query_count'];
            } else {
                $metricData['db_queries'] = 0;
            }

            // Log the metric
            self::$logger->log($metricData);

        } catch (\Exception $e) {
            // Fail silently - don't break the application
            error_log("MonitoringMiddleware Error: " . $e->getMessage());
        }
    }

    /**
     * Check if endpoint is a system route
     */
    private static function isSystemRoute(string $endpoint): bool
    {
        $systemPrefixes = ['/monitoring', '/docs', '/env', '/service-test', '/runmigration'];
        
        foreach ($systemPrefixes as $prefix) {
            if (strpos($endpoint, $prefix) === 0) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get the real client IP address
     */
    private static function getClientIp(): string
    {
        $ipKeys = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];

        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Handle multiple IPs in X-Forwarded-For
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                return $ip;
            }
        }

        return 'Unknown';
    }

    /**
     * Get current resource usage (for live monitoring)
     */
    public static function getCurrentResourceUsage(): array
    {
        // Get memory info with percentage
        $memoryLimit = ini_get('memory_limit');
        $limitMB = self::convertMemoryToMB($memoryLimit);
        $currentMB = round(memory_get_usage() / 1024 / 1024, 2);
        $peakMB = round(memory_get_peak_usage() / 1024 / 1024, 2);
        $memoryPercent = $limitMB > 0 ? round(($currentMB / $limitMB) * 100, 1) : 0;

        $data = [
            'timestamp' => date('Y-m-d H:i:s'),
            'memory' => [
                'current' => $currentMB,
                'peak' => $peakMB,
                'limit' => $limitMB,
                'limit_formatted' => $memoryLimit,
                'percentage' => $memoryPercent
            ],
            'server' => [
                'uptime' => self::getServerUptime(),
                'load_average' => self::getLoadAverage(),
            ],
            'php' => [
                'version' => PHP_VERSION,
                'max_execution_time' => ini_get('max_execution_time'),
            ]
        ];

        // Add CPU usage if available (Unix/Linux only)
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            $data['server']['cpu_load'] = [
                '1min' => round($load[0], 2),
                '5min' => round($load[1], 2),
                '15min' => round($load[2], 2)
            ];
        }

        // Add disk usage
        $data['disk'] = self::getDiskUsage();

        return $data;
    }

    /**
     * Convert memory limit string to MB
     */
    private static function convertMemoryToMB(string $memory): float
    {
        if ($memory === '-1') {
            return 0; // Unlimited
        }

        $unit = strtoupper(substr($memory, -1));
        $value = (int) $memory;
        
        switch ($unit) {
            case 'G':
                return $value * 1024;
            case 'M':
                return $value;
            case 'K':
                return round($value / 1024, 2);
            default:
                // Assume bytes if no unit
                return round($value / 1024 / 1024, 2);
        }
    }

    /**
     * Get server uptime
     */
    private static function getServerUptime(): ?string
    {
        if (file_exists('/proc/uptime')) {
            $uptime = file_get_contents('/proc/uptime');
            $uptimeSeconds = (int) explode(' ', $uptime)[0];

            $days = floor($uptimeSeconds / 86400);
            $hours = floor(($uptimeSeconds % 86400) / 3600);
            $minutes = floor(($uptimeSeconds % 3600) / 60);

            return "{$days}d {$hours}h {$minutes}m";
        }

        return null;
    }

    /**
     * Get load average
     */
    private static function getLoadAverage(): ?array
    {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            return [
                '1min' => round($load[0], 2),
                '5min' => round($load[1], 2),
                '15min' => round($load[2], 2)
            ];
        }

        return null;
    }

    /**
     * Get disk usage
     */
    private static function getDiskUsage(): array
    {
        $rootPath = __DIR__ . '/../../';

        $total = @disk_total_space($rootPath);
        $free = @disk_free_space($rootPath);

        if ($total === false || $free === false) {
            return [
                'total' => 'N/A',
                'free' => 'N/A',
                'used' => 'N/A',
                'used_percent' => 'N/A'
            ];
        }

        $used = $total - $free;
        $usedPercent = round(($used / $total) * 100, 2);

        return [
            'total' => round($total / 1024 / 1024 / 1024, 2) . ' GB',
            'free' => round($free / 1024 / 1024 / 1024, 2) . ' GB',
            'used' => round($used / 1024 / 1024 / 1024, 2) . ' GB',
            'used_percent' => $usedPercent
        ];
    }

    /**
     * Check database connectivity
     */
    public static function checkDatabaseHealth(): array
    {
        try {
            // Get database credentials from environment
            $host = $_ENV['DB_HOST'] ?? 'localhost';
            $dbname = $_ENV['DB_NAME'] ?? '';
            $username = $_ENV['DB_USER'] ?? '';
            $password = $_ENV['DB_PASS'] ?? '';
            $port = $_ENV['DB_PORT'] ?? 3306;

            if (empty($dbname) || empty($username)) {
                return [
                    'status' => 'unhealthy',
                    'message' => 'Database credentials not configured'
                ];
            }

            // Create a fresh PDO connection for health check
            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
            $pdo = new \PDO($dsn, $username, $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 5 // 5 second timeout
            ]);

            // Test the connection
            $pdo->query('SELECT 1');

            return [
                'status' => 'healthy',
                'message' => 'Database connection is active',
                'database' => $dbname,
                'host' => $host
            ];

        } catch (\PDOException $e) {
            return [
                'status' => 'unhealthy',
                'message' => 'Database connection failed: ' . $e->getMessage()
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'unhealthy',
                'message' => 'Error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Mark a custom performance marker
     */
    public static function mark(string $label, array $additionalData = []): void
    {
        if (!self::$enabled || self::$logger === null) {
            return;
        }

        $markerData = array_merge([
            'timestamp' => date('Y-m-d H:i:s'),
            'type' => 'marker',
            'label' => $label,
            'memory' => round(memory_get_usage() / 1024 / 1024, 2),
        ], $additionalData);

        self::$logger->log($markerData);
    }
}
