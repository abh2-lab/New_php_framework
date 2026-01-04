<?php

// Register middleware
$router->registerMiddleware('auth', \App\Core\Middlewares\AuthMiddleware::class);
$router->registerMiddleware('admin', \App\Core\Middlewares\AdminMiddleware::class);
$router->registerMiddleware('log', \App\Core\Middlewares\LogMiddleware::class);

// Public routes (no middleware)
$router->add([
    'method' => 'GET',
    'url' => '/',
    'controller' => 'TestController@welcome',
    'desc' => 'API welcome message',
    'visible' => true,
    'group' => 'Testing'
]);


// Register test middleware
$router->registerMiddleware('test', \App\Core\Middlewares\TestMiddleware::class);
$router->registerMiddleware('blocking', \App\Core\Middlewares\BlockingMiddleware::class);

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
        function () {
            error_log("📦 Group before() filter executed");
        }
    ],
    'after' => [
        function ($response) {
            error_log("📦 Group after() filter executed");
            return $response;
        }
    ]
], function ($router) {

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
], function ($router) {

    // Inner group adds another middleware
    $router->group([
        'prefix' => '/inner',
        'before' => [
            function () {
                error_log("🔥 Inner group before() executed");
            }
        ]
    ], function ($router) {

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








// ============================================================================
// ORDER MANAGEMENT ROUTES
// ============================================================================
$router->add([
    'method' => 'POST',
    'url' => '/orders/create',
    'controller' => 'OrderController@create',
    'desc' => 'Create a new order with items',
    'visible' => true,
    'group' => 'Orders',
    'params' => [
        'json' => [
            'user_id' => 'User ID (integer)',
            'payment_method' => 'Payment method (card/cash/upi)',
            'items' => 'Array of items with product_id and quantity',
            'notes' => 'Optional order notes'
        ]
    ]
]);

$router->add([
    'method' => 'GET',
    'url' => '/orders/details',
    'controller' => 'OrderController@details',
    'desc' => 'Get order details by ID',
    'visible' => true,
    'group' => 'Orders',
    'params' => [
        'get' => [
            'order_id' => 'Order ID to retrieve'
        ]
    ]
]);

$router->add([
    'method' => 'POST',
    'url' => '/orders/cancel',
    'controller' => 'OrderController@cancel',
    'desc' => 'Cancel an order and restore stock',
    'visible' => true,
    'group' => 'Orders',
    'params' => [
        'json' => [
            'order_id' => 'Order ID to cancel',
            'reason' => 'Cancellation reason'
        ]
    ]
]);

// ============================================================================
// PRODUCT MANAGEMENT ROUTES
// ============================================================================
$router->add([
    'method' => 'GET',
    'url' => '/products/list',
    'controller' => 'ProductController@list',
    'desc' => 'Get list of products with filters',
    'visible' => true,
    'group' => 'Products',
    'params' => [
        'get' => [
            'category' => 'Optional: Filter by category',
            'limit' => 'Optional: Number of products (default: 10)'
        ]
    ]
]);

$router->add([
    'method' => 'GET',
    'url' => 'products/details',
    'controller' => 'ProductController@details',
    'desc' => 'Get product details by ID',
    'visible' => true,
    'group' => 'Products',
    'params' => [
        'get' => [
            'product_id' => 'Product ID to retrieve'
        ]
    ]
]);

$router->add([
    'method' => 'POST',
    'url' => '/products/create',
    'controller' => 'ProductController@create',
    'desc' => 'Create a new product',
    'visible' => true,
    'group' => 'Products',
    'params' => [
        'json' => [
            'name' => 'Product name',
            'sku' => 'Product SKU (unique)',
            'price' => 'Product price',
            'stock' => 'Stock quantity',
            'category' => 'Product category'
        ]
    ]
]);

$router->add([
    'method' => 'POST',
    'url' => '/products/update',
    'controller' => 'ProductController@update',
    'desc' => 'Update product details',
    'visible' => true,
    'group' => 'Products',
    'params' => [
        'json' => [
            'product_id' => 'Product ID to update',
            'name' => 'Optional: New product name',
            'price' => 'Optional: New price',
            'stock' => 'Optional: New stock quantity'
        ]
    ]
]);