<?php
// src/api/Repositories/OrderRepository.php

namespace App\Repositories;

use App\Core\Database;

/**
 * OrderRepository - Handles all order data access
 */
class OrderRepository
{
    private Database $db;
    
    public function __construct(Database $db)
    {
        $this->db = $db;
    }
    
    /**
     * Create new order
     * 
     * @param array $data Order data
     * @return int Inserted order ID
     */
    public function create(array $data): int
    {
        $query = "INSERT INTO orders (
            user_id, 
            total_amount, 
            payment_method, 
            status, 
            notes,
            created_at
        ) VALUES (
            :user_id,
            :total_amount,
            :payment_method,
            :status,
            :notes,
            NOW()
        )";
        
        $result = $this->db->insert($query, [
            ':user_id' => $data['user_id'],
            ':total_amount' => $data['total_amount'],
            ':payment_method' => $data['payment_method'],
            ':status' => $data['status'],
            ':notes' => $data['notes'] ?? null
        ]);
        
        return (int)$result['id'];
    }
    
    /**
     * Add order item
     * 
     * @param array $data Order item data
     * @return int Inserted item ID
     */
    public function addOrderItem(array $data): int
    {
        $query = "INSERT INTO order_items (
            order_id,
            product_id,
            quantity,
            price,
            subtotal,
            created_at
        ) VALUES (
            :order_id,
            :product_id,
            :quantity,
            :price,
            :subtotal,
            NOW()
        )";
        
        $result = $this->db->insert($query, [
            ':order_id' => $data['order_id'],
            ':product_id' => $data['product_id'],
            ':quantity' => $data['quantity'],
            ':price' => $data['price'],
            ':subtotal' => $data['subtotal']
        ]);
        
        return (int)$result['id'];
    }
    
    /**
     * Find order by ID
     * 
     * @param int $orderId Order ID
     * @return array|null Order data or null
     */
    public function findById(int $orderId): ?array
    {
        $query = "SELECT 
            id,
            user_id,
            total_amount,
            payment_method,
            status,
            notes,
            cancellation_reason,
            created_at,
            cancelled_at,
            updated_at
        FROM orders 
        WHERE id = :id";
        
        return $this->db->first($query, [':id' => $orderId]);
    }
    
    /**
     * Get order items
     * 
     * @param int $orderId Order ID
     * @return array Order items
     */
    public function getOrderItems(int $orderId): array
    {
        $query = "SELECT 
            oi.id,
            oi.product_id,
            p.name as product_name,
            p.sku,
            oi.quantity,
            oi.price,
            oi.subtotal
        FROM order_items oi
        INNER JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = :order_id
        ORDER BY oi.id";
        
        return $this->db->select($query, [':order_id' => $orderId]);
    }
    
    /**
     * Update order
     * 
     * @param int $orderId Order ID
     * @param array $data Data to update
     * @return bool Success status
     */
    public function update(int $orderId, array $data): bool
    {
        $setClauses = [];
        $params = [':id' => $orderId];
        
        foreach ($data as $key => $value) {
            $setClauses[] = "$key = :$key";
            $params[":$key"] = $value;
        }
        
        $setClauses[] = "updated_at = NOW()";
        
        $query = "UPDATE orders SET " . implode(', ', $setClauses) . " WHERE id = :id";
        
        $result = $this->db->update($query, $params);
        
        return $result['affected_rows'] > 0;
    }
    
    /**
     * Create transaction log
     * 
     * @param array $data Transaction data
     * @return int Inserted log ID
     */
    public function createTransactionLog(array $data): int
    {
        $query = "INSERT INTO order_transactions (
            order_id,
            action,
            amount,
            payment_method,
            status,
            notes,
            created_at
        ) VALUES (
            :order_id,
            :action,
            :amount,
            :payment_method,
            :status,
            :notes,
            NOW()
        )";
        
        $result = $this->db->insert($query, [
            ':order_id' => $data['order_id'],
            ':action' => $data['action'],
            ':amount' => $data['amount'],
            ':payment_method' => $data['payment_method'],
            ':status' => $data['status'],
            ':notes' => $data['notes'] ?? null
        ]);
        
        return (int)$result['id'];
    }
    
    /**
     * Get transaction log for an order
     * 
     * @param int $orderId Order ID
     * @return array Transaction logs
     */
    public function getTransactionLog(int $orderId): array
    {
        $query = "SELECT 
            id,
            action,
            amount,
            payment_method,
            status,
            notes,
            created_at
        FROM order_transactions
        WHERE order_id = :order_id
        ORDER BY created_at DESC";
        
        return $this->db->select($query, [':order_id' => $orderId]);
    }
    
    /**
     * Get orders by user
     * 
     * @param int $userId User ID
     * @param int $limit Maximum results
     * @return array Orders array
     */
    public function findByUserId(int $userId, int $limit = 10): array
    {
        $query = "SELECT 
            id,
            total_amount,
            payment_method,
            status,
            created_at
        FROM orders
        WHERE user_id = :user_id
        ORDER BY created_at DESC
        LIMIT :limit";
        
        return $this->db->select($query, [
            ':user_id' => $userId,
            ':limit' => $limit
        ]);
    }
}
