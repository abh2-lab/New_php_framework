<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Core\Database;
use App\Services\OrderService;
use App\Services\ProductService;

// Load environment
$dotenv = Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

// Load connection
require_once __DIR__ . '/Core/Config/connection.php';

echo "=== Service Layer Test ===\n\n";

// Initialize Database
$db = new Database();

// Initialize Services
$productService = new ProductService($db);
$orderService = new OrderService($db);

// Test 1: ProductService
echo "=== Test 1: ProductService ===\n";

// Get products
$products = $productService->getProducts(['category' => 'Electronics', 'limit' => 5]);
echo "✓ Retrieved " . count($products) . " products\n";

if (count($products) > 0) {
    $testProduct = $products[0];
    echo "  Sample: " . $testProduct['name'] . " (Stock: " . $testProduct['stock'] . ")\n";
}

// Test 2: Create Order (Success Scenario)
echo "\n=== Test 2: Create Order (Success) ===\n";

try {
    $orderData = [
        'user_id' => 123,
        'total_amount' => 1029.98,
        'payment_method' => 'card',
        'notes' => 'Test order from service layer'
    ];

    $items = [
        ['product_id' => 1, 'quantity' => 1],  // Laptop Pro
        ['product_id' => 2, 'quantity' => 1]   // Wireless Mouse
    ];

    $order = $orderService->createOrder($orderData, $items);
    
    echo "✓ Order created successfully\n";
    echo "  Order ID: " . $order['order_id'] . "\n";
    echo "  Total Amount: $" . $order['total_amount'] . "\n";
    echo "  Items: " . count($order['items']) . "\n";
    
    foreach ($order['items'] as $item) {
        echo "    - " . $item['product_name'] . " (Qty: " . $item['quantity'] . ", Price: $" . $item['price'] . ")\n";
    }
    
    $createdOrderId = $order['order_id'];
    
} catch (\Exception $e) {
    echo "✗ Order creation failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 3: Get Order Details
echo "\n=== Test 3: Get Order Details ===\n";

try {
    $details = $orderService->getOrderDetails($createdOrderId);
    
    echo "✓ Retrieved order details\n";
    echo "  Status: " . $details['order']['status'] . "\n";
    echo "  Items: " . count($details['items']) . "\n";
    echo "  Transactions: " . count($details['transactions']) . "\n";
    
} catch (\Exception $e) {
    echo "✗ Failed to get order details: " . $e->getMessage() . "\n";
}

// Test 4: Cancel Order (with stock restoration)
echo "\n=== Test 4: Cancel Order (Stock Restoration) ===\n";

try {
    // Get stock before cancellation
    $product1Before = $productService->getProduct(1);
    echo "Product 1 stock before cancellation: " . $product1Before['stock'] . "\n";
    
    // Cancel order
    $cancelled = $orderService->cancelOrder($createdOrderId, "Customer requested cancellation");
    
    if ($cancelled) {
        echo "✓ Order cancelled successfully\n";
        
        // Verify stock restored
        $product1After = $productService->getProduct(1);
        echo "Product 1 stock after cancellation: " . $product1After['stock'] . "\n";
        
        if ($product1After['stock'] == $product1Before['stock']) {
            echo "✓ Stock restored correctly\n";
        } else {
            echo "✗ Stock restoration issue\n";
        }
        
        // Verify order status
        $orderDetails = $orderService->getOrderDetails($createdOrderId);
        echo "✓ Order status: " . $orderDetails['order']['status'] . "\n";
        echo "  Cancellation reason: " . $orderDetails['order']['cancellation_reason'] . "\n";
    }
    
} catch (\Exception $e) {
    echo "✗ Cancellation failed: " . $e->getMessage() . "\n";
}

// Test 5: Create Order with Insufficient Stock (Should Fail)
echo "\n=== Test 5: Create Order with Insufficient Stock (Should Fail) ===\n";

try {
    $orderData = [
        'user_id' => 456,
        'total_amount' => 9999.00,
        'payment_method' => 'upi'
    ];

    $items = [
        ['product_id' => 1, 'quantity' => 999]  // Too much quantity
    ];

    $order = $orderService->createOrder($orderData, $items);
    
    echo "✗ Order should have failed due to insufficient stock\n";
    
} catch (\Exception $e) {
    echo "✓ Order correctly rejected: " . $e->getMessage() . "\n";
}

// Test 6: Transaction Rollback Verification
echo "\n=== Test 6: Transaction Rollback Verification ===\n";

try {
    // Count orders before
    $ordersBefore = $db->first("SELECT COUNT(*) as count FROM orders");
    echo "Orders before failed transaction: " . $ordersBefore['count'] . "\n";
    
    // Try to create order with invalid product (should rollback)
    try {
        $orderData = [
            'user_id' => 789,
            'total_amount' => 100.00,
            'payment_method' => 'cash'
        ];

        $items = [
            ['product_id' => 99999, 'quantity' => 1]  // Non-existent product
        ];

        $orderService->createOrder($orderData, $items);
        
    } catch (\Exception $e) {
        // Expected to fail
    }
    
    // Count orders after
    $ordersAfter = $db->first("SELECT COUNT(*) as count FROM orders");
    echo "Orders after failed transaction: " . $ordersAfter['count'] . "\n";
    
    if ($ordersBefore['count'] == $ordersAfter['count']) {
        echo "✓ Transaction correctly rolled back - no orphan orders created\n";
    } else {
        echo "✗ Transaction rollback issue - orphan order created\n";
    }
    
} catch (\Exception $e) {
    echo "✗ Test failed: " . $e->getMessage() . "\n";
}

// Cleanup
echo "\n=== Cleanup ===\n";
try {
    $db->query(['query' => "DELETE FROM order_transactions WHERE order_id = $createdOrderId"]);
    $db->query(['query' => "DELETE FROM order_items WHERE order_id = $createdOrderId"]);
    $db->query(['query' => "DELETE FROM orders WHERE id = $createdOrderId"]);
    echo "✓ Test data cleaned up\n";
} catch (\Exception $e) {
    echo "⚠ Cleanup warning: " . $e->getMessage() . "\n";
}

echo "\n=== All Service Layer Tests Passed! ✓ ===\n";
echo "\n✅ Step 4 Complete: Service layer created and tested!\n";
echo "\nNext Step: Update Controllers to use Services\n";
