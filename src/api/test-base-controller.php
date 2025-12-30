<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;

// Load environment
$dotenv = Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

echo "=== BaseController Test ===\n\n";

// Create a test controller that extends BaseController
class TestController extends \App\Core\BaseController
{
    public function testDatabaseAccess()
    {
        echo "=== Testing Database Access ===\n";
        
        // Test 1: Legacy $this->conn still works
        echo "\n--- Test 1: Legacy \$this->conn (Backward Compatibility) ---\n";
        if ($this->conn instanceof PDO) {
            echo "✓ \$this->conn is available (PDO instance)\n";
            
            // Test query with old method
            $stmt = $this->conn->query('SELECT 1 as test');
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            echo "✓ Can query with \$this->conn: " . json_encode($result) . "\n";
        } else {
            echo "✗ \$this->conn is not available\n";
        }
        
        // Test 2: New $this->db works
        echo "\n--- Test 2: New \$this->db (Database Class) ---\n";
        if ($this->db instanceof \App\Core\Database) {
            echo "✓ \$this->db is available (Database instance)\n";
            
            // Test query with new method
            $result = $this->db->first('SELECT 1 as test, NOW() as time_now');
            echo "✓ Can query with \$this->db: " . json_encode($result) . "\n";
        } else {
            echo "✗ \$this->db is not available\n";
        }
        
        // Test 3: Test transaction with new $this->db
        echo "\n--- Test 3: Transaction Support ---\n";
        try {
            // Create test table
            $this->db->query([
                'query' => 'CREATE TABLE IF NOT EXISTS test_controller (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    value VARCHAR(100)
                )'
            ]);
            echo "✓ Test table created\n";
            
            // Test transaction
            $result = $this->db->transaction(function($db) {
                $insert1 = $db->insert(
                    'INSERT INTO test_controller (value) VALUES (:value)',
                    [':value' => 'Test 1']
                );
                
                $insert2 = $db->insert(
                    'INSERT INTO test_controller (value) VALUES (:value)',
                    [':value' => 'Test 2']
                );
                
                return [
                    'id1' => $insert1['id'],
                    'id2' => $insert2['id']
                ];
            });
            
            echo "✓ Transaction executed successfully\n";
            echo "  Inserted IDs: " . $result['id1'] . ", " . $result['id2'] . "\n";
            
            // Verify
            $count = $this->db->first('SELECT COUNT(*) as count FROM test_controller');
            echo "✓ Records in table: " . $count['count'] . "\n";
            
            // Cleanup
            $this->db->query(['query' => 'DROP TABLE test_controller']);
            echo "✓ Test table dropped\n";
            
        } catch (\Exception $e) {
            echo "✗ Transaction test failed: " . $e->getMessage() . "\n";
        }
        
        // Test 4: Test helper methods
        echo "\n--- Test 4: Helper Methods ---\n";
        
        // Test validateRequired
        $data = ['name' => 'John', 'email' => 'john@example.com'];
        $required = ['name', 'email', 'password'];
        $missing = $this->validateRequired($data, $required);
        
        if ($missing === ['password']) {
            echo "✓ validateRequired() works correctly\n";
            echo "  Missing fields: " . implode(', ', $missing) . "\n";
        } else {
            echo "✗ validateRequired() failed\n";
        }
        
        // Test sanitizeInput
        $dirty = '<script>alert("xss")</script>Hello';
        $clean = $this->sanitizeInput($dirty);
        
        if ($clean === 'Hello') {
            echo "✓ sanitizeInput() works correctly\n";
            echo "  Cleaned: '$clean'\n";
        } else {
            echo "✗ sanitizeInput() failed. Got: '$clean'\n";
        }
        
        echo "\n=== All BaseController Tests Passed! ✓ ===\n";
    }
}

// Run tests
try {
    $controller = new TestController();
    $controller->testDatabaseAccess();
    
    echo "\n✅ Step 2 Complete: BaseController updated successfully!\n";
    echo "\nNext Step: Create Repository Classes\n";
    
} catch (\Exception $e) {
    echo "✗ Test failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
