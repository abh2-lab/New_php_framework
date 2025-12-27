<?php
/**
 * User/Application Routes
 * All business logic routes go here
 */

// ============================================
// AUTHENTICATION ROUTES
// ============================================
$router->add([
    'method' => 'POST',
    'url' => 'admin/login',
    'controller' => 'AdminAuthController@login',
    'desc' => 'Admin user login',
    'visible' => true,
    'group' => 'Admin Auth'
]);

$router->add([
    'method' => 'POST',
    'url' => 'admin/logout',
    'controller' => 'AdminAuthController@logout',
    'desc' => 'Logout admin user',
    'visible' => true,
    'group' => 'Admin Auth'
]);

// ============================================
// ADMIN MANAGEMENT ROUTES
// ============================================
$router->add([
    'method' => 'GET',
    'url' => 'admin/listAdminUsers',
    'controller' => 'AdminManagementController@listAdminUsers',
    'desc' => 'List all admin users',
    'visible' => true,
    'group' => 'Admin Management'
]);





$router->add([
    'method' => 'GET',
    'url' => '/',
    'controller' => 'AdminAuthController@welcome',
    'desc' => 'API welcome message with framework info',
    'visible' => true,
    'group' => ['Testing']
]);

// ... add all your other user routes here
