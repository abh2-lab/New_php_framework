<?php

/**
 * Application Entry Point
 * Clean bootstrap with proper output buffering for middleware support
 */

// ============================================
// 1. BOOTSTRAP
// ============================================

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Core\Security;
use App\Core\Utilities\SecurityUtility;
use App\Core\Router;
use App\Core\ExceptionHandler;
use App\Core\Middlewares\MonitoringMiddleware;

// Load environment
if (file_exists(__DIR__ . '/../../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../..');
    $dotenv->safeLoad();
}

// Configure error visibility only
$showErrors = !empty($_ENV['SHOW_ERRORS']) && ($_ENV['SHOW_ERRORS'] === 'true' || $_ENV['SHOW_ERRORS'] === '1');

// Keep all PHP errors active so ExceptionHandler::handleError() can catch them
error_reporting(E_ALL);
ini_set('display_errors', $showErrors ? '1' : '0');
ini_set('display_startup_errors', $showErrors ? '1' : '0');

// Disable native PHP file logging here.
// Centralized logging should be handled by ExceptionHandler / Logger.
ini_set('log_errors', '1');

// SETTING DEFAULT TIMEZONE
date_default_timezone_set($_ENV['DB_TIMEZONE'] ?? 'Asia/Kolkata');

// ============================================
// 2. SECURITY (Global Utilities)
// ============================================

Security::initialize();
SecurityUtility::applySecurityHeaders();
SecurityUtility::handleCORS();

// FIX: read rate limit values from .env keys that exist in your .env file
SecurityUtility::rateLimiting(
    (int)($_ENV['RATE_LIMIT_REQUESTS'] ?? 100),
    (int)($_ENV['RATE_LIMIT_WINDOW'] ?? 3600)
);

// Initialize monitoring (if enabled)
MonitoringMiddleware::start();

// Register exception handler
ExceptionHandler::register();

// ============================================
// 3. DETECT RESPONSE TYPE
// ============================================

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$isHtmlRoute = (
    strpos($requestUri, '/docs') !== false ||
    strpos($requestUri, '/service-test') !== false ||
    strpos($requestUri, '/monitoring') !== false
);

// ============================================
// 4. START OUTPUT BUFFERING
// ============================================

// Use basic buffering - let middleware and controllers control headers
ob_start();

// ============================================
// 5. ROUTER SETUP
// ============================================

$basePath = '/api';
$router = new Router($basePath);

// Load routes
$router->setRouteType('system');
require_once __DIR__ . '/core/SystemRoutes.php';

$router->setRouteType('app');
require_once __DIR__ . '/routes.php';

// ============================================
// 6. SET CONTENT TYPE (before dispatch)
// ============================================

// pp($_ENV);

if (!$isHtmlRoute && !headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

// ============================================
// 7. DISPATCH REQUEST (middleware executes here)
// ============================================

$router->dispatch();

// ============================================
// 8. FLUSH OUTPUT
// ============================================

// Always flush - never clean (middleware needs output preserved)
if (ob_get_level() > 0) {
    ob_end_flush();
}
