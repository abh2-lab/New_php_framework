-- ============================================================================
-- Orders System Migration
-- Purpose: Create tables for Products, Orders, Order Items, and Transactions
-- Version: 1.0
-- Date: 2025-12-30
-- ============================================================================

-- ============================================================================
-- PRODUCTS TABLE
-- ============================================================================
CREATE TABLE IF NOT EXISTS products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    sku VARCHAR(100) UNIQUE NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    stock INT NOT NULL DEFAULT 0,
    category VARCHAR(100),
    description TEXT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_sku (sku),
    INDEX idx_category (category),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- ORDERS TABLE
-- ============================================================================
CREATE TABLE IF NOT EXISTS orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('card', 'cash', 'upi') NOT NULL,
    status ENUM('pending', 'confirmed', 'cancelled', 'completed') DEFAULT 'pending',
    notes TEXT,
    cancellation_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    cancelled_at TIMESTAMP NULL,
    
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- ORDER ITEMS TABLE
-- ============================================================================
CREATE TABLE IF NOT EXISTS order_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_order_id (order_id),
    INDEX idx_product_id (product_id),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- ORDER TRANSACTIONS TABLE (Audit Log)
-- ============================================================================
CREATE TABLE IF NOT EXISTS order_transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    action VARCHAR(50) NOT NULL COMMENT 'order_created, order_cancelled, payment_received, etc.',
    amount DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(50),
    status VARCHAR(50) COMMENT 'success, failed, refunded, etc.',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_order_id (order_id),
    INDEX idx_action (action),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SEED DATA (Sample Products)
-- ============================================================================
INSERT INTO products (name, sku, price, stock, category, description, status) VALUES
('Laptop Pro 15"', 'LAP-001', 999.99, 10, 'Electronics', 'High-performance laptop with 16GB RAM', 'active'),
('Wireless Mouse', 'MOU-001', 29.99, 50, 'Electronics', 'Ergonomic wireless mouse with USB receiver', 'active'),
('USB-C Cable', 'CAB-001', 9.99, 100, 'Accessories', 'High-speed USB-C charging cable 2m', 'active'),
('Laptop Bag', 'BAG-001', 49.99, 25, 'Accessories', 'Waterproof laptop bag with multiple compartments', 'active'),
('Wireless Keyboard', 'KEY-001', 79.99, 30, 'Electronics', 'Mechanical wireless keyboard with RGB', 'active')
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

-- ============================================================================
-- VERIFICATION QUERIES (Optional - for checking)
-- ============================================================================
-- SELECT 'Products table created' as status, COUNT(*) as product_count FROM products;
-- SELECT 'Orders table created' as status FROM orders LIMIT 1;
-- SELECT 'Order items table created' as status FROM order_items LIMIT 1;
-- SELECT 'Order transactions table created' as status FROM order_transactions LIMIT 1;
