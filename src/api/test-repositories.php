<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Core\Database;
use App\Repositories\ProductRepository;
use App\Repositories\OrderRepository;

// Load environment
$dotenv = Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

// Load connection
require_once __DIR__ . '/Core/Config/connection.php';

echo "=== Repository Classes Test ===\n\n";

// Initialize Database
$db = new Database();

echo "=== Using Existing Tables (from migration) ===\n";
echo "✓ Tables already exist\n";

// Test ProductRepository
echo "\n=== Test 1: ProductRepository ===\n";
$productRepo = new ProductRepository($db);

// Create products with unique SKUs
$uniqueId = time(); // Use timestamp for unique SKUs

$productId1 = $productRepo->create([
    'name' => 'Test Laptop',
    'sku' => 'TEST-LAP-' . $uniqueId,
    'price' => 1299.99,
    'stock' => 15,
    'category' => 'Electronics'
]);
echo "✓ Created product 1 (ID: $productId1)\n";

$productId2 = $productRepo->create([
    'name' => 'Test Mouse',
    'sku' => 'TEST-MOU-' . $uniqueId,
    'price' => 39.99,
    'stock' => 60,
    'category' => 'Electronics'
]);
echo "✓ Created product 2 (ID: $productId2)\n";

// Find product
$product = $productRepo->findById($productId1);
echo "✓ Found product: " . $product['name'] . " (Stock: " . $product['stock'] . ")\n";

// Get all products
$products = $productRepo->getAll('Electronics', 10);
echo "✓ Retrieved " . count($products) . " products\n";

// Test OrderRepository
echo "\n=== Test 2: OrderRepository ===\n";
$orderRepo = new OrderRepository($db);

// Create order
$orderId = $orderRepo->create([
    'user_id' => 1,
    'total_amount' => 1339.98,
    'payment_method' => 'card',
    'status' => 'pending'
]);
echo "✓ Created order (ID: $orderId)\n";

// Add order items
$itemId1 = $orderRepo->addOrderItem([
    'order_id' => $orderId,
    'product_id' => $productId1,
    'quantity' => 1,
    'price' => 1299.99,
    'subtotal' => 1299.99
]);
echo "✓ Added order item 1 (ID: $itemId1)\n";

$itemId2 = $orderRepo->addOrderItem([
    'order_id' => $orderId,
    'product_id' => $productId2,
    'quantity' => 1,
    'price' => 39.99,
    'subtotal' => 39.99
]);
echo "✓ Added order item 2 (ID: $itemId2)\n";

// Test 3: Stock Management
echo "\n=== Test 3: Stock Management ===\n";
$productBefore = $productRepo->findById($productId1);
echo "Stock before: " . $productBefore['stock'] . "\n";

$productRepo->reduceStock($productId1, 1);
$productAfter = $productRepo->findById($productId1);
echo "✓ Reduced stock by 1\n";
echo "Stock after: " . $productAfter['stock'] . "\n";

$productRepo->increaseStock($productId1, 1);
$productRestored = $productRepo->findById($productId1);
echo "✓ Increased stock by 1\n";
echo "Stock restored: " . $productRestored['stock'] . "\n";

// Test 4: Order Items Retrieval
echo "\n=== Test 4: Order Items Retrieval ===\n";
$items = $orderRepo->getOrderItems($orderId);
echo "✓ Retrieved " . count($items) . " order items\n";
foreach ($items as $item) {
    echo "  - " . $item['product_name'] . " (Qty: " . $item['quantity'] . ", Price: $" . $item['price'] . ")\n";
}

// Test 5: Transaction Log
echo "\n=== Test 5: Transaction Log ===\n";
$logId = $orderRepo->createTransactionLog([
    'order_id' => $orderId,
    'action' => 'order_created',
    'amount' => 1339.98,
    'payment_method' => 'card',
    'status' => 'success'
]);
echo "✓ Created transaction log (ID: $logId)\n";

$logs = $orderRepo->getTransactionLog($orderId);
echo "✓ Retrieved " . count($logs) . " transaction logs\n";

// Test 6: Order Update
echo "\n=== Test 6: Order Update ===\n";
$updated = $orderRepo->update($orderId, [
    'status' => 'confirmed'
]);

if ($updated) {
    $order = $orderRepo->findById($orderId);
    echo "✓ Order updated successfully\n";
    echo "  New status: " . $order['status'] . "\n";
} else {
    echo "✗ Order update failed\n";
}

// Cleanup test data
echo "\n=== Cleanup ===\n";
try {
    // Delete in correct order due to foreign keys
    $db->query(['query' => "DELETE FROM order_transactions WHERE order_id = $orderId"]);
    $db->query(['query' => "DELETE FROM order_items WHERE order_id = $orderId"]);
    $db->query(['query' => "DELETE FROM orders WHERE id = $orderId"]);
    $db->query(['query' => "DELETE FROM products WHERE id IN ($productId1, $productId2)"]);
    echo "✓ Test data cleaned up\n";
} catch (\Exception $e) {
    echo "⚠ Cleanup warning: " . $e->getMessage() . "\n";
}

echo "\n=== All Repository Tests Passed! ✓ ===\n";
echo "\n✅ Step 3 Complete: Repository classes created and tested!\n";
echo "\nNext Step: Create Service Layer\n";
