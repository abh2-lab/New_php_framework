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

// Configure error reporting
if (!empty($_ENV['SHOWERRORS']) && ($_ENV['SHOWERRORS'] === 'true' || $_ENV['SHOWERRORS'] === '1')) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/php-error.log');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}

// ============================================
// 2. SECURITY (Global Utilities)
// ============================================
Security::initialize();
SecurityUtility::applySecurityHeaders();
SecurityUtility::handleCORS();
SecurityUtility::rateLimiting(100, 3600);

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
$basePath = $_ENV['API_BASE_PATH'] ?? '';
$router = new Router($basePath);

// Load routes
// require_once __DIR__ . '/core/SystemRoutes.php';
// require_once __DIR__ . '/routes.php';

$router->setRouteType('system');
require_once __DIR__ . '/core/SystemRoutes.php';

$router->setRouteType('app');
require_once __DIR__ . '/routes.php';


// ============================================
// 6. SET CONTENT TYPE (before dispatch)
// ============================================
if (!$isHtmlRoute && !headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}



// print_r($_ENV);
// print_r( __DIR__. 'core/Config/connection.php');



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
