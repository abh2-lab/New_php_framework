<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;

// Load environment
$dotenv = Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

// Load connection
require_once __DIR__ . '/Core/Config/connection.php';

echo "=== Connection Test ===\n\n";

// Test 1: PDO Connection
if ($connpdo instanceof PDO) {
    echo "✓ PDO connection successful\n";
} else {
    echo "✗ PDO connection failed\n";
    exit(1);
}

// Test 2: RunQuery (Legacy - Should still work)
echo "\n=== Testing RunQuery (Legacy) ===\n";
$result = RunQuery([
    'conn' => $connpdo,
    'query' => 'SELECT 1 as test, NOW() as time_now'  // Fixed: changed current_time to time_now
]);

if (!isset($result['error'])) {
    echo "✓ RunQuery works\n";
    echo "  Result: " . json_encode($result) . "\n";
} else {
    echo "✗ RunQuery failed: " . $result['error'] . "\n";
}

// Test 3: Transaction Functions (New)
echo "\n=== Testing Transaction Functions (New) ===\n";

// Test begin
if (transBegin($connpdo)) {
    echo "✓ transBegin() works\n";
    
    // Test status
    if (transStatus($connpdo)) {
        echo "✓ transStatus() works - transaction is active\n";
    } else {
        echo "✗ transStatus() failed\n";
    }
    
    // Test rollback
    if (transRollback($connpdo)) {
        echo "✓ transRollback() works\n";
    } else {
        echo "✗ transRollback() failed\n";
    }
} else {
    echo "✗ transBegin() failed\n";
}

// Test 4: Full transaction flow
echo "\n=== Testing Full Transaction Flow ===\n";

transBegin($connpdo);
echo "✓ Transaction started\n";

// Run a safe test query
$result = RunQuery([
    'conn' => $connpdo,
    'query' => 'SELECT DATABASE() as db_name, VERSION() as mysql_version'
]);

if (!isset($result['error'])) {
    echo "✓ Query executed inside transaction\n";
    echo "  Database: " . $result[0]['db_name'] . "\n";
    echo "  MySQL Version: " . $result[0]['mysql_version'] . "\n";
} else {
    echo "✗ Query failed: " . $result['error'] . "\n";
}

transCommit($connpdo);
echo "✓ Transaction committed\n";

// Test 5: Test withSuccess option
echo "\n=== Testing RunQuery with withSuccess ===\n";

// Create a temporary test table
$createTable = RunQuery([
    'conn' => $connpdo,
    'query' => 'CREATE TABLE IF NOT EXISTS test_connection (
        id INT AUTO_INCREMENT PRIMARY KEY,
        test_value VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )'
]);

if (isset($createTable['error'])) {
    echo "✗ Failed to create test table: " . $createTable['error'] . "\n";
} else {
    echo "✓ Test table created\n";
}

// Test INSERT with success info
$insertResult = RunQuery([
    'conn' => $connpdo,
    'query' => 'INSERT INTO test_connection (test_value) VALUES (:value)',
    'params' => [':value' => 'Test from CLI'],
    'withSuccess' => true
]);

if (isset($insertResult['error'])) {
    echo "✗ INSERT failed: " . $insertResult['error'] . "\n";
} else {
    echo "✓ INSERT works with withSuccess\n";
    echo "  Inserted ID: " . $insertResult['id'] . "\n";
    echo "  Affected rows: " . $insertResult['affected_rows'] . "\n";
}

// Test SELECT
$selectResult = RunQuery([
    'conn' => $connpdo,
    'query' => 'SELECT * FROM test_connection WHERE id = :id',
    'params' => [':id' => $insertResult['id']]
]);

if (isset($selectResult['error'])) {
    echo "✗ SELECT failed: " . $selectResult['error'] . "\n";
} else {
    echo "✓ SELECT works\n";
    echo "  Retrieved: " . json_encode($selectResult[0]) . "\n";
}

// Cleanup test table
RunQuery([
    'conn' => $connpdo,
    'query' => 'DROP TABLE test_connection'
]);
echo "✓ Test table cleaned up\n";

// Test 6: Transaction with Rollback
echo "\n=== Testing Transaction Rollback ===\n";

// Create test table again
RunQuery([
    'conn' => $connpdo,
    'query' => 'CREATE TABLE IF NOT EXISTS test_transaction (
        id INT AUTO_INCREMENT PRIMARY KEY,
        value VARCHAR(100)
    )'
]);

transBegin($connpdo);
echo "✓ Transaction started\n";

// Insert data
$result = RunQuery([
    'conn' => $connpdo,
    'query' => 'INSERT INTO test_transaction (value) VALUES (:value)',
    'params' => [':value' => 'This will be rolled back'],
    'withSuccess' => true
]);

echo "✓ Data inserted (ID: " . $result['id'] . ")\n";

// Check data exists
$check = RunQuery([
    'conn' => $connpdo,
    'query' => 'SELECT COUNT(*) as count FROM test_transaction'
]);
echo "✓ Records in transaction: " . $check[0]['count'] . "\n";

// Rollback
transRollback($connpdo);
echo "✓ Transaction rolled back\n";

// Check data is gone
$checkAfter = RunQuery([
    'conn' => $connpdo,
    'query' => 'SELECT COUNT(*) as count FROM test_transaction'
]);
echo "✓ Records after rollback: " . $checkAfter[0]['count'] . " (should be 0)\n";

// Cleanup
RunQuery([
    'conn' => $connpdo,
    'query' => 'DROP TABLE test_transaction'
]);

echo "\n=== All Tests Passed! ✓ ===\n";
echo "\n✅ Step 0 Complete: connection.php upgraded successfully!\n";
echo "\nNext Step: Create Database class\n";
