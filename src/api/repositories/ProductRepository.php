<?php
// src/api/Repositories/ProductRepository.php

namespace App\Repositories;

use App\Core\Database;
use App\Core\Exceptions\DatabaseException;

/**
 * ProductRepository - Handles all product data access
 */
class ProductRepository
{
    private Database $db;
    
    public function __construct(Database $db)
    {
        $this->db = $db;
    }
    
    /**
     * Find product by ID
     * 
     * @param int $productId Product ID
     * @return array|null Product data or null if not found
     */
    public function findById(int $productId): ?array
    {
        $query = "SELECT 
            id,
            name,
            sku,
            price,
            stock,
            category,
            status,
            created_at,
            updated_at
        FROM products
        WHERE id = :id AND status = 'active'";
        
        return $this->db->first($query, [':id' => $productId]);
    }
    
    /**
     * Get all products with optional filters
     * 
     * @param string|null $category Filter by category
     * @param int $limit Maximum results
     * @return array Products array
     */
    public function getAll(?string $category = null, int $limit = 10): array
    {
        $params = [':limit' => $limit];
        
        $query = "SELECT 
            id,
            name,
            sku,
            price,
            stock,
            category,
            created_at
        FROM products
        WHERE status = 'active'";
        
        if ($category) {
            $query .= " AND category = :category";
            $params[':category'] = $category;
        }
        
        $query .= " ORDER BY created_at DESC LIMIT :limit";
        
        return $this->db->select($query, $params);
    }
    
    /**
     * Reduce product stock (for orders)
     * 
     * @param int $productId Product ID
     * @param int $quantity Quantity to reduce
     * @return bool Success status
     * @throws DatabaseException If stock reduction fails
     */
    public function reduceStock(int $productId, int $quantity): bool
    {
        // Fixed: Use unique parameter names
        $query = "UPDATE products 
                  SET stock = stock - :quantity,
                      updated_at = NOW()
                  WHERE id = :id 
                  AND stock >= :min_quantity
                  AND status = 'active'";
        
        $result = $this->db->update($query, [
            ':id' => $productId,
            ':quantity' => $quantity,
            ':min_quantity' => $quantity  // Different parameter name for WHERE clause
        ]);
        
        if ($result['affected_rows'] === 0) {
            throw new DatabaseException("Failed to reduce stock for product ID $productId. Insufficient stock or product not found.");
        }
        
        return true;
    }
    
    /**
     * Increase product stock (for cancellations/returns)
     * 
     * @param int $productId Product ID
     * @param int $quantity Quantity to add
     * @return bool Success status
     */
    public function increaseStock(int $productId, int $quantity): bool
    {
        $query = "UPDATE products 
                  SET stock = stock + :quantity,
                      updated_at = NOW()
                  WHERE id = :id 
                  AND status = 'active'";
        
        $result = $this->db->update($query, [
            ':id' => $productId,
            ':quantity' => $quantity
        ]);
        
        return $result['affected_rows'] > 0;
    }
    
    /**
     * Create new product
     * 
     * @param array $data Product data
     * @return int Inserted product ID
     */
    public function create(array $data): int
    {
        $query = "INSERT INTO products (
            name,
            sku,
            price,
            stock,
            category,
            status,
            created_at
        ) VALUES (
            :name,
            :sku,
            :price,
            :stock,
            :category,
            :status,
            NOW()
        )";
        
        $result = $this->db->insert($query, [
            ':name' => $data['name'],
            ':sku' => $data['sku'],
            ':price' => $data['price'],
            ':stock' => $data['stock'] ?? 0,
            ':category' => $data['category'] ?? null,
            ':status' => $data['status'] ?? 'active'
        ]);
        
        return (int)$result['id'];
    }
    
    /**
     * Update product
     * 
     * @param int $productId Product ID
     * @param array $data Data to update
     * @return bool Success status
     */
    public function update(int $productId, array $data): bool
    {
        $setClauses = [];
        $params = [':id' => $productId];
        
        foreach ($data as $key => $value) {
            $setClauses[] = "$key = :$key";
            $params[":$key"] = $value;
        }
        
        $setClauses[] = "updated_at = NOW()";
        
        $query = "UPDATE products SET " . implode(', ', $setClauses) . " WHERE id = :id";
        
        $result = $this->db->update($query, $params);
        
        return $result['affected_rows'] > 0;
    }
}
