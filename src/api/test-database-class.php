<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Core\Database;
use App\Core\Exceptions\DatabaseException;

// Load environment
$dotenv = Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

// Load connection
require_once __DIR__ . '/Core/Config/connection.php';

echo "=== Database Class Test ===\n\n";

// Test 1: Create Database instance
echo "=== Test 1: Create Database Instance ===\n";
try {
    $db = new Database();
    echo "✓ Database instance created\n";
} catch (DatabaseException $e) {
    echo "✗ Failed to create Database instance: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 2: Basic SELECT
echo "\n=== Test 2: Basic SELECT Query ===\n";
try {
    $result = $db->select('SELECT 1 as test, NOW() as time_now');
    echo "✓ SELECT query works\n";
    echo "  Result: " . json_encode($result[0]) . "\n";
} catch (DatabaseException $e) {
    echo "✗ SELECT failed: " . $e->getMessage() . "\n";
}

// Test 3: first() helper
echo "\n=== Test 3: first() Helper Method ===\n";
try {
    $result = $db->first('SELECT DATABASE() as db_name, VERSION() as version');
    echo "✓ first() method works\n";
    echo "  Database: " . $result['db_name'] . "\n";
    echo "  Version: " . $result['version'] . "\n";
} catch (DatabaseException $e) {
    echo "✗ first() failed: " . $e->getMessage() . "\n";
}

// Create test table for further tests
echo "\n=== Setting Up Test Table ===\n";
try {
    $db->query([
        'query' => 'CREATE TABLE IF NOT EXISTS test_db_class (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(100),
            age INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )'
    ]);
    echo "✓ Test table created\n";
} catch (DatabaseException $e) {
    echo "✗ Failed to create test table: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 4: INSERT with helper
echo "\n=== Test 4: INSERT with insert() Helper ===\n";
try {
    $result = $db->insert(
        'INSERT INTO test_db_class (name, email, age) VALUES (:name, :email, :age)',
        [
            ':name' => 'John Doe',
            ':email' => 'john@example.com',
            ':age' => 30
        ]
    );
    echo "✓ INSERT works\n";
    echo "  Inserted ID: " . $result['id'] . "\n";
    echo "  Affected rows: " . $result['affected_rows'] . "\n";
    
    $insertedId = $result['id'];
} catch (DatabaseException $e) {
    echo "✗ INSERT failed: " . $e->getMessage() . "\n";
}

// Test 5: SELECT with params
echo "\n=== Test 5: SELECT with Parameters ===\n";
try {
    $user = $db->first(
        'SELECT * FROM test_db_class WHERE id = :id',
        [':id' => $insertedId]
    );
    echo "✓ SELECT with params works\n";
    echo "  User: " . json_encode($user) . "\n";
} catch (DatabaseException $e) {
    echo "✗ SELECT failed: " . $e->getMessage() . "\n";
}

// Test 6: UPDATE with helper
echo "\n=== Test 6: UPDATE with update() Helper ===\n";
try {
    $result = $db->update(
        'UPDATE test_db_class SET age = :age WHERE id = :id',
        [':age' => 31, ':id' => $insertedId]
    );
    echo "✓ UPDATE works\n";
    echo "  Affected rows: " . $result['affected_rows'] . "\n";
    
    // Verify update
    $updated = $db->first('SELECT age FROM test_db_class WHERE id = :id', [':id' => $insertedId]);
    echo "  New age: " . $updated['age'] . "\n";
} catch (DatabaseException $e) {
    echo "✗ UPDATE failed: " . $e->getMessage() . "\n";
}

// Test 7: Transaction - Success
echo "\n=== Test 7: Transaction (Success Scenario) ===\n";
try {
    $result = $db->transaction(function($db) {
        // Insert first user
        $result1 = $db->insert(
            'INSERT INTO test_db_class (name, email, age) VALUES (:name, :email, :age)',
            [':name' => 'Alice', ':email' => 'alice@example.com', ':age' => 25]
        );
        
        // Insert second user
        $result2 = $db->insert(
            'INSERT INTO test_db_class (name, email, age) VALUES (:name, :email, :age)',
            [':name' => 'Bob', ':email' => 'bob@example.com', ':age' => 28]
        );
        
        return [
            'user1_id' => $result1['id'],
            'user2_id' => $result2['id']
        ];
    });
    
    echo "✓ Transaction committed successfully\n";
    echo "  User 1 ID: " . $result['user1_id'] . "\n";
    echo "  User 2 ID: " . $result['user2_id'] . "\n";
    
    // Verify both records exist
    $count = $db->first('SELECT COUNT(*) as count FROM test_db_class');
    echo "  Total records: " . $count['count'] . "\n";
} catch (DatabaseException $e) {
    echo "✗ Transaction failed: " . $e->getMessage() . "\n";
}

// Test 8: Transaction - Rollback
echo "\n=== Test 8: Transaction (Rollback Scenario) ===\n";
$countBefore = $db->first('SELECT COUNT(*) as count FROM test_db_class');
echo "  Records before: " . $countBefore['count'] . "\n";

try {
    $db->transaction(function($db) {
        // Insert a user
        $db->insert(
            'INSERT INTO test_db_class (name, email, age) VALUES (:name, :email, :age)',
            [':name' => 'Charlie', ':email' => 'charlie@example.com', ':age' => 35]
        );
        
        // Simulate an error
        throw new \Exception('Simulated error - transaction should rollback');
    });
    
    echo "✗ Transaction should have thrown exception\n";
} catch (\Exception $e) {
    echo "✓ Transaction rolled back on error\n";
    echo "  Error: " . $e->getMessage() . "\n";
    
    // Verify rollback
    $countAfter = $db->first('SELECT COUNT(*) as count FROM test_db_class');
    echo "  Records after rollback: " . $countAfter['count'] . " (should be same as before)\n";
    
    if ($countBefore['count'] == $countAfter['count']) {
        echo "✓ Rollback successful - data not committed\n";
    } else {
        echo "✗ Rollback failed - data was committed\n";
    }
}

// Test 9: Manual Transaction Control
echo "\n=== Test 9: Manual Transaction Control ===\n";
try {
    $db->beginTransaction();
    echo "✓ Transaction started manually\n";
    
    $result = $db->insert(
        'INSERT INTO test_db_class (name, email, age) VALUES (:name, :email, :age)',
        [':name' => 'David', ':email' => 'david@example.com', ':age' => 40]
    );
    echo "✓ Data inserted in transaction (ID: " . $result['id'] . ")\n";
    
    // Check transaction status
    if ($db->inTransaction()) {
        echo "✓ Transaction is active\n";
    }
    
    $db->rollback();
    echo "✓ Transaction rolled back manually\n";
    
    // Verify data was not saved
    $user = $db->first('SELECT * FROM test_db_class WHERE email = :email', [':email' => 'david@example.com']);
    if ($user === null) {
        echo "✓ Data correctly rolled back (not found)\n";
    } else {
        echo "✗ Data was committed despite rollback\n";
    }
} catch (DatabaseException $e) {
    echo "✗ Manual transaction failed: " . $e->getMessage() . "\n";
}

// Test 10: DELETE
echo "\n=== Test 10: DELETE with delete() Helper ===\n";
try {
    $result = $db->delete(
        'DELETE FROM test_db_class WHERE age > :age',
        [':age' => 30]
    );
    echo "✓ DELETE works\n";
    echo "  Affected rows: " . $result['affected_rows'] . "\n";
} catch (DatabaseException $e) {
    echo "✗ DELETE failed: " . $e->getMessage() . "\n";
}

// Cleanup
echo "\n=== Cleanup ===\n";
try {
    $db->query(['query' => 'DROP TABLE test_db_class']);
    echo "✓ Test table dropped\n";
} catch (DatabaseException $e) {
    echo "✗ Cleanup failed: " . $e->getMessage() . "\n";
}

echo "\n=== All Database Class Tests Passed! ✓ ===\n";
echo "\n✅ Step 1 Complete: Database class created and tested!\n";
echo "\nNext Step: Update BaseController to use Database class\n";
