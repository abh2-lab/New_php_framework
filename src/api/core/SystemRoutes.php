<?php
/**
 * System Routes
 * Framework/development tools and system utilities
 */

// ============================================
// DOCUMENTATION & TESTING
// ============================================
$router->add([
    'method' => 'GET',
    'url' => '/docs',
    'controller' => 'DocsController@index',
    'desc' => 'Interactive API documentation',
    'visible' => false,
    'group' => 'System'
]);

$router->add([
    'method' => 'GET',
    'url' => '/service-test',
    'controller' => 'ServiceTesterController@index',
    'desc' => 'Service tester UI',
    'visible' => true,
    'group' => 'System'
]);

// ============================================
// DATABASE MIGRATION
// ============================================
$router->add([
    'method' => 'POST',
    'url' => '/runmigration',
    'controller' => 'InitController@migrateFromFile',
    'desc' => 'Run DB migrations',
    'visible' => true,
    'group' => 'System'
]);

// ============================================
// ENVIRONMENT MANAGEMENT
// ============================================
$router->add([
    'method' => 'GET',
    'url' => 'env/get',
    'controller' => 'DocsController@getEnvironment',
    'desc' => 'Get environment variables',
    'visible' => false,
    'group' => 'System'
]);

$router->add([
    'method' => 'POST',
    'url' => 'env/update',
    'controller' => 'DocsController@updateEnvironment',
    'desc' => 'Update environment variable',
    'visible' => false,
    'group' => 'System'
]);

$router->add([
    'method' => 'POST',
    'url' => 'env/add',
    'controller' => 'DocsController@addEnvironment',
    'desc' => 'Add new environment variable',
    'visible' => false,
    'group' => 'System'
]);



// ============================================
// MONITORING & METRICS
// ============================================
$router->add([
    'method' => 'GET',
    'url' => '/monitoring',
    'controller' => 'MonitoringController@index',
    'desc' => 'System monitoring dashboard',
    'visible' => true,
    'group' => 'Monitoring'
]);

$router->add([
    'method' => 'GET',
    'url' => '/monitoring/live',
    'controller' => 'MonitoringController@live',
    'desc' => 'Get live metrics (500ms polling)',
    'visible' => false,
    'group' => 'Monitoring'
]);

$router->add([
    'method' => 'GET',
    'url' => '/monitoring/history',
    'controller' => 'MonitoringController@history',
    'desc' => 'Get historical metrics',
    'visible' => true,
    'group' => 'Monitoring'
]);

$router->add([
    'method' => 'GET',
    'url' => '/monitoring/health',
    'controller' => 'MonitoringController@health',
    'desc' => 'System health check',
    'visible' => true,
    'group' => 'Monitoring'
]);

$router->add([
    'method' => 'GET',
    'url' => '/monitoring/stats',
    'controller' => 'MonitoringController@stats',
    'desc' => 'Endpoint statistics',
    'visible' => true,
    'group' => 'Monitoring'
]);

$router->add([
    'method' => 'POST',
    'url' => '/monitoring/clear',
    'controller' => 'MonitoringController@clear',
    'desc' => 'Clear all monitoring logs',
    'visible' => true,
    'group' => 'Monitoring'
]);
