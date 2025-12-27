<?php
// START OUTPUT BUFFERING FIRST
ob_start('ob_gzhandler');

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Load autoloader first
require_once __DIR__ .'/../vendor/autoload.php'; // ← Fixed: two levels up

// Load environment variables from ROOT
use Dotenv\Dotenv;
use App\Core\Security;
use App\Core\Middleware\SecurityMiddleware;
use App\Core\ExceptionHandler;
use App\Core\Router;

// Load .env if file exists (works in both local and Coolify)
if (file_exists(__DIR__ . '/../../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../..');
    $dotenv->safeLoad();
    // $dotenv->load();
}

// Initialize security
Security::initialize();

// Apply security headers and CORS
SecurityMiddleware::applySecurityHeaders();
SecurityMiddleware::handleCORS();

// Rate limiting
SecurityMiddleware::rateLimiting(100, 3600);

// Register exception handler
ExceptionHandler::register();

// Configure error reporting based on environment
if (!empty($_ENV['SHOW_ERRORS']) && ($_ENV['SHOW_ERRORS'] === true || $_ENV['SHOW_ERRORS'] === '1')) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/php-error.log');
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
}

// ============================================
// CREATE ROUTER
// ============================================
// Get base path from .env, default to empty string if not set
$basePath = $_ENV['API_BASE_PATH'] ?? '';
$router = new Router($basePath);

// ============================================
// LOAD ROUTES
// ============================================
// Load system routes first
require_once __DIR__ . '/core/SystemRoutes.php';

// Load user/application routes
require_once __DIR__ . '/routes.php';


// ============================================
// DISPATCH
// ============================================
// Set JSON content type except for docs
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$isDocsRoute = strpos($requestUri, 'docs') !== false;

if (!$isDocsRoute) {
    header('Content-Type: application/json');
}



$router->dispatch();

// Handle output buffering
if ($isDocsRoute) {
    ob_end_flush();
} else {
    if (!headers_sent()) {
        ob_end_clean();
    }
}
