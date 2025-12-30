<?php
namespace App\Services;

/**
 * MetricsLogger - Handles monitoring metrics logging to JSON files
 * Automatically rotates logs older than 7 days
 */
class MetricsLogger
{
    private string $logDirectory;
    private int $retentionDays;

    public function __construct(int $retentionDays = 7)
    {
        $this->logDirectory = __DIR__ . '/../../logs/monitoring/';
        $this->retentionDays = $retentionDays;
        $this->ensureLogDirectoryExists();
    }

    /**
     * Log a metric entry to today's JSON file
     */
    public function log(array $metricData): bool
    {
        try {
            // Add timestamp if not present
            if (!isset($metricData['timestamp'])) {
                $metricData['timestamp'] = date('Y-m-d H:i:s');
            }

            // Get today's log file
            $filename = $this->getLogFilename();
            $filepath = $this->logDirectory . $filename;

            // Read existing data
            $existingData = [];
            if (file_exists($filepath)) {
                $content = file_get_contents($filepath);
                $existingData = json_decode($content, true) ?? [];
            }

            // Append new metric
            $existingData[] = $metricData;

            // Write back to file
            $jsonContent = json_encode($existingData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            file_put_contents($filepath, $jsonContent, LOCK_EX);

            // Cleanup old logs
            $this->cleanupOldLogs();

            return true;
        } catch (\Exception $e) {
            error_log("MetricsLogger Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get metrics from a specific date
     */
    public function getMetrics(string $date = null): array
    {
        $filename = $date ? "metrics-{$date}.json" : $this->getLogFilename();
        $filepath = $this->logDirectory . $filename;

        if (!file_exists($filepath)) {
            return [];
        }

        $content = file_get_contents($filepath);
        return json_decode($content, true) ?? [];
    }

    /**
     * Get metrics from last N days
     */
    public function getMetricsRange(int $days = 7): array
    {
        $allMetrics = [];

        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $metrics = $this->getMetrics($date);

            if (!empty($metrics)) {
                $allMetrics[$date] = $metrics;
            }
        }

        return $allMetrics;
    }


    public function getAggregatedStats(array $metrics): array
    {
        if (empty($metrics)) {
            return [];
        }

        $stats = [
            'total_requests' => count($metrics),

            'avg_response_time' => 0,
            'max_response_time' => 0,
            'min_response_time' => PHP_FLOAT_MAX,

            // NEW
            'p50_response_time' => 0,
            'p95_response_time' => 0,

            'avg_memory_used' => 0,
            'max_memory_used' => 0,

            'endpoints' => [],
            'status_codes' => [],
            'methods' => [],
        ];

        $responseTimes = [];
        $totalResponseTime = 0.0;

        $memoryValues = [];
        $totalMemory = 0.0;

        foreach ($metrics as $metric) {

            // -------- Response time (support snake_case and legacy) --------
            $rt = null;
            if (isset($metric['response_time'])) {
                $rt = $metric['response_time'];
            } elseif (isset($metric['responsetime'])) {
                $rt = $metric['responsetime'];
            } elseif (isset($metric['responseTime'])) {
                $rt = $metric['responseTime'];
            }

            if ($rt !== null) {
                $responseTime = (float) $rt;
                $responseTimes[] = $responseTime;
                $totalResponseTime += $responseTime;

                $stats['max_response_time'] = max($stats['max_response_time'], $responseTime);
                $stats['min_response_time'] = min($stats['min_response_time'], $responseTime);
            }

            // -------- Memory (support snake_case and legacy) --------
            $mu = null;
            if (isset($metric['memory_used'])) {
                $mu = $metric['memory_used'];
            } elseif (isset($metric['memoryused'])) {
                $mu = $metric['memoryused'];
            } elseif (isset($metric['memoryUsed'])) {
                $mu = $metric['memoryUsed'];
            }

            if ($mu !== null) {
                $memory = (float) $mu;
                $memoryValues[] = $memory;
                $totalMemory += $memory;

                $stats['max_memory_used'] = max($stats['max_memory_used'], $memory);
            }

            // -------- Endpoint tracking --------
            if (isset($metric['endpoint'])) {
                $endpoint = (string) $metric['endpoint'];

                if (!isset($stats['endpoints'][$endpoint])) {
                    $stats['endpoints'][$endpoint] = [
                        'count' => 0,
                        'total_time' => 0,
                    ];
                }

                $stats['endpoints'][$endpoint]['count']++;

                // total_time should track the same response-time field we found above
                if ($rt !== null) {
                    $stats['endpoints'][$endpoint]['total_time'] += (float) $rt;
                }
            }

            // -------- Status code tracking (support snake_case and legacy) --------
            $sc = null;
            if (isset($metric['status_code'])) {
                $sc = $metric['status_code'];
            } elseif (isset($metric['statuscode'])) {
                $sc = $metric['statuscode'];
            } elseif (isset($metric['statusCode'])) {
                $sc = $metric['statusCode'];
            }

            if ($sc !== null) {
                $code = (string) $sc;
                $stats['status_codes'][$code] = ($stats['status_codes'][$code] ?? 0) + 1;
            }

            // -------- HTTP method tracking --------
            if (isset($metric['method'])) {
                $method = (string) $metric['method'];
                $stats['methods'][$method] = ($stats['methods'][$method] ?? 0) + 1;
            }
        }

        // Averages should be based on samples that actually had the metric
        $rtCount = count($responseTimes);
        $stats['avg_response_time'] = $rtCount > 0 ? round($totalResponseTime / $rtCount, 2) : 0;

        $memCount = count($memoryValues);
        $stats['avg_memory_used'] = $memCount > 0 ? round($totalMemory / $memCount, 2) : 0;

        // Percentiles (linear interpolation)
        $percentile = function (array $vals, float $p): float {
            $n = count($vals);
            if ($n === 0)
                return 0.0;

            sort($vals);
            $pos = ($n - 1) * $p;
            $lo = (int) floor($pos);
            $hi = (int) ceil($pos);
            if ($lo === $hi)
                return (float) $vals[$lo];

            $w = $pos - $lo;
            return (float) $vals[$lo] * (1 - $w) + (float) $vals[$hi] * $w;
        };

        $stats['p50_response_time'] = round($percentile($responseTimes, 0.50), 2);
        $stats['p95_response_time'] = round($percentile($responseTimes, 0.95), 2);

        // Calculate endpoint averages (keep existing behavior)
        foreach ($stats['endpoints'] as $endpoint => &$data) {
            $data['avg_time'] = $data['count'] > 0 ? round($data['total_time'] / $data['count'], 2) : 0;
        }
        unset($data);

        if ($stats['min_response_time'] === PHP_FLOAT_MAX) {
            $stats['min_response_time'] = 0;
        }

        // Backward-compat keys (your MonitoringController JS reads these styles)
        $stats['totalrequests'] = $stats['total_requests'];
        $stats['avgresponsetime'] = $stats['avg_response_time'];
        $stats['p50responsetime'] = $stats['p50_response_time'];
        $stats['p95responsetime'] = $stats['p95_response_time'];

        return $stats;
    }



    /**
     * Get current log filename based on today's date
     */
    private function getLogFilename(): string
    {
        return 'metrics-' . date('Y-m-d') . '.json';
    }

    /**
     * Ensure log directory exists
     */
    private function ensureLogDirectoryExists(): void
    {
        if (!is_dir($this->logDirectory)) {
            mkdir($this->logDirectory, 0755, true);
        }
    }

    /**
     * Remove log files older than retention period
     */
    private function cleanupOldLogs(): void
    {
        $files = glob($this->logDirectory . 'metrics-*.json');
        $cutoffDate = strtotime("-{$this->retentionDays} days");

        foreach ($files as $file) {
            // Extract date from filename: metrics-YYYY-MM-DD.json
            preg_match('/metrics-(\d{4}-\d{2}-\d{2})\.json/', basename($file), $matches);

            if (isset($matches[1])) {
                $fileDate = strtotime($matches[1]);

                // Delete if older than retention period
                if ($fileDate < $cutoffDate) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * Get list of available log files
     */
    public function getAvailableLogDates(): array
    {
        $files = glob($this->logDirectory . 'metrics-*.json');
        $dates = [];

        foreach ($files as $file) {
            preg_match('/metrics-(\d{4}-\d{2}-\d{2})\.json/', basename($file), $matches);
            if (isset($matches[1])) {
                $dates[] = $matches[1];
            }
        }

        rsort($dates); // Most recent first
        return $dates;
    }

    /**
     * Clear all logs (useful for testing)
     */
    public function clearAllLogs(): bool
    {
        $files = glob($this->logDirectory . 'metrics-*.json');
        foreach ($files as $file) {
            @unlink($file);
        }
        return true;
    }
}
