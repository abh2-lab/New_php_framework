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

        // DEBUG: Check what we're getting
        error_log("ENABLE_MONITORING from ENV: " . ($_ENV['ENABLE_MONITORING'] ?? 'NOT SET'));
        error_log("Type: " . gettype($_ENV['ENABLE_MONITORING'] ?? null));

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
    public function live(): void
    {
        // Check viewer limit
        $this->cleanupInactiveViewers();

        $viewerId = $_GET['viewer_id'] ?? null;
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
        $latestRequests = array_slice(array_reverse($todayMetrics), 0, 10);

        // Calculate recent stats (last 100 requests)
        $recentMetrics = array_slice(array_reverse($todayMetrics), 0, 100);
        $recentStats = $this->logger->getAggregatedStats($recentMetrics);

        $this->sendSuccess('Live metrics retrieved', [
            'viewer_id' => $viewerId,
            'active_viewers' => count(self::$activeViewers),
            'max_viewers' => $maxViewers,
            'current_resources' => $metrics,
            'latest_requests' => $latestRequests,
            'recent_stats' => $recentStats,
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
            // Get specific date
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
        sendJsonResponse($statusCode, 'success', 'Health check completed', $health);
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
            'period' => "{$days} day(s)",
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
        return !empty($_ENV['ENABLE_MONITORING']) &&
            ($_ENV['ENABLE_MONITORING'] === 'true' || $_ENV['ENABLE_MONITORING'] === '1');
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
     * Render monitoring dashboard HTML with Chart.js (Manual Scrolling)
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
                    max-width: 1400px;
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
                }

                .header h1 {
                    font-size: 28px;
                    color: var(--accent-color);
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

                .grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                    gap: 20px;
                    margin-bottom: 20px;
                }

                .card {
                    background: var(--bg-secondary);
                    border: 1px solid var(--border-color);
                    border-radius: 12px;
                    padding: 20px;
                    box-shadow: var(--shadow);
                }

                .card h3 {
                    color: var(--accent-color);
                    margin-bottom: 15px;
                    font-size: 18px;
                }

                .chart-container {
                    position: relative;
                    height: 200px;
                }

                .requests-table {
                    width: 100%;
                    border-collapse: collapse;
                    font-size: 13px;
                }

                .requests-table th {
                    background: var(--bg-tertiary);
                    padding: 10px;
                    text-align: left;
                    color: var(--text-secondary);
                    font-weight: 600;
                }

                .requests-table td {
                    padding: 10px;
                    border-bottom: 1px solid var(--border-color);
                }

                .method {
                    display: inline-block;
                    padding: 2px 8px;
                    border-radius: 4px;
                    font-weight: 600;
                    font-size: 11px;
                }

                .method-GET {
                    background: #3fb950;
                    color: white;
                }

                .method-POST {
                    background: #58a6ff;
                    color: white;
                }

                .method-PUT {
                    background: #d29922;
                    color: white;
                }

                .method-DELETE {
                    background: #f85149;
                    color: white;
                }

                .status-2xx {
                    color: var(--success-color);
                }

                .status-4xx {
                    color: var(--warning-color);
                }

                .status-5xx {
                    color: var(--danger-color);
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
                <div class="header">
                    <div>
                        <h1>🔍 System Monitoring</h1>
                        <div class="viewer-info">
                            <span class="refresh-indicator"></span>
                            <span>Auto-refresh: 500ms</span>
                            <span id="viewerCount"></span>
                        </div>
                    </div>
                    <div id="systemStatus" class="status-badge status-healthy">Healthy</div>
                </div>

                <!-- Live Graphs -->
                <div class="grid">
                    <div class="card">
                        <h3>💾 Memory Usage (MB)</h3>
                        <div class="chart-container">
                            <canvas id="memoryChart"></canvas>
                        </div>
                    </div>

                    <div class="card">
                        <h3>⚡ Response Time (ms)</h3>
                        <div class="chart-container">
                            <canvas id="responseChart"></canvas>
                        </div>
                    </div>

                    <div class="card">
                        <h3>📊 Requests Per Second</h3>
                        <div class="chart-container">
                            <canvas id="requestRateChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Request Table -->
                <div class="card">
                    <h3>🔄 Latest Requests</h3>
                    <div id="latestRequests" class="loading">Loading...</div>
                </div>
            </div>

            <!-- Scripts -->
            <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
            <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6"></script>

            <script>
                let viewerId = null;
                const basePath = '<?= $basePath ?>';
                let memoryChart, responseChart, requestRateChart;
                let requestCount = 0;
                let lastUpdateTime = Date.now();
                const maxDataPoints = 120; // 60 seconds at 500ms = 120 points

                // Initialize charts
                $(document).ready(function () {
                    initializeCharts();
                    fetchMetrics();
                    setInterval(fetchMetrics, 500);
                });

                function initializeCharts() {
                    const chartConfig = {
                        type: 'line',
                        data: {
                            labels: [],
                            datasets: []
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: {
                                intersect: false
                            },
                            scales: {
                                x: {
                                    ticks: {
                                        maxRotation: 0,
                                        autoSkipPadding: 10,
                                        color: '#8b949e',
                                        callback: function (value, index) {
                                            // Show only every 20th label (10 seconds)
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
                                legend: {
                                    labels: { color: '#c9d1d9' }
                                }
                            },
                            animation: {
                                duration: 0
                            }
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
                                }
                            ]
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
                }

                function updateChart(chart, value) {
                    const now = new Date().getTime();

                    // Add new data point
                    chart.data.labels.push(now);
                    chart.data.datasets[0].data.push(value);

                    // Remove old data points (keep last 120 = 60 seconds)
                    if (chart.data.labels.length > maxDataPoints) {
                        chart.data.labels.shift();
                        chart.data.datasets[0].data.shift();
                    }

                    chart.update('none');
                }

                function updateChartMulti(chart, values) {
                    const now = new Date().getTime();

                    // Add new data points
                    chart.data.labels.push(now);
                    values.forEach((value, index) => {
                        chart.data.datasets[index].data.push(value);
                    });

                    // Remove old data points
                    if (chart.data.labels.length > maxDataPoints) {
                        chart.data.labels.shift();
                        chart.data.datasets.forEach(dataset => {
                            dataset.data.shift();
                        });
                    }

                    chart.update('none');
                }

                function formatBytes(bytes) {
                    return bytes + ' MB';
                }

                function formatDuration(ms) {
                    return ms + ' ms';
                }

                function getStatusClass(code) {
                    if (code >= 200 && code < 300) return 'status-2xx';
                    if (code >= 400 && code < 500) return 'status-4xx';
                    return 'status-5xx';
                }

                function fetchMetrics() {
                    const endpoint = basePath ? `/api/monitoring/live` : '/monitoring/live';
                    const url = viewerId ? `${endpoint}?viewer_id=${viewerId}` : endpoint;

                    $.ajax({
                        url: url,
                        method: 'GET',
                        dataType: 'json',
                        success: function (result) {
                            if (result.status === 'success') {
                                const data = result.data;
                                viewerId = data.viewer_id;

                                // Update memory chart
                                updateChartMulti(memoryChart, [
                                    parseFloat(data.current_resources.memory.current),
                                    parseFloat(data.current_resources.memory.peak)
                                ]);

                                // Update response time chart
                                if (data.recent_stats && data.recent_stats.avg_response_time) {
                                    updateChart(responseChart, parseFloat(data.recent_stats.avg_response_time));
                                }

                                // Calculate request rate
                                const now = Date.now();
                                const timeDiff = (now - lastUpdateTime) / 1000;
                                const newRequests = data.recent_stats?.total_requests || 0;
                                const requestRate = timeDiff > 0
                                    ? Math.round((newRequests - requestCount) / timeDiff)
                                    : 0;

                                updateChart(requestRateChart, Math.max(0, requestRate));

                                requestCount = newRequests;
                                lastUpdateTime = now;

                                updateLatestRequests(data.latest_requests);
                                updateViewerCount(data.active_viewers, data.max_viewers);

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

                    let html = '<table class="requests-table"><thead><tr><th>Time</th><th>Method</th><th>Endpoint</th><th>Duration</th><th>Memory</th><th>Status</th></tr></thead><tbody>';

                    $.each(requests, function (index, req) {
                        html += `
                <tr>
                    <td>${req.timestamp.split(' ')[1]}</td>
                    <td><span class="method method-${req.method}">${req.method}</span></td>
                    <td>${req.endpoint}</td>
                    <td>${formatDuration(req.response_time)}</td>
                    <td>${formatBytes(req.memory_used)}</td>
                    <td class="${getStatusClass(req.status_code)}">${req.status_code}</td>
                </tr>
            `;
                    });

                    html += '</tbody></table>';
                    $('#latestRequests').html(html);
                }

                function updateViewerCount(active, max) {
                    $('#viewerCount').text(`Viewers: ${active}/${max}`);
                }
            </script>
        </body>

        </html>
        <?php
    }


}
