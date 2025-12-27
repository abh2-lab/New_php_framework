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
    'url' => 'docs',
    'controller' => 'DocsController@index',
    'desc' => 'Interactive API documentation',
    'visible' => false,
    'group' => 'System'
]);

$router->add([
    'method' => 'GET',
    'url' => 'service-test',
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
    'url' => 'runmigration',
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
