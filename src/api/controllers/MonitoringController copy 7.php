<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Services\MetricsLogger;
use App\Core\Middlewares\MonitoringMiddleware;

/**
 * MonitoringController - System monitoring and metrics dashboard
 * Only accessible when ENABLE_MONITORING=true in .env
 */
class MonitoringController extends BaseController
{
    private MetricsLogger $logger;
    private static array $activeViewers = [];
    private const VIEWER_TIMEOUT = 30; // seconds

    public function __construct()
    {
        parent::__construct();

        if (!$this->isMonitoringEnabled()) {
            $this->sendError('Monitoring is disabled', 403);
            exit;
        }

        $this->logger = new MetricsLogger();
    }

    /**
     * Main monitoring dashboard UI
     */
    public function index(): void
    {
        $this->renderDashboard();
    }





    public function stream(): void
{
    // Set SSE headers
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no'); // Nginx
    
    // Disable output buffering
    if (ob_get_level()) ob_end_clean();
    
    $viewerId = $_GET['viewerid'] ?? $this->generateViewerId();
    $maxViewers = (int) ($_ENV['MAX_MONITORING_VIEWERS'] ?? 2);
    
    // Check viewer limit
    $this->cleanupInactiveViewers();
    
    if (count(self::$activeViewers) >= $maxViewers && !isset(self::$activeViewers[$viewerId])) {
        echo "event: error\n";
        echo "data: " . json_encode(['message' => 'Maximum viewers reached']) . "\n\n";
        flush();
        return;
    }
    
    self::$activeViewers[$viewerId] = time();
    
    // Send initial viewer ID
    echo "event: connected\n";
    echo "data: " . json_encode(['viewer_id' => $viewerId]) . "\n\n";
    flush();
    
    // Stream loop (send updates every 500ms)
    $startTime = time();
    $maxDuration = 300; // 5 minutes max, then client reconnects
    
    while (time() - $startTime < $maxDuration && connection_status() === CONNECTION_NORMAL) {
        $this->cleanupInactiveViewers();
        self::$activeViewers[$viewerId] = time();
        
        // Get metrics (same as live() endpoint)
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
        
        $data = [
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
        ];
        
        echo "event: metrics\n";
        echo "data: " . json_encode($data) . "\n\n";
        flush();
        
        usleep(500000); // 500ms
    }
    
    // Clean up on disconnect
    unset(self::$activeViewers[$viewerId]);
}






    /**
     * Get live metrics (polled every 500ms)
     */
    public function live(): void
    {
        $this->cleanupInactiveViewers();

        $viewerId = $_GET['viewerid'] ?? null;
        $maxViewers = (int) ($_ENV['MAX_MONITORING_VIEWERS'] ?? 2);

        if (!$viewerId) {
            $viewerId = $this->generateViewerId();
        }

        self::$activeViewers[$viewerId] = time();

        if (count(self::$activeViewers) > $maxViewers && !isset(self::$activeViewers[$viewerId])) {
            $this->sendError('Maximum number of viewers reached', 429);
            return;
        }

        $metrics = MonitoringMiddleware::getCurrentResourceUsage();
        $todayMetrics = $this->logger->getMetrics();

        // Separate system and app route metrics
        $systemMetrics = [];
        $appMetrics = [];

        foreach ($todayMetrics as $metric) {
            if (!empty($metric['is_system_route'])) {
                $systemMetrics[] = $metric;
            } else {
                $appMetrics[] = $metric;
            }
        }

        // Calculate system route memory usage
        $systemRouteMemory = 0;
        $systemRouteCount = count($systemMetrics);
        foreach ($systemMetrics as $metric) {
            $systemRouteMemory += (float) ($metric['memory_used'] ?? 0);
        }
        $avgSystemRouteMemory = $systemRouteCount > 0 ? round($systemRouteMemory / $systemRouteCount, 2) : 0;
        $metrics['memory']['system_route'] = $avgSystemRouteMemory;

        $latestRequests = array_slice(array_reverse($todayMetrics), 0, 10);

        // Recent metrics (last 10 for real-time movement)
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

    /**
     * Get historical metrics
     */
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

    /**
     * Health check endpoint
     */
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
            'usedpercent' => 'N/A',
        ];

        $diskPercentRaw = $diskUsage['used_percent'] ?? $diskUsage['usedpercent'] ?? 'N/A';
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

    /**
     * Get endpoint statistics
     */
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

    /**
     * Clear all monitoring logs (admin only)
     */
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

    /**
     * Render monitoring dashboard HTML with Chart.js
     */
    private function renderDashboard(): void
    {
        $basePath = $_ENV['API_BASE_PATH'] ?? '';
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

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            padding: 20px;
            line-height: 1.6;
        }

        .container { max-width: 1600px; margin: 0 auto; }

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

        .header h1 { font-size: 28px; color: var(--accent-color); margin: 0; }

        .header-info {
            display: flex;
            gap: 20px;
            align-items: center;
            flex-wrap: wrap;
        }

        .status-badge {
            padding: 6px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 14px;
        }

        .status-healthy { background: var(--success-color); color: white; }
        .status-warning { background: var(--warning-color); color: white; }
        .status-error { background: var(--danger-color); color: white; }

        .uptime-badge {
            font-size: 12px;
            color: var(--text-secondary);
            padding: 4px 12px;
            background: var(--bg-tertiary);
            border-radius: 6px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            grid-template-rows: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        @media (max-width: 1200px) {
            .grid { grid-template-columns: 1fr; grid-template-rows: auto; }
        }

        .card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            position: relative;
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

        .chart-container { position: relative; height: 200px; }

        /* Requests controls */
        .requests-controls {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }

        .filter-btn {
            background: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 6px 10px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 12px;
        }

        .filter-btn.active {
            border-color: var(--accent-color);
            box-shadow: 0 0 0 2px rgba(88, 166, 255, 0.12);
        }

        /* Type badges (SYSTEM vs APP) */
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
            border: 1px solid var(--border-color);
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }

        .route-app {
            border-color: rgba(88, 166, 255, 0.35);
            color: #58a6ff;
            background: rgba(88, 166, 255, 0.10);
        }

        .route-system {
            border-color: rgba(163, 113, 247, 0.35);
            color: #d2a8ff;
            background: rgba(163, 113, 247, 0.10);
        }

        /* Latest requests table */
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
            font-family: Consolas, Monaco, Menlo, monospace;
            letter-spacing: 0.5px;
        }

        .table-memory {
            font-size: 18px;
            font-weight: 700;
            color: var(--warning-color);
            font-family: Consolas, Monaco, Menlo, monospace;
        }

        .table-response-time {
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

        .method-GET { background: var(--success-color); opacity: 0.6; color: white; }
        .method-POST { background: var(--accent-color); color: white; }
        .method-PUT { background: var(--warning-color); color: #212529; }
        .method-DELETE { background: var(--danger-color); color: white; }

        .table-status {
            padding: 5px 12px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 13px;
            display: inline-block;
            min-width: 45px;
            text-align: center;
        }

        .status-2xx { background: rgba(63, 185, 80, 0.2); color: var(--success-color); }
        .status-4xx { background: rgba(210, 153, 34, 0.2); color: var(--warning-color); }
        .status-5xx { background: rgba(248, 81, 73, 0.2); color: var(--danger-color); }

        .table-endpoint {
            font-family: Consolas, Monaco, Menlo, monospace;
            font-size: 14px;
            color: var(--text-primary);
            max-width: 400px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .table-queries { color: var(--text-secondary); font-size: 13px; }

        @media (max-width: 1200px) {
            .requests-table { display: block; overflow-x: auto; white-space: nowrap; }
        }

        .loading { text-align: center; padding: 40px; color: var(--text-secondary); }

        .refresh-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            background: var(--success-color);
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }

        .viewer-info {
            font-size: 12px;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .refresh-control {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: var(--text-secondary);
        }

        .refresh-control input[type="number"] {
            width: 80px;
            padding: 4px 8px;
            background: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            color: var(--text-primary);
            font-size: 14px;
        }

        .refresh-control input[type="number"]:focus {
            outline: none;
            border-color: var(--accent-color);
        }

        /* Modal dialog */
        .chart-dialog {
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

        .chart-dialog::backdrop {
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
    </style>
</head>

<body>
<div class="container">
    <div class="header">
        <div>
            <h1>System Monitoring</h1>
        </div>

        <div class="header-info">
            <div class="viewer-info">
                <span class="refresh-indicator"></span>
                <span>Auto-refresh: <span id="currentInterval">500</span>ms</span>
                <span id="viewerCount"></span>
            </div>

            <div class="refresh-control">
                <label for="refreshInterval">Interval (ms) [press Enter to set]:</label>
                <input type="number" id="refreshInterval" value="500" min="100" max="10000" step="100">
            </div>

            <div class="uptime-badge">
                Uptime: <span id="uptime">Loading...</span>
            </div>

            <div id="systemStatus" class="status-badge status-healthy">Healthy</div>
        </div>
    </div>

    <div class="grid">
        <div class="card" data-chart="memory" data-title="Memory Usage (MB)">
            <h3>
                Memory Usage (MB)
                <button type="button" class="expand-icon" aria-label="Expand Memory chart">⛶</button>
            </h3>
            <div class="chart-container"><canvas id="memoryChart"></canvas></div>
        </div>

        <div class="card" data-chart="response" data-title="Response Time (ms)">
            <h3>
                Response Time (ms)
                <button type="button" class="expand-icon" aria-label="Expand Response Time chart">⛶</button>
            </h3>
            <div class="chart-container"><canvas id="responseChart"></canvas></div>
        </div>

        <div class="card" data-chart="requestRate" data-title="Requests Per Second">
            <h3>
                Requests Per Second
                <button type="button" class="expand-icon" aria-label="Expand Requests Per Second chart">⛶</button>
            </h3>
            <div class="chart-container"><canvas id="requestRateChart"></canvas></div>
        </div>

        <div class="card" data-chart="dbMemory" data-title="Database Memory">
            <h3>
                Database Memory
                <button type="button" class="expand-icon" aria-label="Expand Database Memory chart">⛶</button>
            </h3>
            <div class="chart-container"><canvas id="dbMemoryChart"></canvas></div>
        </div>
    </div>

    <div class="card">
        <h3>
            Latest Requests
            <span style="color: var(--text-secondary); font-size: 12px; font-weight: 600;" id="latestRequestsFilterLabel">(All)</span>
        </h3>

        <div class="requests-controls">
            <button type="button" class="filter-btn" data-filter="all">All</button>
            <button type="button" class="filter-btn" data-filter="app">App</button>
            <button type="button" class="filter-btn" data-filter="system">System</button>
        </div>

        <div id="latestRequests" class="loading">Loading...</div>
    </div>
</div>

<dialog id="chartModal" class="chart-dialog">
    <div class="dialog-header">
        <h2 id="modalTitle"></h2>
        <button class="modal-close" onclick="closeModal()" aria-label="Close modal">&times;</button>
    </div>
    <div class="dialog-body">
        <canvas id="modalChart"></canvas>
    </div>
</dialog>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6"></script>
<script>
    let viewerId = null;
    const basePath = '<?php echo $basePath; ?>';

    let memoryChart, responseChart, requestRateChart, dbMemoryChart, modalChart;
    let eventSource = null;
    let useFallback = false;

    // Separate request counts for system and app routes
    let appRequestCount = 0;
    let systemRequestCount = 0;
    let lastUpdateTime = Date.now();

    // Latest requests UI state
    let lastLatestRequestsCache = [];
    let currentRequestsFilter = localStorage.getItem('monitoringRequestsFilter') || 'all';

    const maxDataPoints = 120;
    let maxMemoryLimit = 512;
    let currentMemoryScale = 100;

    function parseMemoryLimit(limitString) {
        if (typeof limitString !== 'string') limitString = String(limitString);
        if (!limitString || limitString === '-1' || limitString === 'unlimited') return 512;

        const units = { 'K': 1024, 'M': 1024 * 1024, 'G': 1024 * 1024 * 1024 };
        const match = limitString.match(/^(\d+)([KMG])?$/i);

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
        const newScale = Math.ceil(maxValue / 100) * 100;
        return Math.max(100, newScale);
    }

    function setRequestsFilter(filter) {
        currentRequestsFilter = filter;
        localStorage.setItem('monitoringRequestsFilter', filter);

        $('.filter-btn').removeClass('active');
        $(`.filter-btn[data-filter="${filter}"]`).addClass('active');

        const label = filter === 'all' ? '(All)' : (filter === 'app' ? '(App)' : '(System)');
        $('#latestRequestsFilterLabel').text(label);

        updateLatestRequests(lastLatestRequestsCache);
    }

    // ========== SSE + Fallback Logic ==========

    function startSSE() {
        if (useFallback) {
            startPolling();
            return;
        }

        const url = viewerId 
            ? `/${basePath}/monitoring/stream?viewerid=${viewerId}` 
            : `/${basePath}/monitoring/stream`;

        eventSource = new EventSource(url);

        eventSource.addEventListener('connected', function(e) {
            const data = JSON.parse(e.data);
            viewerId = data.viewer_id;
            console.log('SSE connected, viewer ID:', viewerId);
        });

        eventSource.addEventListener('metrics', function(e) {
            const result = { status: 'success', data: JSON.parse(e.data) };
            handleMetricsUpdate(result);
        });

        eventSource.addEventListener('error', function(e) {
            console.error('SSE error, falling back to polling');
            if (eventSource) eventSource.close();
            useFallback = true;
            startPolling();
        });
    }

    function startPolling() {
        fetchMetrics();

        let currentRefreshInterval = parseInt($('#refreshInterval').val()) || 500;
        const refreshIntervalId = setInterval(fetchMetrics, currentRefreshInterval);

        $('#refreshInterval').off('change').on('change', function() {
            clearInterval(refreshIntervalId);
            startPolling();
        });

        $('#refreshInterval').off('keyup').on('keyup', function(e) {
            if (e.key === 'Enter') {
                clearInterval(refreshIntervalId);
                startPolling();
            }
        });
    }

    function fetchMetrics() {
        const endpoint = `/${basePath}/monitoring/live`;
        const url = viewerId ? `${endpoint}?viewerid=${viewerId}` : endpoint;

        $.ajax({
            url: url,
            method: 'GET',
            dataType: 'json',
            success: function (result) {
                handleMetricsUpdate(result);
            },
            error: function () {
                $('#systemStatus').removeClass('status-healthy status-warning').addClass('status-error').text('Connection Error');
            }
        });
    }

    function handleMetricsUpdate(result) {
        if (result.status !== 'success') return;

        const data = result.data || {};
        viewerId = data.viewer_id ?? data.viewerid ?? viewerId;

        const resources = data.current_resources ?? data.currentresources ?? {};
        const memory = resources.memory ?? {};
        const server = resources.server ?? {};
        const recentStats = data.recent_stats ?? data.recentstats ?? {};

        const limitValue = memory.limit_formatted ?? memory.limit;
        if (limitValue != null) {
            const newLimit = parseMemoryLimit(limitValue);
            if (newLimit !== maxMemoryLimit) maxMemoryLimit = newLimit;
        }

        // Memory chart
        const currentMem = parseFloat(memory.current || 0);
        const peakMem = parseFloat(memory.peak || 0);
        const systemMem = parseFloat((memory.system_route ?? memory.systemroute) || 0);

        const newScale = calculateDynamicMemoryScale(currentMem, peakMem, systemMem);
        if (newScale !== currentMemoryScale) {
            currentMemoryScale = newScale;
            if (memoryChart && memoryChart.options.scales.y) memoryChart.options.scales.y.max = currentMemoryScale;
        }
        updateChartMulti(memoryChart, [currentMem, peakMem, systemMem]);

        // Response chart
        const appP50 = parseFloat(recentStats?.app?.p50_response_time ?? recentStats?.app?.p50responsetime ?? 0);
        const appP95 = parseFloat(recentStats?.app?.p95_response_time ?? recentStats?.app?.p95responsetime ?? 0);
        const systemP50 = parseFloat(recentStats?.system?.p50_response_time ?? recentStats?.system?.p50responsetime ?? 0);
        const systemP95 = parseFloat(recentStats?.system?.p95_response_time ?? recentStats?.system?.p95responsetime ?? 0);
        updateChartMulti(responseChart, [appP50, appP95, systemP50, systemP95]);

        // Request rate
        const now = Date.now();
        const timeDiff = (now - lastUpdateTime) / 1000;

        const newAppRequests = parseInt(recentStats?.app?.total_requests ?? recentStats?.app?.totalrequests ?? 0);
        const newSystemRequests = parseInt(recentStats?.system?.total_requests ?? recentStats?.system?.totalrequests ?? 0);

        const appRequestRate = timeDiff > 0 ? Math.round((newAppRequests - appRequestCount) / timeDiff) : 0;
        const systemRequestRate = timeDiff > 0 ? Math.round((newSystemRequests - systemRequestCount) / timeDiff) : 0;

        updateChartMulti(requestRateChart, [Math.max(0, appRequestRate), Math.max(0, systemRequestRate)]);

        appRequestCount = newAppRequests;
        systemRequestCount = newSystemRequests;
        lastUpdateTime = now;

        // DB chart
        const dbQueries = parseFloat(
            recentStats?.app?.avg_db_queries ??
            recentStats?.system?.avg_db_queries ??
            recentStats?.app?.avgdbqueries ??
            recentStats?.system?.avgdbqueries ??
            0
        );
        const memoryPercent = parseFloat(memory.percentage || 0);
        updateChartMulti(dbMemoryChart, [dbQueries, memoryPercent]);

        // Latest requests
        const latestRequests = data.latest_requests ?? data.latestrequests ?? [];
        lastLatestRequestsCache = latestRequests;
        updateLatestRequests(latestRequests);

        // Viewers
        const active = data.active_viewers ?? data.activeviewers ?? 0;
        const max = data.max_viewers ?? data.maxviewers ?? 0;
        updateViewerCount(active, max);

        $('#uptime').text(server.uptime || 'N/A');
        $('#systemStatus').removeClass('status-error status-warning').addClass('status-healthy').text('Healthy');
    }

    // ========== Initialization ==========

    $(document).ready(function () {
        initializeCharts();

        // Expand modal ONLY when expand icon is clicked
        $(document).on('click', '.expand-icon', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $card = $(this).closest('.card');
            openModal($card.data('chart'), $card.data('title'));
        });

        // Requests filter buttons
        $(document).on('click', '.filter-btn', function () {
            const f = $(this).data('filter');
            setRequestsFilter(f);
        });

        // Apply initial filter state
        setRequestsFilter(currentRequestsFilter);

        // Try SSE first, fallback to polling if not supported
        if (typeof EventSource !== 'undefined') {
            console.log('Starting SSE transport...');
            startSSE();
        } else {
            console.log('EventSource not supported, using polling');
            useFallback = true;
            startPolling();
        }

        // Update interval display (for polling mode)
        const savedInterval = localStorage.getItem('monitoringRefreshInterval');
        if (savedInterval) $('#refreshInterval').val(savedInterval);
        $('#currentInterval').text($('#refreshInterval').val());
    });

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
                                return date.toLocaleTimeString('en-US', { hour12: false, hour: '2-digit', minute: '2-digit', second: '2-digit' });
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
                    { label: 'Current Memory (MB)', borderColor: '#58a6ff', backgroundColor: 'rgba(88,166,255,0.1)', data: [], fill: true, tension: 0.4, pointRadius: 0 },
                    { label: 'Peak Memory (MB)', borderColor: '#d29922', backgroundColor: 'rgba(210,153,34,0.1)', data: [], fill: true, tension: 0.4, pointRadius: 0 },
                    { label: 'System Route Avg (MB)', borderColor: '#f85149', backgroundColor: 'rgba(248,81,73,0.1)', data: [], fill: true, tension: 0.4, pointRadius: 0 }
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
                    { label: 'App p50 ms', borderColor: '#3fb950', backgroundColor: 'rgba(63,185,80,0.08)', data: [], fill: true, tension: 0.4, pointRadius: 0 },
                    { label: 'App p95 ms', borderColor: '#2ea043', backgroundColor: 'rgba(46,160,67,0.06)', data: [], fill: true, tension: 0.4, pointRadius: 0 },
                    { label: 'System p50 ms', borderColor: '#a371f7', backgroundColor: 'rgba(163,113,247,0.08)', data: [], fill: true, tension: 0.4, pointRadius: 0 },
                    { label: 'System p95 ms', borderColor: '#d2a8ff', backgroundColor: 'rgba(210,168,255,0.06)', data: [], fill: true, tension: 0.4, pointRadius: 0 }
                ]
            },
            options: { ...chartConfig.options }
        });

        requestRateChart = new Chart($('#requestRateChart')[0].getContext('2d'), {
            type: 'line',
            data: {
                labels: [],
                datasets: [
                    { label: 'App Routes (req/s)', borderColor: '#58a6ff', backgroundColor: 'rgba(88,166,255,0.1)', data: [], fill: true, tension: 0.4, pointRadius: 0 },
                    { label: 'System Routes (req/s)', borderColor: '#f85149', backgroundColor: 'rgba(248,81,73,0.1)', data: [], fill: true, tension: 0.4, pointRadius: 0 }
                ]
            },
            options: { ...chartConfig.options }
        });

        dbMemoryChart = new Chart($('#dbMemoryChart')[0].getContext('2d'), {
            ...chartConfig,
            data: {
                labels: [],
                datasets: [
                    { label: 'DB Queries', borderColor: '#a371f7', backgroundColor: 'rgba(163,113,247,0.1)', data: [], fill: true, tension: 0.4, pointRadius: 0, yAxisID: 'y' },
                    { label: 'Memory Usage %', borderColor: '#fbbf24', backgroundColor: 'rgba(251,191,36,0.1)', data: [], fill: true, tension: 0.4, pointRadius: 0, yAxisID: 'y1' }
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
            if (chart.data.datasets[index]) chart.data.datasets[index].data.push(value);
        });

        if (chart.data.labels.length > maxDataPoints) {
            chart.data.labels.shift();
            chart.data.datasets.forEach(dataset => dataset.data.shift());
        }

        chart.update('none');
    }

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
        html += '<th>Response</th><th>Memory</th><th>Queries</th>';
        html += '</tr></thead><tbody>';

        $.each(filtered, function (index, req) {
            const code = req.status_code ?? req.statuscode ?? req.statusCode ?? 0;
            const statusClass = getStatusClass(parseInt(code, 10) || 0);

            const ts = (req.timestamp || '').split(' ');
            const time = ts.length > 1 ? ts[1] : (req.timestamp || '');

            const isSystem = !!req.is_system_route;
            const typeText = isSystem ? 'SYSTEM' : 'APP';
            const typeClass = isSystem ? 'route-system' : 'route-app';

            const responseTime = req.response_time ?? req.responsetime ?? req.responseTime ?? 0;
            const memoryUsed = req.memory_used ?? req.memoryused ?? req.memoryUsed ?? 0;
            const queries = req.db_queries ?? req.dbqueries ?? 0;

            html += '<tr>';
            html += `<td><span class="table-method method-${req.method}">${req.method}</span></td>`;
            html += `<td><span class="route-badge ${typeClass}">${typeText}</span></td>`;
            html += `<td><span class="table-status ${statusClass}">${code}</span></td>`;
            html += `<td><span class="table-endpoint" title="${req.endpoint}">${req.endpoint}</span></td>`;
            html += `<td><span class="table-time">${time}</span></td>`;
            html += `<td><span class="table-response-time">${responseTime}ms</span></td>`;
            html += `<td><span class="table-memory">${memoryUsed} MB</span></td>`;
            html += `<td><span class="table-queries">${queries}</span></td>`;
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

    function updateViewerCount(active, max) {
        $('#viewerCount').text(`Viewers: ${active}/${max}`);
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
            default: sourceChart = memoryChart; break;
        }

        const clonedData = {
            labels: [...sourceChart.data.labels],
            datasets: sourceChart.data.datasets.map(dataset => ({ ...dataset, data: [...dataset.data] }))
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
                            return date.toLocaleTimeString('en-US', { hour12: false, hour: '2-digit', minute: '2-digit', second: '2-digit' });
                        }
                    },
                    grid: { color: '#30363d' }
                },
                y: { beginAtZero: true, grid: { color: '#30363d' }, ticks: { color: '#8b949e' } }
            },
            plugins: {
                legend: { labels: { color: '#c9d1d9', font: { size: 14 }, padding: 20 } },
                tooltip: {
                    callbacks: {
                        title: function (context) {
                            const date = new Date(Number(context[0].label));
                            return date.toLocaleTimeString('en-US', { hour12: false, hour: '2-digit', minute: '2-digit', second: '2-digit' });
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
                ticks: { color: '#8b949e', callback: function (value) { return value + '%'; } }
            };
        }

        if (chartType === 'memory') {
            modalOptions.scales.y.max = currentMemoryScale;
            modalOptions.scales.y.ticks.callback = function (value) { return value + ' MB'; };
        }

        dialog.showModal();

        const canvas = $('#modalChart')[0];
        const ctx = canvas.getContext('2d');

        setTimeout(() => {
            modalChart = new Chart(ctx, {
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

    document.getElementById('chartModal')?.addEventListener('click', function (e) {
        if (e.target.tagName === 'DIALOG') closeModal();
    });
</script>

</body>
</html>
        <?php
    }
}
