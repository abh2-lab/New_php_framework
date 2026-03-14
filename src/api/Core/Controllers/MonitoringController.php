<?php

namespace App\Core\Controllers;

use App\Core\BaseController;
use App\Services\MetricsLogger;
use App\Core\Middlewares\MonitoringMiddleware;

class MonitoringController extends BaseController
{
    private MetricsLogger $logger;
    private static array $activeViewers = [];
    private const VIEWER_TIMEOUT = 30;

    public function __construct()
    {
        parent::__construct();

        if (!$this->isMonitoringEnabled()) {
            $this->sendError('Monitoring is disabled', 403);
            exit;
        }

        $this->logger = new MetricsLogger();
    }

    public function index(): void
    {
        $this->renderDashboard();
    }



    public function stream(): void
    {
        // 1. Set SSE Headers
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // Critical for Nginx

        // 2. CRITICAL: Clear ALL Output Buffers
        // We loop to clear buffers from index.php (ob_start) and php.ini (zlib/output_buffering)
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // 3. Enable Implicit Flush 
        // Ensures every echo is sent to the browser immediately
        ob_implicit_flush(true);

        $viewerId = $_GET['viewerid'] ?? $this->generateViewerId();
        $maxViewers = (int) ($_ENV['MAX_MONITORING_VIEWERS'] ?? 2);

        // Check viewer limit
        $this->cleanupInactiveViewers();
        if (count(self::$activeViewers) >= $maxViewers && !isset(self::$activeViewers[$viewerId])) {
            echo "event: error\n";
            echo "data: " . json_encode(['message' => 'Maximum viewers reached']) . "\n\n";
            return;
        }

        self::$activeViewers[$viewerId] = time();

        // Send Initial Connection Event
        echo "event: connected\n";
        echo "data: " . json_encode(['viewerid' => $viewerId]) . "\n\n";

        $startTime = time();
        $maxDuration = 300; // 5 minutes max connection time

        while ((time() - $startTime) < $maxDuration) {
            // Stop if client disconnected
            if (connection_status() !== CONNECTION_NORMAL || connection_aborted()) {
                break;
            }

            // Update viewer activity
            $this->cleanupInactiveViewers();
            self::$activeViewers[$viewerId] = time();

            // -------------------------------------------------
            // Your Original Metric Logic
            // -------------------------------------------------
            $metrics = MonitoringMiddleware::getCurrentResourceUsage();
            $todayMetrics = $this->logger->getMetrics();

            // Calculate System vs App splits
            $systemMetrics = [];
            $appMetrics = [];

            foreach ($todayMetrics as $metric) {
                if (!empty($metric['issystemroute'])) {
                    $systemMetrics[] = $metric;
                } else {
                    $appMetrics[] = $metric;
                }
            }

            // Calculate dynamic memory scale
            $systemRouteMemory = 0;
            $systemRouteCount = count($systemMetrics);
            foreach ($systemMetrics as $metric) {
                $systemRouteMemory += (float) ($metric['memoryused'] ?? 0);
            }
            $avgSystemRouteMemory = $systemRouteCount > 0 ? round($systemRouteMemory / $systemRouteCount, 2) : 0;

            // Add to live metrics
            $metrics['memory']['systemroute'] = $avgSystemRouteMemory;

            // Get lists for dashboard
            $latestRequests = array_slice(array_reverse($todayMetrics), 0, 10);
            $recentSystemMetrics = array_slice(array_reverse($systemMetrics), 0, 10);
            $recentAppMetrics = array_slice(array_reverse($appMetrics), 0, 10);

            $systemStats = $this->logger->getAggregatedStats($recentSystemMetrics);
            $appStats = $this->logger->getAggregatedStats($recentAppMetrics);

            // Prepare Data Packet
            $data = [
                'viewer_id' => $viewerId,
                'active_viewers' => count(self::$activeViewers),
                'max_viewers' => $maxViewers,
                'current_resources' => $metrics,
                'latest_requests' => $latestRequests,
                'recent_stats' => [
                    'system' => $systemStats,
                    'app' => $appStats
                ],
                'timestamp' => date('Y-m-d H:i:s'),
            ];

            // Send Event
            echo "event: metrics\n";
            echo "data: " . json_encode($data) . "\n\n";

            // Force flush manually (backup for implicit_flush)
            flush();

            // 500ms delay
            usleep(1000000);
        }

        unset(self::$activeViewers[$viewerId]);
    }


    public function live(): void
    {
        $this->cleanupInactiveViewers();

        $viewerId = $_GET['viewerid'] ?? $this->generateViewerId();
        $maxViewers = (int) ($_ENV['MAX_MONITORING_VIEWERS'] ?? 2);

        self::$activeViewers[$viewerId] = time();

        if (count(self::$activeViewers) > $maxViewers && !isset(self::$activeViewers[$viewerId])) {
            $this->sendError('Maximum number of viewers reached', 429);
            return;
        }

        $metrics = MonitoringMiddleware::getCurrentResourceUsage();
        $todayMetrics = $this->logger->getMetrics();

        $systemMetrics = [];
        $appMetrics = [];

        foreach ($todayMetrics as $metric) {
            if (!empty($metric['is_system_route'])) {
                $systemMetrics[] = $metric;
            } else {
                $appMetrics[] = $metric;
            }
        }

        $systemRouteMemory = 0;
        $systemRouteCount = count($systemMetrics);
        foreach ($systemMetrics as $metric) {
            $systemRouteMemory += (float) ($metric['memory_used'] ?? 0);
        }
        $avgSystemRouteMemory = $systemRouteCount > 0 ? round($systemRouteMemory / $systemRouteCount, 2) : 0;
        $metrics['memory']['system_route'] = $avgSystemRouteMemory;

        $latestRequests = array_slice(array_reverse($todayMetrics), 0, 10);

        $recentSystemMetrics = array_slice(array_reverse($systemMetrics), 0, 10);
        $recentAppMetrics = array_slice(array_reverse($appMetrics), 0, 10);

        $systemStats = $this->logger->getAggregatedStats($recentSystemMetrics);
        $appStats = $this->logger->getAggregatedStats($recentAppMetrics);

        $this->sendSuccess('Live metrics retrieved', [
            'viewer_id' => $viewerId,
            'active_viewers' => count(self::$activeViewers),
            'max_viewers' => $maxViewers,
            'current_resources' => $metrics,
            'latest_requests' => $latestRequests,
            'recent_stats' => [
                'system' => $systemStats,
                'app' => $appStats,
            ],
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
    }

    public function history(): void
    {
        $days = (int) ($_GET['days'] ?? 7);
        $days = min($days, 7);
        $date = $_GET['date'] ?? null;

        if ($date) {
            $metrics = $this->logger->getMetrics($date);
            $stats = $this->logger->getAggregatedStats($metrics);

            $this->sendSuccess('Historical metrics retrieved', [
                'date' => $date,
                'metrics' => $metrics,
                'stats' => $stats,
                'totalrequests' => count($metrics),
            ]);
            return;
        }

        $metricsRange = $this->logger->getMetricsRange($days);
        $summary = [];

        foreach ($metricsRange as $d => $metrics) {
            $summary[$d] = $this->logger->getAggregatedStats($metrics);
            $summary[$d]['totalrequests'] = count($metrics);
        }

        $this->sendSuccess('Historical metrics retrieved', [
            'days' => $days,
            'summary' => $summary,
            'availabledates' => $this->logger->getAvailableLogDates(),
        ]);
    }

    public function health(): void
    {
        $health = [
            'status' => 'healthy',
            'timestamp' => date('Y-m-d H:i:s'),
            'checks' => [],
        ];

        $dbHealth = MonitoringMiddleware::checkDatabaseHealth();
        $health['checks']['database'] = $dbHealth;

        $health['checks']['php'] = [
            'status' => 'healthy',
            'version' => PHP_VERSION,
            'memorylimit' => ini_get('memory_limit'),
        ];

        $diskUsage = MonitoringMiddleware::getCurrentResourceUsage()['disk'] ?? [
            'total' => 'N/A',
            'free' => 'N/A',
            'used' => 'N/A',
            'used_percent' => 'N/A',
        ];

        $diskPercentRaw = $diskUsage['used_percent'] ?? $diskUsage['usedpercent'] ?? 0;
        $diskPercent = is_numeric($diskPercentRaw) ? (float) $diskPercentRaw : (float) str_replace('%', '', (string) $diskPercentRaw);

        $health['checks']['disk'] = [
            'status' => $diskPercent > 90 ? 'warning' : 'healthy',
            'usage' => $diskUsage,
        ];

        foreach ($health['checks'] as $check) {
            if (($check['status'] ?? '') === 'unhealthy') {
                $health['status'] = 'unhealthy';
                break;
            }
            if (($check['status'] ?? '') === 'warning' && $health['status'] !== 'unhealthy') {
                $health['status'] = 'degraded';
            }
        }

        $statusCode = $health['status'] === 'healthy' ? 200 : 503;
        sendJsonResponse($statusCode, true, 'Health check completed', $health);
    }

    public function stats(): void
    {
        $days = (int) ($_GET['days'] ?? 1);
        $days = min($days, 7);

        $metricsRange = $this->logger->getMetricsRange($days);

        $allMetrics = [];
        foreach ($metricsRange as $metrics) {
            $allMetrics = array_merge($allMetrics, $metrics);
        }

        $stats = $this->logger->getAggregatedStats($allMetrics);

        if (!empty($stats['endpoints']) && is_array($stats['endpoints'])) {
            uasort($stats['endpoints'], function ($a, $b) {
                return ($b['count'] ?? 0) - ($a['count'] ?? 0);
            });
        }

        $this->sendSuccess('Statistics retrieved', [
            'period' => $days . ' days',
            'stats' => $stats,
        ]);
    }

    public function clear(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->sendError('Method not allowed', 405);
            return;
        }

        $this->logger->clearAllLogs();
        $this->sendSuccess('All monitoring logs cleared');
    }

    private function isMonitoringEnabled(): bool
    {
        $value = $_ENV['ENABLE_MONITORING'] ?? false;
        return !empty($value) && ($value === 'true' || $value === '1' || $value === 1 || $value === true);
    }

    private function generateViewerId(): string
    {
        return uniqid('viewer_', true);
    }

    private function cleanupInactiveViewers(): void
    {
        $now = time();
        foreach (self::$activeViewers as $id => $lastSeen) {
            if ($now - $lastSeen > self::VIEWER_TIMEOUT) {
                unset(self::$activeViewers[$id]);
            }
        }
    }



    private function renderDashboard(): void
    {
        // Hardcoded base path
        $basePath = '/api';

        header('Content-Type: text/html; charset=utf-8');
        ?>
        <!DOCTYPE html>
        <html lang="en" data-theme="dark">

        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>System Monitoring Dashboard</title>
            <style>
                :root {
                    --bg-primary: #0d1117;
                    --bg-secondary: #161b22;
                    --bg-tertiary: #21262d;
                    --text-primary: #c9d1d9;
                    --text-secondary: #8b949e;
                    --border-color: #30363d;
                    --accent-color: #58a6ff;
                    --success-color: #3fb950;
                    --warning-color: #d29922;
                    --danger-color: #f85149;
                    --purple-color: #a371f7;
                    --shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
                }

                * {
                    box-sizing: border-box;
                    margin: 0;
                    padding: 0;
                }

                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    background: var(--bg-primary);
                    color: var(--text-primary);
                    padding: 20px;
                    line-height: 1.6;
                }

                .container {
                    max-width: 1600px;
                    margin: 0 auto;
                }

                .header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-bottom: 30px;
                    padding: 20px;
                    background: var(--bg-secondary);
                    border-radius: 12px;
                    border: 1px solid var(--border-color);
                    flex-wrap: wrap;
                    gap: 15px;
                }

                .header h1 {
                    font-size: 28px;
                    color: var(--accent-color);
                    margin: 0;
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }

                .help-icon {
                    font-size: 20px;
                    width: 28px;
                    height: 28px;
                    border-radius: 50%;
                    background: var(--bg-tertiary);
                    border: 2px solid var(--border-color);
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    cursor: pointer;
                    transition: all 0.2s;
                }

                .help-icon:hover {
                    background: var(--accent-color);
                    border-color: var(--accent-color);
                    transform: scale(1.1);
                }

                .header-info {
                    display: flex;
                    gap: 15px;
                    align-items: center;
                    flex-wrap: wrap;
                }

                .status-badge {
                    padding: 8px 16px;
                    border-radius: 8px;
                    font-weight: 700;
                    font-size: 13px;
                    border: 2px solid transparent;
                }

                .status-healthy {
                    background: var(--success-color);
                    color: white;
                    border-color: var(--success-color);
                }

                .status-warning {
                    background: var(--warning-color);
                    color: white;
                    border-color: var(--warning-color);
                }

                .status-error {
                    background: var(--danger-color);
                    color: white;
                    border-color: var(--danger-color);
                }

                .metric-badge {
                    font-size: 12px;
                    font-weight: 600;
                    padding: 6px 12px;
                    background: var(--bg-tertiary);
                    border-radius: 6px;
                    border: 1px solid var(--border-color);
                    display: flex;
                    align-items: center;
                    gap: 6px;
                }

                .metric-badge-active {
                    background: rgba(88, 166, 255, 0.15);
                    border-color: rgba(88, 166, 255, 0.4);
                }

                .metric-value {
                    font-weight: 700;
                    color: var(--accent-color);
                    font-size: 14px;
                }

                .refresh-indicator {
                    display: inline-block;
                    width: 8px;
                    height: 8px;
                    background: var(--success-color);
                    border-radius: 50%;
                    animation: pulse 2s infinite;
                }

                @keyframes pulse {

                    0%,
                    100% {
                        opacity: 1;
                    }

                    50% {
                        opacity: 0.3;
                    }
                }

                .grid {
                    display: grid;
                    grid-template-columns: repeat(2, 1fr);
                    gap: 20px;
                    margin-bottom: 20px;
                }

                @media (max-width: 1200px) {
                    .grid {
                        grid-template-columns: 1fr;
                    }
                }

                .card {
                    background: var(--bg-secondary);
                    border: 1px solid var(--border-color);
                    border-radius: 12px;
                    padding: 20px;
                    box-shadow: var(--shadow);
                    transition: all 0.3s ease;
                }

                .card h3 {
                    color: var(--accent-color);
                    margin-bottom: 15px;
                    font-size: 18px;
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }

                .expand-icon {
                    margin-left: auto;
                    font-size: 20px;
                    color: var(--text-secondary);
                    cursor: pointer;
                    background: transparent;
                    border: 0;
                    padding: 0;
                    line-height: 1;
                }

                .chart-container {
                    position: relative;
                    height: 200px;
                }

                .requests-controls {
                    display: flex;
                    gap: 10px;
                    align-items: center;
                    margin-bottom: 12px;
                    flex-wrap: wrap;
                }

                .filter-btn {
                    background: var(--bg-tertiary);
                    border: 2px solid var(--border-color);
                    color: var(--text-primary);
                    padding: 8px 16px;
                    border-radius: 8px;
                    cursor: pointer;
                    font-weight: 700;
                    font-size: 12px;
                    transition: all 0.2s;
                }

                .filter-btn:hover {
                    border-color: var(--accent-color);
                }

                .filter-btn.active {
                    background: var(--accent-color);
                    color: white;
                    border-color: var(--accent-color);
                }

                .route-badge {
                    display: inline-block;
                    padding: 5px 10px;
                    border-radius: 999px;
                    font-weight: 800;
                    font-size: 11px;
                    letter-spacing: 0.3px;
                    text-transform: uppercase;
                    min-width: 72px;
                    text-align: center;
                    border: 1px solid;
                }

                .route-app {
                    border-color: rgba(88, 166, 255, 0.5);
                    color: #58a6ff;
                    background: rgba(88, 166, 255, 0.15);
                }

                .route-system {
                    border-color: rgba(163, 113, 247, 0.5);
                    color: #d2a8ff;
                    background: rgba(163, 113, 247, 0.15);
                }

                /* Top Endpoints Table */
                .top-endpoints-wrapper {
                    max-width: 800px;
                }

                .top-endpoints-list {
                    list-style: none;
                    padding: 0;
                    margin: 0;
                }

                .top-endpoints-list li {
                    display: flex;
                    align-items: center;
                    gap: 16px;
                    padding: 14px 16px;
                    background: var(--bg-tertiary);
                    border-radius: 8px;
                    margin-bottom: 8px;
                    border: 1px solid var(--border-color);
                    transition: all 0.2s;
                }

                .top-endpoints-list li:hover {
                    background: rgba(88, 166, 255, 0.1);
                    transform: translateX(4px);
                }

                .endpoint-rank {
                    font-weight: 800;
                    font-size: 18px;
                    color: var(--text-secondary);
                    min-width: 28px;
                }

                .endpoint-info {
                    flex: 1;
                    min-width: 0;
                }

                .endpoint-path {
                    font-family: 'Consolas', 'Monaco', monospace;
                    font-size: 14px;
                    color: var(--text-primary);
                    overflow: hidden;
                    text-overflow: ellipsis;
                    white-space: nowrap;
                    margin-bottom: 4px;
                }

                .endpoint-meta {
                    font-size: 12px;
                    color: var(--text-secondary);
                    display: flex;
                    align-items: center;
                    gap: 8px;
                }

                .endpoint-time {
                    font-weight: 700;
                    font-size: 16px;
                    color: var(--danger-color);
                    font-family: 'Consolas', 'Monaco', monospace;
                    white-space: nowrap;
                }

                /* Requests Table */
                .requests-table {
                    width: 100%;
                    border-collapse: separate;
                    border-spacing: 0;
                    font-size: 14px;
                }

                .requests-table thead {
                    background: var(--bg-tertiary);
                    position: sticky;
                    top: 0;
                    z-index: 10;
                }

                .requests-table th {
                    padding: 12px 16px;
                    text-align: left;
                    font-weight: 700;
                    color: var(--text-primary);
                    border-bottom: 2px solid var(--border-color);
                    white-space: nowrap;
                }

                .requests-table tbody tr {
                    background: var(--bg-tertiary);
                    border-bottom: 1px solid var(--border-color);
                    transition: all 0.2s ease;
                }

                .requests-table tbody tr:hover {
                    background: rgba(88, 166, 255, 0.1);
                    transform: translateX(4px);
                }

                .requests-table td {
                    padding: 14px 16px;
                    border-bottom: 1px solid var(--border-color);
                }

                .table-time {
                    font-size: 15px;
                    font-weight: 400;
                    color: var(--accent-color);
                    font-family: 'Consolas', 'Monaco', monospace;
                }

                .table-memory {
                    font-size: 18px;
                    font-weight: 700;
                    color: var(--warning-color);
                    font-family: 'Consolas', 'Monaco', monospace;
                }

                .table-response-time,
                .table-cpu-time {
                    font-size: 16px;
                    font-weight: 600;
                    color: var(--success-color);
                }

                .table-method {
                    display: inline-block;
                    padding: 5px 12px;
                    border-radius: 4px;
                    font-weight: 700;
                    font-size: 11px;
                    text-transform: uppercase;
                    min-width: 60px;
                    text-align: center;
                }

                .method-GET {
                    background: var(--success-color);
                    opacity: 0.6;
                    color: white;
                }

                .method-POST {
                    background: var(--accent-color);
                    color: white;
                }

                .method-PUT {
                    background: var(--warning-color);
                    color: #212529;
                }

                .method-DELETE {
                    background: var(--danger-color);
                    color: white;
                }

                .table-status {
                    padding: 5px 12px;
                    border-radius: 4px;
                    font-weight: 600;
                    font-size: 13px;
                    display: inline-block;
                    min-width: 45px;
                    text-align: center;
                }

                .status-2xx {
                    background: rgba(63, 185, 80, 0.2);
                    color: var(--success-color);
                }

                .status-4xx {
                    background: rgba(210, 153, 34, 0.2);
                    color: var(--warning-color);
                }

                .status-5xx {
                    background: rgba(248, 81, 73, 0.2);
                    color: var(--danger-color);
                }

                .table-endpoint {
                    font-family: 'Consolas', 'Monaco', monospace;
                    font-size: 14px;
                    color: var(--text-primary);
                    max-width: 300px;
                    overflow: hidden;
                    text-overflow: ellipsis;
                    white-space: nowrap;
                }

                .table-queries {
                    color: var(--text-secondary);
                    font-size: 13px;
                }

                .loading {
                    text-align: center;
                    padding: 40px;
                    color: var(--text-secondary);
                }

                /* Modal Dialog */
                .chart-dialog,
                .guide-dialog {
                    border: none;
                    border-radius: 12px;
                    padding: 0;
                    max-width: 95vw;
                    max-height: 95vh;
                    width: 95vw;
                    height: 95vh;
                    background: var(--bg-secondary);
                    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.9);
                    overflow: hidden;
                    margin: auto;
                }

                .guide-dialog {
                    width: 800px;
                    height: auto;
                    max-height: 90vh;
                }

                .chart-dialog::backdrop,
                .guide-dialog::backdrop {
                    background: rgba(0, 0, 0, 0.92);
                    backdrop-filter: blur(8px);
                }

                .dialog-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    padding: 20px 30px;
                    background: var(--bg-tertiary);
                    border-bottom: 1px solid var(--border-color);
                }

                .dialog-header h2 {
                    margin: 0;
                    color: var(--accent-color);
                    font-size: 24px;
                }

                .dialog-body {
                    padding: 20px;
                    height: calc(100% - 80px);
                    display: flex;
                    flex-direction: column;
                }

                .dialog-body canvas {
                    flex: 1;
                    width: 100% !important;
                    height: 100% !important;
                }

                .guide-dialog .dialog-body {
                    height: auto;
                    overflow-y: auto;
                    max-height: calc(90vh - 80px);
                }

                .guide-section {
                    margin-bottom: 30px;
                }

                .guide-section h3 {
                    color: var(--accent-color);
                    font-size: 20px;
                    margin-bottom: 12px;
                    border-bottom: 2px solid var(--border-color);
                    padding-bottom: 8px;
                }

                .guide-metric {
                    margin-bottom: 20px;
                    padding: 16px;
                    background: var(--bg-tertiary);
                    border-radius: 8px;
                    border-left: 4px solid var(--accent-color);
                }

                .guide-metric h4 {
                    color: var(--text-primary);
                    font-size: 16px;
                    margin-bottom: 8px;
                }

                .guide-metric p {
                    color: var(--text-secondary);
                    font-size: 14px;
                    line-height: 1.6;
                    margin-bottom: 8px;
                }

                .guide-thresholds {
                    display: flex;
                    gap: 12px;
                    margin-top: 10px;
                    flex-wrap: wrap;
                }

                .threshold {
                    padding: 6px 12px;
                    border-radius: 6px;
                    font-size: 12px;
                    font-weight: 600;
                }

                .threshold-good {
                    background: rgba(63, 185, 80, 0.2);
                    color: var(--success-color);
                }

                .threshold-warning {
                    background: rgba(210, 153, 34, 0.2);
                    color: var(--warning-color);
                }

                .threshold-danger {
                    background: rgba(248, 81, 73, 0.2);
                    color: var(--danger-color);
                }

                .modal-close {
                    background: var(--bg-primary);
                    border: 2px solid var(--border-color);
                    color: var(--text-primary);
                    font-size: 32px;
                    width: 45px;
                    height: 45px;
                    border-radius: 50%;
                    cursor: pointer;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    transition: all 0.2s ease;
                    line-height: 1;
                    padding: 0;
                }

                .modal-close:hover {
                    background: var(--danger-color);
                    color: white;
                    border-color: var(--danger-color);
                    transform: rotate(90deg);
                }



                .table-queries {
                    color: var(--text-secondary);
                    font-size: 13px;
                }

                /* ========== NEW: DB Time Styles ========== */
                .table-db-time {
                    font-size: 15px;
                    font-weight: 700;
                    font-family: 'Consolas', 'Monaco', monospace;
                }

                .db-efficient {
                    color: var(--success-color);
                }

                .db-moderate {
                    color: var(--warning-color);
                }

                .db-bottleneck {
                    color: var(--danger-color);
                }

                .table-db-percentage {
                    font-size: 12px;
                    font-weight: 600;
                    padding: 4px 8px;
                    border-radius: 4px;
                    display: inline-block;
                }

                /* ========================================== */
            </style>
        </head>

        <body>
            <div class="container">
                <div class="header">
                    <div>
                        <h1>
                            🔍 System Monitoring
                            <button type="button" class="help-icon" onclick="openGuide()"
                                title="Show Interpretation Guide">?</button>
                        </h1>
                    </div>
                    <div class="header-info">
                        <div class="metric-badge">
                            <span class="refresh-indicator"></span>
                            <span>SSE Streaming</span>
                        </div>
                        <div class="metric-badge metric-badge-active">
                            <span>Active:</span>
                            <span class="metric-value" id="activeRequests">0</span>
                            <span>req</span>
                        </div>
                        <div class="metric-badge">
                            <span>Uptime:</span>
                            <span class="metric-value" id="uptime">--</span>
                        </div>
                        <div class="metric-badge">
                            <span id="viewerCount">Viewers: 0/2</span>
                        </div>
                        <div id="systemStatus" class="status-badge status-healthy">Healthy</div>
                    </div>
                </div>

                <div class="grid">
                    <div class="card" data-chart="memory" data-title="Memory Usage (MB)">
                        <h3>
                            Memory Usage (MB)
                            <button type="button" class="expand-icon" aria-label="Expand">⛶</button>
                        </h3>
                        <div class="chart-container">
                            <canvas id="memoryChart"></canvas>
                        </div>
                    </div>

                    <div class="card" data-chart="response" data-title="Response Time (ms)">
                        <h3>
                            Response Time (ms)
                            <button type="button" class="expand-icon" aria-label="Expand">⛶</button>
                        </h3>
                        <div class="chart-container">
                            <canvas id="responseChart"></canvas>
                        </div>
                    </div>

                    <div class="card" data-chart="requestRate" data-title="Requests Per Second">
                        <h3>
                            Requests Per Second
                            <button type="button" class="expand-icon" aria-label="Expand">⛶</button>
                        </h3>
                        <div class="chart-container">
                            <canvas id="requestRateChart"></canvas>
                        </div>
                    </div>

                    <div class="card" data-chart="dbMemory" data-title="Database & Memory">
                        <h3>
                            Database & Memory
                            <button type="button" class="expand-icon" aria-label="Expand">⛶</button>
                        </h3>
                        <div class="chart-container">
                            <canvas id="dbMemoryChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <h3>
                        Latest Requests
                        <span style="color: var(--text-secondary); font-size: 12px; font-weight: 600;"
                            id="latestRequestsFilterLabel">All</span>
                    </h3>
                    <div class="requests-controls">
                        <button type="button" class="filter-btn active" data-filter="all">All Routes</button>
                        <button type="button" class="filter-btn" data-filter="app">App Routes Only</button>
                        <button type="button" class="filter-btn" data-filter="system">System Routes Only</button>
                    </div>
                    <div id="latestRequests" class="loading">Connecting to stream...</div>
                </div>

                <div class="card" style="margin-top: 20px; width: auto;">
                    <h3>Top 5 Slowest Endpoints (Last 10 Requests)</h3>
                    <div class="top-endpoints-wrapper">
                        <div id="topEndpoints" class="loading">Loading...</div>
                    </div>
                </div>
            </div>

            <!-- Chart Modal -->
            <dialog id="chartModal" class="chart-dialog">
                <div class="dialog-header">
                    <h2 id="modalTitle"></h2>
                    <button class="modal-close" onclick="closeModal()">&times;</button>
                </div>
                <div class="dialog-body">
                    <canvas id="modalChart"></canvas>
                </div>
            </dialog>

            <!-- Guide Modal -->
            <dialog id="guideModal" class="guide-dialog">
                <div class="dialog-header">
                    <h2>📊 Monitoring Dashboard Guide</h2>
                    <button class="modal-close" onclick="closeGuide()">&times;</button>
                </div>
                <div class="dialog-body">
                    <div class="guide-section">
                        <h3>Understanding Metrics</h3>

                        <div class="guide-metric">
                            <h4>💾 Memory Usage</h4>
                            <p><strong>What:</strong> RAM consumed by PHP during request execution.</p>
                            <p><strong>How to read:</strong> Watch for spikes. Consistent growth = memory leak.</p>
                            <div class="guide-thresholds">
                                <span class="threshold threshold-good">Good: &lt; 50 MB</span>
                                <span class="threshold threshold-warning">Warning: 50-100 MB</span>
                                <span class="threshold threshold-danger">Critical: &gt; 100 MB</span>
                            </div>
                        </div>

                        <div class="guide-metric">
                            <h4>⚡ Response Time</h4>
                            <p><strong>What:</strong> Time from request start to response sent (in milliseconds).</p>
                            <p><strong>How to read:</strong> p50 = median speed, p95 = worst-case for 95% of requests.</p>
                            <div class="guide-thresholds">
                                <span class="threshold threshold-good">Good: &lt; 200ms</span>
                                <span class="threshold threshold-warning">Warning: 200-500ms</span>
                                <span class="threshold threshold-danger">Slow: &gt; 500ms</span>
                            </div>
                        </div>

                        <div class="guide-metric">
                            <h4>📊 Requests Per Second (RPS)</h4>
                            <p><strong>What:</strong> Number of requests processed every second.</p>
                            <p><strong>How to read:</strong> High RPS + high response time = server overload.</p>
                            <div class="guide-thresholds">
                                <span class="threshold threshold-good">Normal: 0-10 RPS</span>
                                <span class="threshold threshold-warning">Busy: 10-50 RPS</span>
                                <span class="threshold threshold-danger">Stressed: &gt; 50 RPS</span>
                            </div>
                        </div>

                        <div class="guide-metric">
                            <h4>🗄️ Database Queries</h4>
                            <p><strong>What:</strong> Average number of SQL queries per request.</p>
                            <p><strong>How to read:</strong> High query count = N+1 problem or missing eager loading.</p>
                            <div class="guide-thresholds">
                                <span class="threshold threshold-good">Efficient: &lt; 10 queries</span>
                                <span class="threshold threshold-warning">Heavy: 10-50 queries</span>
                                <span class="threshold threshold-danger">N+1 Problem: &gt; 50 queries</span>
                            </div>
                        </div>

                        <div class="guide-metric">
                            <h4>⚙️ CPU Time</h4>
                            <p><strong>What:</strong> Actual CPU processing time (excludes waiting for IO, database).</p>
                            <p><strong>How to read:</strong> High CPU time = complex calculations, not IO.</p>
                            <div class="guide-thresholds">
                                <span class="threshold threshold-good">Light: &lt; 50ms</span>
                                <span class="threshold threshold-warning">Heavy: 50-200ms</span>
                                <span class="threshold threshold-danger">Compute-Intensive: &gt; 200ms</span>
                            </div>
                        </div>

                        <div class="guide-metric">
                            <h4>🔄 Active Requests</h4>
                            <p><strong>What:</strong> Number of requests currently being processed.</p>
                            <p><strong>How to read:</strong> Stuck at high number = blocking operations or deadlock.</p>
                            <div class="guide-thresholds">
                                <span class="threshold threshold-good">Normal: &lt; 5</span>
                                <span class="threshold threshold-warning">Busy: 5-20</span>
                                <span class="threshold threshold-danger">Overload: &gt; 20</span>
                            </div>
                        </div>
                    </div>

                    <div class="guide-section">
                        <h3>Color Coding</h3>
                        <div class="guide-metric">
                            <h4>Route Types</h4>
                            <p><span class="route-badge route-app">APP</span> Your application endpoints</p>
                            <p><span class="route-badge route-system">SYSTEM</span> Framework/monitoring routes (ignore these
                                for app performance)</p>
                        </div>
                    </div>

                    <div class="guide-section">
                        <h3>⚠️ When to Worry</h3>
                        <div class="guide-metric">
                            <h4>🚨 Critical Issues</h4>
                            <p>• Memory &gt; 100 MB + increasing = Memory leak</p>
                            <p>• Response time &gt; 1000ms consistently = Database/API bottleneck</p>
                            <p>• DB queries &gt; 50 per request = N+1 query problem</p>
                            <p>• Active requests stuck at 20+ = Deadlock or blocking code</p>
                        </div>

                        <div class="guide-metric">
                            <h4>⚡ Warning Signs</h4>
                            <p>• Response time p95 &gt; 2× p50 = Inconsistent performance</p>
                            <p>• RPS high but server CPU low = Database bottleneck</p>
                            <p>• CPU time ≈ Response time = No IO, pure computation</p>
                        </div>
                    </div>
                </div>
            </dialog>

            <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
            <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6"></script>
            <script>
                // ============================================
                // Configuration with proper JSON encoding
                // ============================================
                let viewerId = null;
                const basePath = <?php echo json_encode($basePath); ?>;

                // DEBUG LOGGING
                console.log('========== MONITORING DEBUG ==========');
                console.log('Base Path:', basePath);
                console.log('Expected Stream URL:', basePath + '/monitoring/stream');
                console.log('======================================');

                let memoryChart, responseChart, requestRateChart, dbMemoryChart, modalChart;
                let eventSource = null;
                let appRequestCount = 0;
                let systemRequestCount = 0;
                let lastUpdateTime = Date.now();
                let lastLatestRequestsCache = [];
                let currentRequestsFilter = localStorage.getItem('monitoringRequestsFilter') || 'all';

                const maxDataPoints = 120;
                let maxMemoryLimit = 512;
                let currentMemoryScale = 10;

                // ============================================
                // Helper Functions
                // ============================================
                function parseMemoryLimit(limitString) {
                    if (typeof limitString !== 'string') limitString = String(limitString);
                    if (!limitString || limitString === '-1' || limitString === 'unlimited') return 512;

                    const units = { 'K': 1024, 'M': 1024 * 1024, 'G': 1024 * 1024 * 1024 };
                    const match = limitString.match(/(\d+)([KMG]?)/i);

                    if (match) {
                        const value = parseInt(match[1]);
                        const unit = match[2] ? match[2].toUpperCase() : 'M';
                        return Math.round(value * units[unit] / (1024 * 1024));
                    }

                    const numValue = parseInt(limitString);
                    if (!isNaN(numValue)) return numValue;
                    return 512;
                }

                function calculateDynamicMemoryScale(currentMem, peakMem, systemMem) {
                    const maxValue = Math.max(currentMem, peakMem, systemMem);
                    const newScale = Math.ceil(maxValue / 10) * 10;
                    return Math.max(10, newScale);
                }

                function setRequestsFilter(filter) {
                    currentRequestsFilter = filter;
                    localStorage.setItem('monitoringRequestsFilter', filter);

                    $('.filter-btn').removeClass('active');
                    $(`.filter-btn[data-filter="${filter}"]`).addClass('active');

                    const label = filter === 'all' ? 'All' : (filter === 'app' ? 'App' : 'System');
                    $('#latestRequestsFilterLabel').text(label);

                    updateLatestRequests(lastLatestRequestsCache);
                }

                // ============================================
                // SSE Connection
                // ============================================
                function startSSE() {
                    const streamPath = basePath + '/monitoring/stream';
                    const url = viewerId ? `${streamPath}?viewerid=${viewerId}` : streamPath;

                    console.log('🔌 Attempting SSE connection to:', url);

                    eventSource = new EventSource(url);

                    eventSource.addEventListener('connected', function (e) {
                        const data = JSON.parse(e.data);
                        viewerId = data.viewer_id;
                        console.log('✅ SSE connected, viewer ID:', viewerId);
                    });

                    eventSource.addEventListener('metrics', function (e) {
                        const result = { status: 'success', data: JSON.parse(e.data) };
                        handleMetricsUpdate(result);
                        console.log(result);
                    });

                    eventSource.addEventListener('error', function (e) {
                        console.error('❌ SSE connection error');
                        console.error('EventSource readyState:', eventSource.readyState);
                        console.error('Error event:', e);

                        $('#systemStatus')
                            .removeClass('status-healthy status-warning')
                            .addClass('status-error')
                            .text('Reconnecting...');
                    });
                }

                // ============================================
                // Handle Incoming Metrics
                // ============================================
                function handleMetricsUpdate(result) {
                    if (result.status !== 'success') return;

                    const data = result.data;
                    viewerId = data.viewer_id ?? data.viewer_id ?? viewerId;

                    const resources = data.current_resources ?? data.current_resources ?? {};
                    const memory = resources.memory ?? {};
                    const server = resources.server ?? {};
                    const recentStats = data.recent_stats ?? data.recent_stats ?? {};

                    // Memory limit
                    const limitValue = memory.limit_formatted ?? memory.limit;
                    if (limitValue != null) {
                        const newLimit = parseMemoryLimit(limitValue);
                        if (newLimit !== maxMemoryLimit) {
                            maxMemoryLimit = newLimit;
                        }
                    }

                    // Memory chart
                    const currentMem = parseFloat(memory.current) || 0;
                    const peakMem = parseFloat(memory.peak) || 0;
                    const systemMem = parseFloat(memory.system_route ?? memory.system_route) || 0;

                    const newScale = calculateDynamicMemoryScale(currentMem, peakMem, systemMem);
                    if (newScale !== currentMemoryScale) {
                        currentMemoryScale = newScale;
                        if (memoryChart) {
                            memoryChart.options.scales.y.max = currentMemoryScale;
                        }
                    }

                    updateChartMulti(memoryChart, [currentMem, peakMem, systemMem]);

                    // Response chart
                    const appP50 = parseFloat(recentStats?.app?.p50_response_time ?? recentStats?.app?.p50_response_time) || 0;
                    const appP95 = parseFloat(recentStats?.app?.p95_response_time ?? recentStats?.app?.p95_response_time) || 0;
                    const systemP50 = parseFloat(recentStats?.system?.p50_response_time ?? recentStats?.system?.p50_response_time) || 0;
                    const systemP95 = parseFloat(recentStats?.system?.p95_response_time ?? recentStats?.system?.p95_response_time) || 0;

                    updateChartMulti(responseChart, [appP50, appP95, systemP50, systemP95]);

                    // Request rate
                    const now = Date.now();
                    const timeDiff = (now - lastUpdateTime) / 1000;
                    const newAppRequests = parseInt(recentStats?.app?.total_requests ?? recentStats?.app?.total_requests) || 0;
                    const newSystemRequests = parseInt(recentStats?.system?.total_requests ?? recentStats?.system?.total_requests) || 0;

                    const appRequestRate = timeDiff > 0 ? Math.round((newAppRequests - appRequestCount) / timeDiff) : 0;
                    const systemRequestRate = timeDiff > 0 ? Math.round((newSystemRequests - systemRequestCount) / timeDiff) : 0;

                    updateChartMulti(requestRateChart, [Math.max(0, appRequestRate), Math.max(0, systemRequestRate)]);

                    appRequestCount = newAppRequests;
                    systemRequestCount = newSystemRequests;
                    lastUpdateTime = now;

                    // DB chart - Use total queries count
                    const dbQueries = parseFloat(
                        recentStats?.app?.total_db_queries ??
                        recentStats?.system?.total_db_queries ?? 0
                    ) || 0;
                    const memoryPercent = parseFloat(memory.percentage) || 0;

                    updateChartMulti(dbMemoryChart, [dbQueries, memoryPercent]);

                    // Latest requests
                    const latestRequests = data.latest_requests ?? data.latestRequests ?? [];

                    updateLatestRequests(latestRequests);

                    // Top endpoints
                    updateTopEndpoints(recentStats);

                    // Header badges
                    const active = data.active_viewers ?? data.active_viewers ?? 0;
                    const max = data.max_viewers ?? data.max_viewers ?? 0;
                    $('#viewerCount').text(`Viewers: ${active}/${max}`);
                    $('#uptime').text(server.uptime || '--');
                    $('#activeRequests').text(server.active_requests ?? server.active_requests ?? 0);

                    $('#systemStatus')
                        .removeClass('status-error status-warning')
                        .addClass('status-healthy')
                        .text('Healthy');
                }

                // ============================================
                // Top Endpoints
                // ============================================
                function updateTopEndpoints(recentStats) {
                    const appEndpoints = recentStats?.app?.endpoints ?? {};
                    const systemEndpoints = recentStats?.system?.endpoints ?? {};

                    const allEndpoints = [];

                    Object.entries(appEndpoints).forEach(([path, stats]) => {
                        allEndpoints.push({
                            path: path,
                            avgtime: stats.avgtime ?? stats.avgtime ?? 0,
                            count: stats.count ?? 0,
                            type: 'app'
                        });
                    });

                    Object.entries(systemEndpoints).forEach(([path, stats]) => {
                        allEndpoints.push({
                            path: path,
                            avgtime: stats.avgtime ?? stats.avgtime ?? 0,
                            count: stats.count ?? 0,
                            type: 'system'
                        });
                    });

                    allEndpoints.sort((a, b) => b.avgtime - a.avgtime);
                    const top5 = allEndpoints.slice(0, 5);

                    if (top5.length === 0) {
                        $('#topEndpoints').html('<div class="loading">No endpoint data yet</div>');
                        return;
                    }

                    let html = '<ul class="top-endpoints-list">';
                    top5.forEach((endpoint, index) => {
                        const typeClass = endpoint.type === 'app' ? 'route-app' : 'route-system';
                        const typeText = endpoint.type === 'app' ? 'APP' : 'SYSTEM';

                        html += '<li>';
                        html += `<span class="endpoint-rank">#${index + 1}</span>`;
                        html += '<div class="endpoint-info">';
                        html += `<div class="endpoint-path">${endpoint.path}</div>`;
                        html += '<div class="endpoint-meta">';
                        html += `<span class="route-badge ${typeClass}">${typeText}</span>`;
                        html += `<span>${endpoint.count} requests</span>`;
                        html += '</div>';
                        html += '</div>';
                        html += `<span class="endpoint-time">${endpoint.avgtime.toFixed(2)}ms</span>`;
                        html += '</li>';
                    });
                    html += '</ul>';

                    $('#topEndpoints').html(html);
                }

                // ============================================
                // Latest Requests Table
                // ============================================
                function updateLatestRequests(requests) {

                    if (!requests || requests.length === 0) {
                        $('#latestRequests').html('<div class="loading">No recent requests</div>');
                        return;
                    }

                    let filtered = requests;

                    if (currentRequestsFilter === 'app') {
                        filtered = requests.filter(r => !r.is_system_route);
                    } else if (currentRequestsFilter === 'system') {
                        filtered = requests.filter(r => !!r.is_system_route);
                    }

                    if (!filtered || filtered.length === 0) {
                        $('#latestRequests').html('<div class="loading">No requests for this filter</div>');
                        return;
                    }

                    let html = '<table class="requests-table"><thead><tr>';
                    html += '<th>Method</th><th>Type</th><th>Status</th><th>Endpoint</th><th>Time</th>';
                    html += '<th>Response</th><th>CPU</th><th>Memory</th><th>DB</th>';
                    html += '<th>DB Time</th><th>DB %</th>'; // NEW


                    filtered.forEach(req => {

                        const code = req.status_code ?? req.status_code ?? 0;
                        const statusClass = getStatusClass(parseInt(code, 10) || 0);
                        const ts = (req.timestamp || '').split(' ');
                        const time = ts.length > 1 ? ts[1] : req.timestamp;
                        const isSystem = !!req.is_system_route;
                        const typeText = isSystem ? 'SYSTEM' : 'APP';
                        const typeClass = isSystem ? 'route-system' : 'route-app';

                        const responseTime = parseFloat(req.response_time ?? req.response_time ?? 0).toFixed(2);
                        const cpuTime = parseFloat(req.cpu_time ?? req.cpu_time ?? 0).toFixed(2);
                        const memoryUsed = parseFloat(req.memory_used ?? req.memory_used ?? 0).toFixed(2);
                        const queries = req.db_queries ?? req.db_queries ?? 0;

                        // ========== NEW: DB Time Metrics ==========
                        const dbTime = parseFloat(req.db_time ?? req.db_time ?? 0).toFixed(2);
                        const dbPercentage = parseFloat(req.db_percentage ?? req.db_percentage ?? 0).toFixed(1);

                        // Color coding based on DB percentage
                        let dbTimeClass = 'db-efficient';
                        if (dbPercentage >= 60) {
                            dbTimeClass = 'db-bottleneck';
                        } else if (dbPercentage >= 30) {
                            dbTimeClass = 'db-moderate';
                        }

                        let dbPercentClass = 'db-efficient';
                        if (dbPercentage >= 60) {
                            dbPercentClass = 'db-bottleneck';
                        } else if (dbPercentage >= 30) {
                            dbPercentClass = 'db-moderate';
                        }
                        // ==========================================

                        html += '<tr>';
                        html += `<td><span class="table-method method-${req.method}">${req.method}</span></td>`;
                        html += `<td><span class="route-badge ${typeClass}">${typeText}</span></td>`;
                        html += `<td><span class="table-status ${statusClass}">${code}</span></td>`;
                        html += `<td><span class="table-endpoint" title="${req.endpoint}">${req.endpoint}</span></td>`;
                        html += `<td><span class="table-time">${time}</span></td>`;
                        html += `<td><span class="table-response-time">${responseTime}ms</span></td>`;
                        html += `<td><span class="table-cpu-time">${cpuTime}ms</span></td>`;
                        html += `<td><span class="table-memory">${memoryUsed}MB</span></td>`;
                        html += `<td><span class="table-queries">${queries}</span></td>`;
                        // ========== NEW: DB Time Columns ==========
                        html += `<td><span class="table-db-time ${dbTimeClass}">${dbTime}ms</span></td>`;
                        html += `<td><span class="table-db-percentage ${dbPercentClass}">${dbPercentage}%</span></td>`;
                        // ==========================================
                        html += '</tr>';

                    });

                    html += '</tbody></table>';
                    $('#latestRequests').html(html);
                }

                function getStatusClass(code) {
                    if (code >= 200 && code < 300) return 'status-2xx';
                    if (code >= 400 && code < 500) return 'status-4xx';
                    return 'status-5xx';
                }

                // ============================================
                // Chart Functions
                // ============================================
                function initializeCharts() {
                    const chartConfig = {
                        type: 'line',
                        data: { labels: [], datasets: [] },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: { intersect: false },
                            scales: {
                                x: { display: false, grid: { display: false } },
                                y: {
                                    beginAtZero: true,
                                    grid: { color: '#30363d' },
                                    ticks: { color: '#8b949e' }
                                }
                            },
                            plugins: {
                                legend: { labels: { color: '#c9d1d9' } },
                                tooltip: {
                                    callbacks: {
                                        title: function (context) {
                                            const date = new Date(Number(context[0].label));
                                            return date.toLocaleTimeString('en-US', {
                                                hour12: false,
                                                hour: '2-digit',
                                                minute: '2-digit',
                                                second: '2-digit'
                                            });
                                        }
                                    }
                                }
                            },
                            animation: { duration: 0 }
                        }
                    };

                    memoryChart = new Chart($('#memoryChart')[0].getContext('2d'), {
                        type: 'line',
                        data: {
                            labels: [],
                            datasets: [
                                {
                                    label: 'Current Memory',
                                    borderColor: '#58a6ff',
                                    backgroundColor: 'rgba(88,166,255,0.1)',
                                    data: [],
                                    fill: true,
                                    tension: 0.4,
                                    pointRadius: 0
                                },
                                {
                                    label: 'Peak Memory',
                                    borderColor: '#d29922',
                                    backgroundColor: 'rgba(210,153,34,0.1)',
                                    data: [],
                                    fill: true,
                                    tension: 0.4,
                                    pointRadius: 0
                                },
                                {
                                    label: 'System Route Avg',
                                    borderColor: '#a371f7',
                                    backgroundColor: 'rgba(163,113,247,0.1)',
                                    data: [],
                                    fill: true,
                                    tension: 0.4,
                                    pointRadius: 0
                                }
                            ]
                        },
                        options: {
                            ...chartConfig.options,
                            scales: {
                                x: { display: false, grid: { display: false } },
                                y: {
                                    beginAtZero: true,
                                    max: currentMemoryScale,
                                    grid: { color: '#30363d' },
                                    ticks: {
                                        color: '#8b949e',
                                        maxTicksLimit: 6,
                                        callback: function (value) { return value + ' MB'; }
                                    }
                                }
                            }
                        }
                    });

                    responseChart = new Chart($('#responseChart')[0].getContext('2d'), {
                        type: 'line',
                        data: {
                            labels: [],
                            datasets: [
                                {
                                    label: 'App p50',
                                    borderColor: '#3fb950',
                                    backgroundColor: 'rgba(63,185,80,0.08)',
                                    data: [],
                                    fill: true,
                                    tension: 0.4,
                                    pointRadius: 0
                                },
                                {
                                    label: 'App p95',
                                    borderColor: '#2ea043',
                                    backgroundColor: 'rgba(46,160,67,0.06)',
                                    data: [],
                                    fill: true,
                                    tension: 0.4,
                                    pointRadius: 0
                                },
                                {
                                    label: 'System p50',
                                    borderColor: '#a371f7',
                                    backgroundColor: 'rgba(163,113,247,0.08)',
                                    data: [],
                                    fill: true,
                                    tension: 0.4,
                                    pointRadius: 0
                                },
                                {
                                    label: 'System p95',
                                    borderColor: '#d2a8ff',
                                    backgroundColor: 'rgba(210,168,255,0.06)',
                                    data: [],
                                    fill: true,
                                    tension: 0.4,
                                    pointRadius: 0
                                }
                            ]
                        },
                        options: chartConfig.options
                    });

                    requestRateChart = new Chart($('#requestRateChart')[0].getContext('2d'), {
                        type: 'line',
                        data: {
                            labels: [],
                            datasets: [
                                {
                                    label: 'App Routes',
                                    borderColor: '#58a6ff',
                                    backgroundColor: 'rgba(88,166,255,0.1)',
                                    data: [],
                                    fill: true,
                                    tension: 0.4,
                                    pointRadius: 0
                                },
                                {
                                    label: 'System Routes',
                                    borderColor: '#a371f7',
                                    backgroundColor: 'rgba(163,113,247,0.1)',
                                    data: [],
                                    fill: true,
                                    tension: 0.4,
                                    pointRadius: 0
                                }
                            ]
                        },
                        options: chartConfig.options
                    });

                    dbMemoryChart = new Chart($('#dbMemoryChart')[0].getContext('2d'), {
                        ...chartConfig,
                        data: {
                            labels: [],
                            datasets: [
                                {
                                    label: 'DB Queries',
                                    borderColor: '#a371f7',
                                    backgroundColor: 'rgba(163,113,247,0.1)',
                                    data: [],
                                    fill: true,
                                    tension: 0.4,
                                    pointRadius: 0,
                                    yAxisID: 'y'
                                },
                                {
                                    label: 'Memory %',
                                    borderColor: '#fbbf24',
                                    backgroundColor: 'rgba(251,191,36,0.1)',
                                    data: [],
                                    fill: true,
                                    tension: 0.4,
                                    pointRadius: 0,
                                    yAxisID: 'y1'
                                }
                            ]
                        },
                        options: {
                            ...chartConfig.options,
                            scales: {
                                ...chartConfig.options.scales,
                                y1: {
                                    type: 'linear',
                                    position: 'right',
                                    beginAtZero: true,
                                    max: 100,
                                    grid: { drawOnChartArea: false },
                                    ticks: {
                                        color: '#8b949e',
                                        callback: function (value) { return value + '%'; }
                                    }
                                }
                            }
                        }
                    });
                }

                function updateChartMulti(chart, values) {
                    const now = new Date().getTime();
                    chart.data.labels.push(now);

                    values.forEach((value, index) => {
                        if (chart.data.datasets[index]) {
                            chart.data.datasets[index].data.push(value);
                        }
                    });

                    if (chart.data.labels.length > maxDataPoints) {
                        chart.data.labels.shift();
                        chart.data.datasets.forEach(dataset => {
                            dataset.data.shift();
                        });
                    }

                    chart.update('none');
                }

                // ============================================
                // Modal Functions
                // ============================================
                function openGuide() {
                    document.getElementById('guideModal').showModal();
                }

                function closeGuide() {
                    document.getElementById('guideModal').close();
                }

                function closeModal() {
                    const dialog = document.getElementById('chartModal');
                    dialog.close();

                    if (modalChart) {
                        modalChart.destroy();
                        modalChart = null;
                    }
                }

                function openModal(chartType, title) {
                    const dialog = document.getElementById('chartModal');
                    $('#modalTitle').text(title);

                    if (modalChart) {
                        modalChart.destroy();
                        modalChart = null;
                    }

                    let sourceChart;
                    switch (chartType) {
                        case 'memory': sourceChart = memoryChart; break;
                        case 'response': sourceChart = responseChart; break;
                        case 'requestRate': sourceChart = requestRateChart; break;
                        case 'dbMemory': sourceChart = dbMemoryChart; break;
                        default: sourceChart = memoryChart;
                    }

                    const clonedData = {
                        labels: [...sourceChart.data.labels],
                        datasets: sourceChart.data.datasets.map(dataset => ({
                            ...dataset,
                            data: [...dataset.data]
                        }))
                    };

                    const modalOptions = {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { intersect: false },
                        scales: {
                            x: {
                                display: true,
                                ticks: {
                                    maxRotation: 0,
                                    autoSkipPadding: 10,
                                    color: '#8b949e',
                                    callback: function (value) {
                                        const timestamp = this.getLabelForValue(value);
                                        const date = new Date(Number(timestamp));
                                        return date.toLocaleTimeString('en-US', {
                                            hour12: false,
                                            hour: '2-digit',
                                            minute: '2-digit',
                                            second: '2-digit'
                                        });
                                    }
                                },
                                grid: { color: '#30363d' }
                            },
                            y: {
                                beginAtZero: true,
                                grid: { color: '#30363d' },
                                ticks: { color: '#8b949e' }
                            }
                        },
                        plugins: {
                            legend: {
                                labels: { color: '#c9d1d9', font: { size: 14 } },
                                padding: 20
                            },
                            tooltip: {
                                callbacks: {
                                    title: function (context) {
                                        const date = new Date(Number(context[0].label));
                                        return date.toLocaleTimeString('en-US', {
                                            hour12: false,
                                            hour: '2-digit',
                                            minute: '2-digit',
                                            second: '2-digit'
                                        });
                                    }
                                }
                            }
                        },
                        animation: { duration: 300 }
                    };

                    if (chartType === 'dbMemory') {
                        modalOptions.scales.y1 = {
                            type: 'linear',
                            position: 'right',
                            beginAtZero: true,
                            max: 100,
                            grid: { drawOnChartArea: false },
                            ticks: {
                                color: '#8b949e',
                                callback: function (value) { return value + '%'; }
                            }
                        };
                    }

                    if (chartType === 'memory') {
                        modalOptions.scales.y.max = currentMemoryScale;
                        modalOptions.scales.y.ticks.maxTicksLimit = 6;
                        modalOptions.scales.y.ticks.callback = function (value) {
                            return value + ' MB';
                        };
                    }

                    dialog.showModal();

                    setTimeout(() => {
                        modalChart = new Chart($('#modalChart')[0].getContext('2d'), {
                            type: sourceChart.config.type,
                            data: clonedData,
                            options: modalOptions
                        });
                    }, 50);

                    const updateInterval = setInterval(function () {
                        if (dialog.open && modalChart) {
                            const sourceData = sourceChart.data;
                            modalChart.data.labels = [...sourceData.labels];
                            modalChart.data.datasets.forEach((dataset, i) => {
                                dataset.data = [...sourceData.datasets[i].data];
                            });
                            modalChart.update('none');
                        } else {
                            clearInterval(updateInterval);
                        }
                    }, 500);
                }

                // ============================================
                // Event Listeners
                // ============================================
                $(document).ready(function () {
                    initializeCharts();

                    $(document).on('click', '.expand-icon', function (e) {
                        e.preventDefault();
                        e.stopPropagation();
                        const card = $(this).closest('.card');
                        openModal(card.data('chart'), card.data('title'));
                    });

                    $(document).on('click', '.filter-btn', function () {
                        setRequestsFilter($(this).data('filter'));
                    });

                    setRequestsFilter(currentRequestsFilter);

                    if (typeof EventSource !== 'undefined') {
                        console.log('✅ EventSource supported, starting SSE stream...');
                        startSSE();
                    } else {
                        console.error('❌ EventSource not supported in this browser');
                        $('#systemStatus')
                            .removeClass('status-healthy')
                            .addClass('status-error')
                            .text('Browser Not Supported');
                    }
                });

                document.getElementById('chartModal')?.addEventListener('click', function (e) {
                    if (e.target.tagName === 'DIALOG') {
                        closeModal();
                    }
                });

                document.getElementById('guideModal')?.addEventListener('click', function (e) {
                    if (e.target === e.currentTarget) {
                        closeGuide();
                    }
                });
            </script>
        </body>

        </html>
        <?php
    }




}
