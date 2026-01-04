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

    private static ?array $requestStartCpu = null;

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

        // Increment active requests counter
        self::incActiveRequests();

        // Record start time and memory
        self::$requestStartTime = microtime(true);
        self::$requestStartMemory = memory_get_usage();

        // Capture CPU usage at start (if available)
        if (function_exists('getrusage')) {
            self::$requestStartCpu = getrusage();
        }

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

            // Calculate CPU time used (user + system) in milliseconds
            $cpuTimeMs = 0;
            if (self::$requestStartCpu !== null && function_exists('getrusage')) {
                $endCpu = getrusage();

                // User CPU time (microseconds)
                $userCpuUs = ($endCpu['ru_utime.tv_sec'] * 1000000 + $endCpu['ru_utime.tv_usec'])
                    - (self::$requestStartCpu['ru_utime.tv_sec'] * 1000000 + self::$requestStartCpu['ru_utime.tv_usec']);

                // System CPU time (microseconds)
                $sysCpuUs = ($endCpu['ru_stime.tv_sec'] * 1000000 + $endCpu['ru_stime.tv_usec'])
                    - (self::$requestStartCpu['ru_stime.tv_sec'] * 1000000 + self::$requestStartCpu['ru_stime.tv_usec']);

                // Convert to milliseconds and round
                $cpuTimeMs = round(($userCpuUs + $sysCpuUs) / 1000, 2);
            }

            // Get request details
            $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
            $uri = $_SERVER['REQUEST_URI'] ?? '/';
            $endpoint = parse_url($uri, PHP_URL_PATH);

            // Get response status code
            $statusCode = http_response_code();
            if ($statusCode === false) {
                $statusCode = 200; // Default if not set
            }

            // Determine if this is a system route using matched route tags
            $route = $GLOBALS['__fw_matched_route'] ?? null;
            $tags = is_array($route['tags'] ?? null) ? $route['tags'] : [];
            $isSystemRoute = in_array('System', $tags, true) || in_array('Monitoring', $tags, true);

            // Build metric data
            $metricData = [
                'timestamp' => date('Y-m-d H:i:s'),
                'method' => $method,
                'endpoint' => $endpoint,
                'full_uri' => $uri,
                'response_time' => $responseTime, // ms
                'cpu_time' => $cpuTimeMs, // ms
                'memory_used' => $memoryUsed, // MB
                'peak_memory' => $peakMemory, // MB
                'status_code' => $statusCode,
                'ip' => self::getClientIp(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                'is_system_route' => $isSystemRoute,
            ];

            // Add query parameters if present
            if (!empty($_SERVER['QUERY_STRING'])) {
                $metricData['query_string'] = $_SERVER['QUERY_STRING'];
            }

            // ========== START: DATABASE METRICS ==========
            // Add database query count
            $dbQueries = $GLOBALS['query_count'] ?? 0;
            $metricData['db_queries'] = $dbQueries;

            // Add database execution time metrics
            $dbTime = $GLOBALS['query_time'] ?? 0;
            $maxDbTime = $GLOBALS['max_query_time'] ?? 0;

            $metricData['db_time'] = round($dbTime, 2); // Total DB time in ms
            $metricData['max_db_time'] = round($maxDbTime, 2); // Slowest query in ms

            // Calculate average DB time per query
            if ($dbQueries > 0) {
                $metricData['avg_db_time'] = round($dbTime / $dbQueries, 2);
            } else {
                $metricData['avg_db_time'] = 0;
            }

            // Calculate DB time as percentage of total response time
            if ($responseTime > 0) {
                $metricData['db_percentage'] = round(($dbTime / $responseTime) * 100, 2);
            } else {
                $metricData['db_percentage'] = 0;
            }

            // Add database data size metrics
            $dbDataSize = $GLOBALS['db_data_size'] ?? 0;
            $maxDbDataSize = $GLOBALS['max_db_data_size'] ?? 0;

            $metricData['db_data_size'] = round($dbDataSize, 2); // Total data size in KB
            $metricData['max_db_data_size'] = round($maxDbDataSize, 2); // Largest query result in KB

            // Calculate average data size per query
            if ($dbQueries > 0) {
                $metricData['avg_db_data_size'] = round($dbDataSize / $dbQueries, 2);
            } else {
                $metricData['avg_db_data_size'] = 0;
            }
            // ========== END: DATABASE METRICS ==========

            // Log the metric
            self::$logger->log($metricData);

        } catch (\Exception $e) {
            // Fail silently - don't break the application
            error_log("MonitoringMiddleware Error: " . $e->getMessage());
        } finally {
            // Always decrement active requests counter, even if logging failed
            self::decActiveRequests();

            // Reset CPU tracking for next request
            self::$requestStartCpu = null;
        }
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
                'active_requests' => self::getActiveRequests(),
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

    // ==================== Active Requests Tracking ====================

    private static function activeRequestsKey(): string
    {
        return 'monitoring_active_requests';
    }

    private static function apcuAvailable(): bool
    {
        return function_exists('apcu_fetch')
            && function_exists('apcu_store')
            && function_exists('apcu_inc')
            && function_exists('apcu_dec');
    }

    private static function getActiveRequests(): int
    {
        $key = self::activeRequestsKey();

        if (self::apcuAvailable()) {
            $ok = false;
            $val = apcu_fetch($key, $ok);
            return $ok ? (int) $val : 0;
        }

        // Fallback: file-based counter
        $file = sys_get_temp_dir() . '/monitoring_active_requests.count';
        if (!file_exists($file)) {
            return 0;
        }

        $fp = @fopen($file, 'c+');
        if (!$fp) {
            return 0;
        }

        flock($fp, LOCK_SH);
        $raw = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return (int) trim($raw ?: '0');
    }

    private static function incActiveRequests(): void
    {
        $key = self::activeRequestsKey();

        if (self::apcuAvailable()) {
            $success = false;
            apcu_inc($key, 1, $success);
            if (!$success) {
                // Key doesn't exist, initialize it
                apcu_store($key, 1);
            }
            return;
        }

        // Fallback: file-based counter
        $file = sys_get_temp_dir() . '/monitoring_active_requests.count';
        $fp = @fopen($file, 'c+');
        if (!$fp) {
            return;
        }

        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $val = (int) trim($raw ?: '0');
        $val++;

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string) $val);

        flock($fp, LOCK_UN);
        fclose($fp);
    }

    private static function decActiveRequests(): void
    {
        $key = self::activeRequestsKey();

        if (self::apcuAvailable()) {
            $success = false;
            $newVal = apcu_dec($key, 1, $success);
            if (!$success) {
                // Key doesn't exist, initialize to 0
                apcu_store($key, 0);
            } elseif ($newVal !== false && $newVal < 0) {
                // Prevent negative values
                apcu_store($key, 0);
            }
            return;
        }

        // Fallback: file-based counter
        $file = sys_get_temp_dir() . '/monitoring_active_requests.count';
        $fp = @fopen($file, 'c+');
        if (!$fp) {
            return;
        }

        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $val = (int) trim($raw ?: '0');
        $val = max(0, $val - 1); // Prevent negative

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string) $val);

        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
