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

        // Check if monitoring is enabled
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

    /**
     * Get live metrics (polled every 500ms)
     */
    public function live_old(): void
    {
        // Check viewer limit
        $this->cleanupInactiveViewers();
        $viewerId = $_GET['viewerid'] ?? null;
        $maxViewers = (int) ($_ENV['MAX_MONITORING_VIEWERS'] ?? 2);

        if (!$viewerId) {
            $viewerId = $this->generateViewerId();
        }

        // Register or update viewer
        self::$activeViewers[$viewerId] = time();

        // Check if we're over the limit
        if (count(self::$activeViewers) > $maxViewers && !isset(self::$activeViewers[$viewerId])) {
            $this->sendError('Maximum number of viewers reached', 429);
            return;
        }

        // Get current resource usage
        $metrics = MonitoringMiddleware::getCurrentResourceUsage();

        // Get latest requests from today's log
        $todayMetrics = $this->logger->getMetrics();

        // Filter system routes if requested
        $filterSystem = $_GET['filter_system'] ?? 'true';
        if ($filterSystem === 'false') {
            $todayMetrics = array_filter($todayMetrics, function ($metric) {
                $endpoint = $metric['endpoint'] ?? '';
                return !$this->isSystemRoute($endpoint);
            });
        }

        $latestRequests = array_slice(array_reverse($todayMetrics), 0, 10);

        // Calculate recent stats (last 100 requests)
        $recentMetrics = array_slice(array_reverse($todayMetrics), 0, 100);
        $recentStats = $this->logger->getAggregatedStats($recentMetrics);

        $this->sendSuccess('Live metrics retrieved', [
            'viewerid' => $viewerId,
            'activeviewers' => count(self::$activeViewers),
            'maxviewers' => $maxViewers,
            'currentresources' => $metrics,
            'latestrequests' => $latestRequests,
            'recentstats' => $recentStats,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

    public function live(): void
    {
        // Check viewer limit
        $this->cleanupInactiveViewers();
        $viewerId = $_GET['viewerid'] ?? null;
        $maxViewers = (int) ($_ENV['MAX_MONITORING_VIEWERS'] ?? 2);

        if (!$viewerId) {
            $viewerId = $this->generateViewerId();
        }

        // Register or update viewer
        self::$activeViewers[$viewerId] = time();

        // Check if we're over the limit
        if (count(self::$activeViewers) > $maxViewers && !isset(self::$activeViewers[$viewerId])) {
            $this->sendError('Maximum number of viewers reached', 429);
            return;
        }

        // Get current resource usage
        $metrics = MonitoringMiddleware::getCurrentResourceUsage();

        // Get latest requests from today's log
        $todayMetrics = $this->logger->getMetrics();

        // Calculate system route memory usage
        $systemRouteMemory = 0;
        $systemRouteCount = 0;
        foreach ($todayMetrics as $metric) {
            $endpoint = $metric['endpoint'] ?? '';
            if ($this->isSystemRoute($endpoint)) {
                $systemRouteMemory += (float) ($metric['memoryused'] ?? 0);
                $systemRouteCount++;
            }
        }
        $avgSystemRouteMemory = $systemRouteCount > 0 ? round($systemRouteMemory / $systemRouteCount, 2) : 0;

        // Add to metrics array
        $metrics['memory']['systemroute'] = $avgSystemRouteMemory;

        // Filter system routes if requested
        $filterSystem = $_GET['filtersystem'] ?? 'true';
        if ($filterSystem === 'false') {
            $todayMetrics = array_filter($todayMetrics, function ($metric) {
                $endpoint = $metric['endpoint'] ?? '';
                return !$this->isSystemRoute($endpoint);
            });
        }

        $latestRequests = array_slice(array_reverse($todayMetrics), 0, 10);

        // Calculate recent stats (last 100 requests)
        $recentMetrics = array_slice(array_reverse($todayMetrics), 0, 100);
        $recentStats = $this->logger->getAggregatedStats($recentMetrics);

        $this->sendSuccess('Live metrics retrieved', [
            'viewerid' => $viewerId,
            'activeviewers' => count(self::$activeViewers),
            'maxviewers' => $maxViewers,
            'currentresources' => $metrics,
            'latestrequests' => $latestRequests,
            'recentstats' => $recentStats,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }


    /**
     * Get historical metrics
     */
    public function history(): void
    {
        $days = (int) ($_GET['days'] ?? 7);
        $days = min($days, 7); // Max 7 days

        $date = $_GET['date'] ?? null;

        if ($date) {
            // Get specific date metrics
            $metrics = $this->logger->getMetrics($date);
            $stats = $this->logger->getAggregatedStats($metrics);

            $this->sendSuccess('Historical metrics retrieved', [
                'date' => $date,
                'metrics' => $metrics,
                'stats' => $stats,
                'total_requests' => count($metrics)
            ]);
        } else {
            // Get range
            $metricsRange = $this->logger->getMetricsRange($days);
            $summary = [];

            foreach ($metricsRange as $date => $metrics) {
                $summary[$date] = $this->logger->getAggregatedStats($metrics);
            }

            $this->sendSuccess('Historical metrics retrieved', [
                'days' => $days,
                'summary' => $summary,
                'available_dates' => $this->logger->getAvailableLogDates()
            ]);
        }
    }

    /**
     * Health check endpoint
     */
    public function health(): void
    {
        $health = [
            'status' => 'healthy',
            'timestamp' => date('Y-m-d H:i:s'),
            'checks' => []
        ];

        // Check database
        $dbHealth = MonitoringMiddleware::checkDatabaseHealth();
        $health['checks']['database'] = $dbHealth;

        // Check PHP
        $health['checks']['php'] = [
            'status' => 'healthy',
            'version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit')
        ];

        // Check disk space
        $diskUsage = MonitoringMiddleware::getCurrentResourceUsage()['disk'];
        $diskPercent = (float) str_replace('%', '', $diskUsage['used_percent']);
        $health['checks']['disk'] = [
            'status' => $diskPercent > 90 ? 'warning' : 'healthy',
            'usage' => $diskUsage
        ];

        // Overall status
        foreach ($health['checks'] as $check) {
            if ($check['status'] === 'unhealthy') {
                $health['status'] = 'unhealthy';
                break;
            } elseif ($check['status'] === 'warning' && $health['status'] !== 'unhealthy') {
                $health['status'] = 'degraded';
            }
        }

        // Use sendJsonResponse for custom status code
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

        // Sort endpoints by count
        uasort($stats['endpoints'], function ($a, $b) {
            return $b['count'] - $a['count'];
        });

        $this->sendSuccess('Statistics retrieved', [
            'period' => "$days days",
            'stats' => $stats
        ]);
    }

    /**
     * Clear all monitoring logs (admin only)
     */
    public function clear(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendError('Method not allowed', 405);
            return;
        }

        $this->logger->clearAllLogs();
        $this->sendSuccess('All monitoring logs cleared');
    }

    /**
     * Check if monitoring is enabled
     */
    private function isMonitoringEnabled(): bool
    {
        $value = $_ENV['ENABLE_MONITORING'] ?? false;

        // Handle string 'true', string '1', boolean true, or integer 1
        return !empty($value) && (
            $value === 'true' ||
            $value === '1' ||
            $value === 1 ||
            $value === true
        );
    }

    /**
     * Generate unique viewer ID
     */
    private function generateViewerId(): string
    {
        return uniqid('viewer_', true);
    }

    /**
     * Cleanup inactive viewers
     */
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
     * Check if endpoint is a system route
     */
    private function isSystemRoute(string $endpoint): bool
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
                    --shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
                }

                * {
                    box-sizing: border-box;
                    margin: 0;
                    padding: 0;
                }

                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
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
                }

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

                .status-healthy {
                    background: var(--success-color);
                    color: white;
                }

                .status-warning {
                    background: var(--warning-color);
                    color: white;
                }

                .status-error {
                    background: var(--danger-color);
                    color: white;
                }

                .uptime-badge {
                    font-size: 12px;
                    color: var(--text-secondary);
                    padding: 4px 12px;
                    background: var(--bg-tertiary);
                    border-radius: 6px;
                }

                .filter-toggle {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    font-size: 14px;
                    color: var(--text-primary);
                }

                .filter-toggle input[type="checkbox"] {
                    width: 18px;
                    height: 18px;
                    cursor: pointer;
                }

                .grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
                    gap: 20px;
                    margin-bottom: 20px;
                }

                @media (min-width: 1200px) {
                    .grid {
                        grid-template-columns: repeat(3, 1fr);
                    }
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

                .card:not(:has(.requests-table)):hover {
                    cursor: pointer;
                    transform: translateY(-2px);
                    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.4);
                }

                .card h3 {
                    color: var(--accent-color);
                    margin-bottom: 15px;
                    font-size: 18px;
                    display: flex;
                    align-items: center;
                    gap: 8px;
                }

                .expand-icon {
                    margin-left: auto;
                    font-size: 20px;
                    color: var(--text-secondary);
                }

                .chart-container {
                    position: relative;
                    height: 200px;
                }

                /* Modal Styles */
                .modal {
                    display: none;
                    position: fixed;
                    z-index: 1000;
                    left: 0;
                    top: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(0, 0, 0, 0.95);
                    animation: fadeIn 0.3s ease;
                }

                @keyframes fadeIn {
                    from {
                        opacity: 0;
                    }

                    to {
                        opacity: 1;
                    }
                }

                .modal-content {
                    position: relative;
                    margin: 2% auto;
                    padding: 40px;
                    width: 90%;
                    max-width: 1400px;
                    height: 85%;
                }

                .modal-close {
                    position: absolute;
                    top: 20px;
                    right: 30px;
                    font-size: 40px;
                    font-weight: bold;
                    color: var(--text-primary);
                    cursor: pointer;
                    z-index: 1001;
                    background: var(--bg-tertiary);
                    width: 50px;
                    height: 50px;
                    border-radius: 50%;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    border: 2px solid var(--border-color);
                }

                .modal-close:hover {
                    color: var(--danger-color);
                    background: var(--bg-secondary);
                }

                .modal-chart-container {
                    height: calc(100% - 60px);
                    background: var(--bg-secondary);
                    border-radius: 12px;
                    padding: 20px;
                    border: 1px solid var(--border-color);
                }

                .modal-chart-container h2 {
                    color: var(--accent-color);
                    margin-bottom: 20px;
                    text-align: center;
                }

                /* Table Styles for Latest Requests */
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
                    font-weight: 600;
                    color: var(--accent-color);
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

                /* Make time and memory columns larger and more expressive */
                .table-time {
                    font-size: 18px;
                    font-weight: 700;
                    color: var(--accent-color);
                    font-family: 'Courier New', monospace;
                    letter-spacing: 0.5px;
                }

                .table-memory {
                    font-size: 18px;
                    font-weight: 700;
                    color: var(--warning-color);
                    font-family: 'Courier New', monospace;
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

                .method-GET {
                    background: var(--success-color);
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
                    font-family: 'Courier New', monospace;
                    font-size: 14px;
                    color: var(--text-primary);
                    max-width: 400px;
                    overflow: hidden;
                    text-overflow: ellipsis;
                    white-space: nowrap;
                }

                .table-queries {
                    color: var(--text-secondary);
                    font-size: 13px;
                }

                /* Responsive table */
                @media (max-width: 1200px) {
                    .requests-table {
                        display: block;
                        overflow-x: auto;
                        white-space: nowrap;
                    }
                }

                .loading {
                    text-align: center;
                    padding: 40px;
                    color: var(--text-secondary);
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

                .viewer-info {
                    font-size: 12px;
                    color: var(--text-secondary);
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }
            </style>
        </head>

        <body>
            <div class="container">
                <!-- Header -->
                <div class="header">
                    <div>
                        <h1>⚡ System Monitoring</h1>
                    </div>
                    <div class="header-info">
                        <div class="viewer-info">
                            <span class="refresh-indicator"></span>
                            <span>Auto-refresh: 500ms</span>
                            <span id="viewerCount"></span>
                        </div>
                        <div class="uptime-badge">
                            🕐 Uptime: <span id="uptime">Loading...</span>
                        </div>
                        <div class="filter-toggle">
                            <input type="checkbox" id="filterSystemRoutes" checked>
                            <label for="filterSystemRoutes">Include System Routes</label>
                        </div>
                        <div id="systemStatus" class="status-badge status-healthy">Healthy</div>
                    </div>
                </div>

                <!-- Live Graphs -->
                <div class="grid">
                    <div class="card" onclick="openModal('memory', 'Memory Usage (MB)')">
                        <h3>💾 Memory Usage (MB) <span class="expand-icon">⛶</span></h3>
                        <div class="chart-container">
                            <canvas id="memoryChart"></canvas>
                        </div>
                    </div>

                    <div class="card" onclick="openModal('response', 'Response Time (ms)')">
                        <h3>⚡ Response Time (ms) <span class="expand-icon">⛶</span></h3>
                        <div class="chart-container">
                            <canvas id="responseChart"></canvas>
                        </div>
                    </div>

                    <div class="card" onclick="openModal('requestRate', 'Requests Per Second')">
                        <h3>📊 Requests Per Second <span class="expand-icon">⛶</span></h3>
                        <div class="chart-container">
                            <canvas id="requestRateChart"></canvas>
                        </div>
                    </div>

                    <div class="card" onclick="openModal('dbMemory', 'Database & Memory %')">
                        <h3>🗄️ Database & Memory % <span class="expand-icon">⛶</span></h3>
                        <div class="chart-container">
                            <canvas id="dbMemoryChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Latest Requests Table -->
                <div class="card">
                    <h3>📋 Latest Requests</h3>
                    <div id="latestRequests" class="loading">Loading...</div>
                </div>
            </div>

            <!-- Modal -->
            <div id="chartModal" class="modal">
                <div class="modal-content">
                    <span class="modal-close" onclick="closeModal()">&times;</span>
                    <div class="modal-chart-container">
                        <h2 id="modalTitle"></h2>
                        <canvas id="modalChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Scripts -->
            <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
            <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6"></script>
            <script>
                let viewerId = null;
                const basePath = '<?= $basePath ? '/' . $basePath . '/' : '/' ?>';
                let memoryChart, responseChart, requestRateChart, dbMemoryChart, modalChart;
                let requestCount = 0;
                let lastUpdateTime = Date.now();
                const maxDataPoints = 120; // 60 seconds at 500ms = 120 points

                // Initialize charts
                $(document).ready(function () {
                    initializeCharts();
                    fetchMetrics();
                    setInterval(fetchMetrics, 420);

                    // Filter toggle handler
                    $('#filterSystemRoutes').change(function () {
                        fetchMetrics();
                    });
                });



                let maxMemoryLimit = 512; // Default fallback

                function getMemoryLimit() {
                    return maxMemoryLimit;
                }

                function parseMemoryLimit(limitString) {
                    // Convert to string first
                    if (typeof limitString !== 'string') {
                        limitString = String(limitString);
                    }

                    if (!limitString || limitString === '-1' || limitString === 'unlimited') {
                        return 512; // Unlimited or unknown
                    }

                    const units = {
                        'K': 1024,
                        'M': 1024 * 1024,
                        'G': 1024 * 1024 * 1024
                    };

                    const match = limitString.match(/^(\d+)([KMG])?$/i);
                    if (match) {
                        const value = parseInt(match[1]);
                        const unit = match[2] ? match[2].toUpperCase() : 'M';
                        return Math.round(value * units[unit] / (1024 * 1024)); // Convert to MB
                    }

                    // Try parsing as plain number (assume MB)
                    const numValue = parseInt(limitString);
                    if (!isNaN(numValue)) {
                        return numValue;
                    }

                    return 512; // Default fallback
                }




                function initializeCharts() {
                    const chartConfig = {
                        type: 'line',
                        data: { labels: [], datasets: [] },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: { intersect: false },
                            scales: {
                                x: {
                                    ticks: {
                                        maxRotation: 0,
                                        autoSkipPadding: 10,
                                        color: '#8b949e',
                                        callback: function (value, index) {
                                            if (index % 20 === 0) {
                                                const date = new Date(this.getLabelForValue(value));
                                                return date.toLocaleTimeString('en-US', {
                                                    hour12: false,
                                                    hour: '2-digit',
                                                    minute: '2-digit',
                                                    second: '2-digit'
                                                });
                                            }
                                            return '';
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
                                legend: { labels: { color: '#c9d1d9' } }
                            },
                            animation: { duration: 0 }
                        }
                    };

                    // Memory Chart
                    memoryChart = new Chart($('#memoryChart')[0].getContext('2d'), {
                        ...chartConfig,
                        data: {
                            labels: [],
                            datasets: [
                                {
                                    label: 'Current Memory (MB)',
                                    borderColor: '#58a6ff',
                                    backgroundColor: 'rgba(88, 166, 255, 0.1)',
                                    data: [],
                                    fill: true,
                                    tension: 0.4,
                                    pointRadius: 0
                                },
                                {
                                    label: 'Peak Memory (MB)',
                                    borderColor: '#d29922',
                                    backgroundColor: 'rgba(210, 153, 34, 0.1)',
                                    data: [],
                                    fill: true,
                                    tension: 0.4,
                                    pointRadius: 0
                                },
                                {
                                    label: 'System Route Usage (MB)',
                                    borderColor: '#f85149',
                                    backgroundColor: 'rgba(248, 81, 73, 0.1)',
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
                                x: {
                                    ticks: {
                                        maxRotation: 0,
                                        autoSkipPadding: 10,
                                        color: '#8b949e',
                                        callback: function (value, index) {
                                            if (index % 20 === 0) {
                                                const date = new Date(this.getLabelForValue(value));
                                                return date.toLocaleTimeString('en-US', {
                                                    hour12: false,
                                                    hour: '2-digit',
                                                    minute: '2-digit',
                                                    second: '2-digit'
                                                });
                                            }
                                            return '';
                                        }
                                    },
                                    grid: { color: '#30363d' }
                                },
                                y: {
                                    beginAtZero: true,
                                    max: getMemoryLimit(),
                                    grid: { color: '#30363d' },
                                    ticks: {
                                        color: '#8b949e',
                                        callback: function (value) {
                                            return value + ' MB';
                                        }
                                    }
                                }
                            },
                            plugins: {
                                legend: {
                                    display: true,
                                    labels: {
                                        color: '#c9d1d9',
                                        usePointStyle: true
                                    },
                                    onClick: function (e, legendItem, legend) {
                                        const index = legendItem.datasetIndex;
                                        const chart = legend.chart;
                                        const meta = chart.getDatasetMeta(index);

                                        // Toggle visibility
                                        meta.hidden = meta.hidden === null ? !chart.data.datasets[index].hidden : null;
                                        chart.update();
                                    }
                                }
                            }
                        }
                    });

                    // Response Time Chart
                    responseChart = new Chart($('#responseChart')[0].getContext('2d'), {
                        ...chartConfig,
                        data: {
                            labels: [],
                            datasets: [{
                                label: 'Avg Response Time (ms)',
                                borderColor: '#3fb950',
                                backgroundColor: 'rgba(63, 185, 80, 0.1)',
                                data: [],
                                fill: true,
                                tension: 0.4,
                                pointRadius: 0
                            }]
                        }
                    });

                    // Request Rate Chart
                    requestRateChart = new Chart($('#requestRateChart')[0].getContext('2d'), {
                        ...chartConfig,
                        data: {
                            labels: [],
                            datasets: [{
                                label: 'Requests/sec',
                                borderColor: '#f85149',
                                backgroundColor: 'rgba(248, 81, 73, 0.1)',
                                data: [],
                                fill: true,
                                tension: 0.4,
                                pointRadius: 0
                            }]
                        }
                    });

                    // DB & Memory % Chart
                    dbMemoryChart = new Chart($('#dbMemoryChart')[0].getContext('2d'), {
                        ...chartConfig,
                        data: {
                            labels: [],
                            datasets: [
                                {
                                    label: 'DB Queries',
                                    borderColor: '#a371f7',
                                    backgroundColor: 'rgba(163, 113, 247, 0.1)',
                                    data: [],
                                    fill: true,
                                    tension: 0.4,
                                    pointRadius: 0,
                                    yAxisID: 'y'
                                },
                                {
                                    label: 'Memory Usage %',
                                    borderColor: '#fbbf24',
                                    backgroundColor: 'rgba(251, 191, 36, 0.1)',
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
                                        callback: function (value) {
                                            return value + '%';
                                        }
                                    }
                                }
                            }
                        }
                    });
                }

                function updateChart(chart, value) {
                    const now = new Date().getTime();
                    chart.data.labels.push(now);
                    chart.data.datasets[0].data.push(value);

                    if (chart.data.labels.length > maxDataPoints) {
                        chart.data.labels.shift();
                        chart.data.datasets[0].data.shift();
                    }

                    chart.update('none');
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
                function fetchMetrics() {
                    const filterSystem = $('#filterSystemRoutes').is(':checked');
                    const endpoint = `${basePath}${basePath ? '' : '/'}monitoring/live`;
                    const url = viewerId
                        ? `${endpoint}?viewerid=${viewerId}&filtersystem=${filterSystem}`
                        : `${endpoint}?filtersystem=${filterSystem}`;

                    $.ajax({
                        url: url,
                        method: 'GET',
                        dataType: 'json',
                        success: function (result) {
                            if (result.status === 'success') {
                                const data = result.data;
                                viewerId = data.viewerid;

                                // Update memory limit on first load
                                if (data.currentresources.memory.limit) {
                                    const newLimit = parseMemoryLimit(data.currentresources.memory.limit);
                                    if (newLimit !== maxMemoryLimit) {
                                        maxMemoryLimit = newLimit;
                                        // Update chart y-axis max
                                        if (memoryChart && memoryChart.options.scales.y) {
                                            memoryChart.options.scales.y.max = maxMemoryLimit;
                                            memoryChart.update('none');
                                        }
                                    }
                                }

                                // Update memory chart with all three values
                                updateChartMulti(memoryChart, [
                                    parseFloat(data.currentresources.memory.current),
                                    parseFloat(data.currentresources.memory.peak),
                                    parseFloat(data.currentresources.memory.systemroute || 0)
                                ]);

                                // Update response time chart
                                if (data.recentstats && data.recentstats.avgresponsetime) {
                                    updateChart(responseChart, parseFloat(data.recentstats.avgresponsetime));
                                }

                                // Calculate request rate
                                const now = Date.now();
                                const timeDiff = (now - lastUpdateTime) / 1000;
                                const newRequests = data.recentstats?.totalrequests || 0;
                                const requestRate = timeDiff > 0 ? Math.round((newRequests - requestCount) / timeDiff) : 0;
                                updateChart(requestRateChart, Math.max(0, requestRate));
                                requestCount = newRequests;
                                lastUpdateTime = now;

                                // Update DB Memory chart
                                const dbQueries = data.recentstats?.avgdbqueries || 0;
                                const memoryPercent = data.currentresources.memory.percentage || 0;
                                updateChartMulti(dbMemoryChart, [dbQueries, memoryPercent]);

                                // Update latest requests
                                updateLatestRequests(data.latestrequests);

                                // Update viewer count
                                updateViewerCount(data.activeviewers, data.maxviewers);

                                // Update uptime
                                $('#uptime').text(data.currentresources.server.uptime || 'N/A');

                                // Update system status
                                $('#systemStatus')
                                    .removeClass('status-error status-warning')
                                    .addClass('status-healthy')
                                    .text('Healthy');
                            }
                        },
                        error: function (xhr, status, error) {
                            console.error('Error fetching metrics:', error);
                            $('#systemStatus')
                                .removeClass('status-healthy status-warning')
                                .addClass('status-error')
                                .text('Connection Error');
                        }
                    });
                }

                function updateLatestRequests(requests) {
                    if (!requests || requests.length === 0) {
                        $('#latestRequests').html('<div class="loading">No recent requests</div>');
                        return;
                    }

                    let html = `
                        <table class="requests-table">
                            <thead>
                                <tr>
                                    <th>Method</th>
                                    <th>Status</th>
                                    <th>Endpoint</th>
                                    <th>Time</th>
                                    <th>Response</th>
                                    <th>Memory</th>
                                    <th>Queries</th>
                                </tr>
                            </thead>
                            <tbody>
                    `;

                    $.each(requests, function (index, req) {
                        const statusClass = getStatusClass(req.status_code);
                        const time = req.timestamp.split(' ')[1]; // Extract time portion

                        html += `
                            <tr>
                                <td><span class="table-method method-${req.method}">${req.method}</span></td>
                                <td><span class="table-status ${statusClass}">${req.status_code}</span></td>
                                <td><span class="table-endpoint" title="${req.endpoint}">${req.endpoint}</span></td>
                                <td><span class="table-time">⏰ ${time}</span></td>
                                <td><span class="table-response-time">⚡ ${req.response_time}ms</span></td>
                                <td><span class="table-memory">💾 ${req.memory_used} MB</span></td>
                                <td><span class="table-queries">🗄️ ${req.db_queries || 0}</span></td>
                            </tr>
                        `;
                    });

                    html += `
                            </tbody>
                        </table>
                    `;

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

                // Modal functions
                let currentModalChart = null;

                function openModal(chartType, title) {
                    const modal = $('#chartModal');
                    $('#modalTitle').text(title);

                    // Destroy previous modal chart if exists
                    if (modalChart) {
                        modalChart.destroy();
                    }

                    // Clone the chart data
                    let sourceChart;
                    switch (chartType) {
                        case 'memory': sourceChart = memoryChart; break;
                        case 'response': sourceChart = responseChart; break;
                        case 'requestRate': sourceChart = requestRateChart; break;
                        case 'dbMemory': sourceChart = dbMemoryChart; break;
                    }

                    // Create modal chart with same data
                    modalChart = new Chart($('#modalChart')[0].getContext('2d'), {
                        type: sourceChart.config.type,
                        data: JSON.parse(JSON.stringify(sourceChart.data)),
                        options: {
                            ...sourceChart.options,
                            maintainAspectRatio: false,
                            responsive: true
                        }
                    });

                    currentModalChart = chartType;
                    modal.fadeIn(300);

                    // Continue updating modal chart
                    const updateInterval = setInterval(function () {
                        if ($('#chartModal').is(':visible') && modalChart) {
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

                function closeModal() {
                    $('#chartModal').fadeOut(300);
                    if (modalChart) {
                        modalChart.destroy();
                        modalChart = null;
                    }
                }

                // Close modal on Escape key
                $(document).keyup(function (e) {
                    if (e.key === "Escape") {
                        closeModal();
                    }
                });

                // Close modal when clicking outside
                $('#chartModal').click(function (e) {
                    if (e.target.id === 'chartModal') {
                        closeModal();
                    }
                });
            </script>
        </body>

        </html>
        <?php
    }
}
