<?php

// Register middleware
$router->registerMiddleware('auth', \App\Core\Middleware\AuthMiddleware::class);
$router->registerMiddleware('admin', \App\Core\Middleware\AdminMiddleware::class);
$router->registerMiddleware('log', \App\Core\Middleware\LogMiddleware::class);

// Public routes (no middleware)
$router->add([
    'method' => 'GET',
    'url' => '/',
    'controller' => 'AuthController@welcome',
    'desc' => 'API welcome message',
    'visible' => true,
    'group' => 'Testing'
]);

// Single route with middleware
$router->add([
    'method' => 'POST',
    'url' => '/admin/login',
    'controller' => 'AdminAuthController@login',
    'desc' => 'Admin user login',
    'middleware' => ['log'],  // Apply log middleware
    'visible' => true,
    'group' => 'Admin Auth'
]);

// Admin routes group with shared middleware
$router->group([
    'prefix' => '/admin',
    'middleware' => ['auth', 'admin', 'log'],  // All routes get these
    'before' => [
        function () {
            // Additional custom check
            if (($_SERVER['HTTP_X_API_VERSION'] ?? '') !== '1.0') {
                http_response_code(400);
                echo json_encode(['error' => 'API version mismatch']);
                exit;
            }
        }
    ],
    'after' => [
        function ($response) {
            error_log('Admin route completed');
            return $response;
        }
    ]
], function ($router) {

    $router->add([
        'method' => 'GET',
        'url' => '/listAdminUsers',
        'controller' => 'AdminManagementController@listAdminUsers',
        'desc' => 'List all admin users',
        'visible' => true,
        'group' => 'Admin Management'
    ]);

    $router->add([
        'method' => 'POST',
        'url' => '/createAdminUser',
        'controller' => 'AdminManagementController@createAdminUser',
        'desc' => 'Create new admin user',
        'visible' => true,
        'group' => 'Admin Management'
    ]);

});

// Nested groups example
$router->group(['prefix' => '/api', 'middleware' => ['log']], function ($router) {

    // API v1 - requires authentication
    $router->group(['prefix' => '/v1', 'middleware' => ['auth']], function ($router) {

        $router->add([
            'method' => 'GET',
            'url' => '/profile',
            'controller' => 'UserController@profile',
            'desc' => 'Get user profile',
            'group' => 'User'
        ]);

        $router->add([
            'method' => 'PUT',
            'url' => '/profile',
            'controller' => 'UserController@updateProfile',
            'desc' => 'Update user profile',
            'group' => 'User'
        ]);

    });

});





// Register test middleware
$router->registerMiddleware('test', \App\Core\Middleware\TestMiddleware::class);
$router->registerMiddleware('blocking', \App\Core\Middleware\BlockingMiddleware::class);

// TEST 1: Route WITHOUT middleware (baseline)
$router->add([
    'method' => 'GET',
    'url' => '/test/no-middleware',
    'controller' => 'TestController@testRoute',
    'desc' => 'Test route without middleware',
    'visible' => true,
    'group' => 'Middleware Tests'
]);

// TEST 2: Route WITH test middleware
$router->add([
    'method' => 'GET',
    'url' => '/test/with-middleware',
    'controller' => 'TestController@testRoute',
    'middleware' => ['test'],
    'desc' => 'Test route with TestMiddleware',
    'visible' => true,
    'group' => 'Middleware Tests'
]);

// TEST 3: Route with BLOCKING middleware
$router->add([
    'method' => 'GET',
    'url' => '/test/blocked',
    'controller' => 'TestController@blockedRoute',
    'middleware' => ['blocking'],
    'desc' => 'This should be blocked by middleware',
    'visible' => true,
    'group' => 'Middleware Tests'
]);

// TEST 4: Group with shared middleware
$router->group([
    'prefix' => '/test/group',
    'middleware' => ['test'],
    'before' => [
        function() {
            error_log("📦 Group before() filter executed");
        }
    ],
    'after' => [
        function($response) {
            error_log("📦 Group after() filter executed");
            return $response;
        }
    ]
], function($router) {
    
    $router->add([
        'method' => 'GET',
        'url' => '/route1',
        'controller' => 'TestController@groupedRoute',
        'desc' => 'Route 1 in group',
        'visible' => true,
        'group' => 'Middleware Tests'
    ]);
    
    $router->add([
        'method' => 'GET',
        'url' => '/route2',
        'controller' => 'TestController@groupedRoute',
        'desc' => 'Route 2 in group',
        'visible' => true,
        'group' => 'Middleware Tests'
    ]);
    
});

// TEST 5: Nested groups
$router->group([
    'prefix' => '/test/nested',
    'middleware' => ['test']
], function($router) {
    
    // Inner group adds another middleware
    $router->group([
        'prefix' => '/inner',
        'before' => [
            function() {
                error_log("🔥 Inner group before() executed");
            }
        ]
    ], function($router) {
        
        $router->add([
            'method' => 'GET',
            'url' => '/deep',
            'controller' => 'TestController@nestedRoute',
            'desc' => 'Nested group route',
            'visible' => true,
            'group' => 'Middleware Tests'
        ]);
        
    });
    
});
