<?php
// src/api/Services/OrderService.php

namespace App\Services;

use App\Core\Database;
use App\Repositories\ProductRepository;
use App\Repositories\OrderRepository;
use App\Core\Exceptions\DatabaseException;

/**
 * OrderService - Business logic for order management
 * 
 * Handles order creation with transactions to ensure data consistency
 */
class OrderService
{
    private Database $db;
    private ProductRepository $productRepo;
    private OrderRepository $orderRepo;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->productRepo = new ProductRepository($db);
        $this->orderRepo = new OrderRepository($db);
    }

    /**
     * Create a new order with items
     * Uses transaction to ensure atomicity
     * 
     * @param array $orderData Order data
     * @param array $items Order items
     * @return array Created order with details
     * @throws \Exception On validation or database errors
     */
    public function createOrder(array $orderData, array $items): array
    {
        // Validate input
        $this->validateOrderData($orderData, $items);

        try {
            // Start transaction
            return $this->db->transaction(function($db) use ($orderData, $items) {
                // Step 1: Create the order
                $orderId = $this->orderRepo->create([
                    'user_id' => $orderData['user_id'],
                    'total_amount' => $orderData['total_amount'],
                    'payment_method' => $orderData['payment_method'],
                    'status' => 'pending',
                    'notes' => $orderData['notes'] ?? null
                ]);

                // Step 2: Process each order item
                $orderItems = [];
                foreach ($items as $item) {
                    // Verify product exists and has enough stock
                    $product = $this->productRepo->findById($item['product_id']);
                    
                    if (!$product) {
                        throw new \Exception("Product ID {$item['product_id']} not found");
                    }

                    if ($product['stock'] < $item['quantity']) {
                        throw new \Exception("Insufficient stock for product: {$product['name']}. Available: {$product['stock']}, Requested: {$item['quantity']}");
                    }

                    // Calculate subtotal
                    $subtotal = $product['price'] * $item['quantity'];

                    // Add order item
                    $itemId = $this->orderRepo->addOrderItem([
                        'order_id' => $orderId,
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'price' => $product['price'],
                        'subtotal' => $subtotal
                    ]);

                    // Reduce product stock
                    $this->productRepo->reduceStock($item['product_id'], $item['quantity']);

                    $orderItems[] = [
                        'id' => $itemId,
                        'product_id' => $item['product_id'],
                        'product_name' => $product['name'],
                        'quantity' => $item['quantity'],
                        'price' => $product['price'],
                        'subtotal' => $subtotal
                    ];
                }

                // Step 3: Log the transaction
                $this->orderRepo->createTransactionLog([
                    'order_id' => $orderId,
                    'action' => 'order_created',
                    'amount' => $orderData['total_amount'],
                    'payment_method' => $orderData['payment_method'],
                    'status' => 'success',
                    'notes' => 'Order created successfully'
                ]);

                // Return complete order data
                return [
                    'order_id' => $orderId,
                    'user_id' => $orderData['user_id'],
                    'total_amount' => $orderData['total_amount'],
                    'payment_method' => $orderData['payment_method'],
                    'status' => 'pending',
                    'items' => $orderItems,
                    'created_at' => date('Y-m-d H:i:s')
                ];
            });

        } catch (DatabaseException $e) {
            throw new \Exception("Database error while creating order: " . $e->getMessage());
        } catch (\Exception $e) {
            throw new \Exception("Failed to create order: " . $e->getMessage());
        }
    }

    /**
     * Cancel an order and restore stock
     * 
     * @param int $orderId Order ID
     * @param string $reason Cancellation reason
     * @return bool Success status
     * @throws \Exception On error
     */
    public function cancelOrder(int $orderId, string $reason): bool
    {
        try {
            return $this->db->transaction(function($db) use ($orderId, $reason) {
                // Get order
                $order = $this->orderRepo->findById($orderId);
                
                if (!$order) {
                    throw new \Exception("Order ID $orderId not found");
                }

                if ($order['status'] === 'cancelled') {
                    throw new \Exception("Order is already cancelled");
                }

                if ($order['status'] === 'completed') {
                    throw new \Exception("Cannot cancel completed order");
                }

                // Get order items
                $items = $this->orderRepo->getOrderItems($orderId);

                // Restore stock for each item
                foreach ($items as $item) {
                    $this->productRepo->increaseStock($item['product_id'], $item['quantity']);
                }

                // Update order status
                $this->orderRepo->update($orderId, [
                    'status' => 'cancelled',
                    'cancellation_reason' => $reason,
                    'cancelled_at' => date('Y-m-d H:i:s')
                ]);

                // Log cancellation
                $this->orderRepo->createTransactionLog([
                    'order_id' => $orderId,
                    'action' => 'order_cancelled',
                    'amount' => $order['total_amount'],
                    'payment_method' => $order['payment_method'],
                    'status' => 'refunded',
                    'notes' => "Cancellation reason: $reason"
                ]);

                return true;
            });

        } catch (\Exception $e) {
            throw new \Exception("Failed to cancel order: " . $e->getMessage());
        }
    }

    /**
     * Get order details with items
     * 
     * @param int $orderId Order ID
     * @return array Order details
     * @throws \Exception If order not found
     */
    public function getOrderDetails(int $orderId): array
    {
        $order = $this->orderRepo->findById($orderId);
        
        if (!$order) {
            throw new \Exception("Order ID $orderId not found");
        }

        $items = $this->orderRepo->getOrderItems($orderId);
        $transactions = $this->orderRepo->getTransactionLog($orderId);

        return [
            'order' => $order,
            'items' => $items,
            'transactions' => $transactions
        ];
    }

    /**
     * Validate order data
     * 
     * @param array $orderData Order data
     * @param array $items Order items
     * @throws \Exception On validation failure
     */
    private function validateOrderData(array $orderData, array $items): void
    {
        // Validate required fields
        if (empty($orderData['user_id'])) {
            throw new \Exception("User ID is required");
        }

        if (empty($orderData['payment_method'])) {
            throw new \Exception("Payment method is required");
        }

        if (!in_array($orderData['payment_method'], ['card', 'cash', 'upi'])) {
            throw new \Exception("Invalid payment method");
        }

        if (empty($items) || !is_array($items)) {
            throw new \Exception("Order must have at least one item");
        }

        // Validate items
        foreach ($items as $item) {
            if (empty($item['product_id'])) {
                throw new \Exception("Product ID is required for all items");
            }

            if (empty($item['quantity']) || $item['quantity'] < 1) {
                throw new \Exception("Valid quantity is required for all items");
            }
        }
    }
}
