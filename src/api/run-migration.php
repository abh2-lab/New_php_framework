<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;

// Load environment
$dotenv = Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->safeLoad();

// Load connection
require_once __DIR__ . '/Core/Config/connection.php';

echo "=== Database Migration Runner ===\n\n";

// Migration file path
$migrationFile = __DIR__ . '/databases/orders_system_migration.sql';

if (!file_exists($migrationFile)) {
    echo "✗ Migration file not found: $migrationFile\n";
    exit(1);
}

echo "Reading migration file...\n";
$sql = file_get_contents($migrationFile);

if (empty($sql)) {
    echo "✗ Migration file is empty\n";
    exit(1);
}

echo "✓ Migration file loaded\n";
echo "Size: " . strlen($sql) . " bytes\n\n";

try {
    // Disable foreign key checks temporarily
    $connpdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    echo "✓ Foreign key checks disabled\n";
    
    // Clean up SQL: remove comments
    $lines = explode("\n", $sql);
    $cleanedLines = [];
    
    foreach ($lines as $line) {
        $line = trim($line);
        // Skip empty lines and comment lines
        if (empty($line) || 
            strpos($line, '--') === 0 || 
            strpos($line, '/*') === 0 ||
            strpos($line, '*/') === 0) {
            continue;
        }
        $cleanedLines[] = $line;
    }
    
    $cleanedSql = implode("\n", $cleanedLines);
    
    // Split by semicolon
    $statements = explode(';', $cleanedSql);
    
    // Filter empty statements
    $statements = array_filter($statements, function($stmt) {
        return !empty(trim($stmt));
    });
    
    echo "Found " . count($statements) . " SQL statements\n\n";
    echo "Executing migration...\n";
    
    $executed = 0;
    $errors = [];
    
    foreach ($statements as $index => $statement) {
        $statement = trim($statement);
        if (empty($statement)) continue;
        
        try {
            // Extract table name for better output
            if (preg_match('/CREATE TABLE.*?`?(\w+)`?/i', $statement, $matches)) {
                echo "  Creating table: {$matches[1]}... ";
                $connpdo->exec($statement);
                echo "✓\n";
                $executed++;
            } elseif (preg_match('/INSERT INTO.*?`?(\w+)`?/i', $statement, $matches)) {
                echo "  Inserting data into: {$matches[1]}... ";
                $connpdo->exec($statement);
                echo "✓\n";
                $executed++;
            } else {
                // Execute other statements
                $connpdo->exec($statement);
                $executed++;
            }
        } catch (PDOException $e) {
            // Don't fail on duplicate key or table exists errors
            if ($e->getCode() == '23000' || $e->getCode() == '42S01') {
                echo "⚠ (already exists)\n";
            } else {
                $errors[] = "Statement " . ($index + 1) . ": " . $e->getMessage();
                echo "✗ Error: " . $e->getMessage() . "\n";
            }
        }
    }
    
    // Re-enable foreign key checks
    $connpdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    echo "\n✓ Foreign key checks re-enabled\n";
    
    if (empty($errors)) {
        echo "\n✓ Migration completed successfully!\n";
        echo "Executed $executed SQL statements\n\n";
    } else {
        echo "\n⚠ Migration completed with errors:\n";
        foreach ($errors as $error) {
            echo "  - $error\n";
        }
        echo "\n";
    }
    
    // Verify tables
    echo "=== Verification ===\n";
    
    $tables = ['products', 'orders', 'order_items', 'order_transactions'];
    foreach ($tables as $table) {
        try {
            $result = $connpdo->query("SELECT COUNT(*) as count FROM $table")->fetch(PDO::FETCH_ASSOC);
            echo "✓ Table '$table' exists with {$result['count']} records\n";
        } catch (PDOException $e) {
            echo "✗ Table '$table' does not exist\n";
        }
    }
    
    echo "\n✅ Database is ready for testing!\n";
    
} catch (PDOException $e) {
    echo "\n✗ Migration failed: " . $e->getMessage() . "\n";
    echo "Error Code: " . $e->getCode() . "\n";
    exit(1);
}
