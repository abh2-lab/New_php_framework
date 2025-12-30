<?php
// src/api/Controllers/OrderController.php

namespace App\Controllers;

use App\Core\BaseController;
use App\Services\OrderService;

/**
 * OrderController - Handles order-related API endpoints
 */
class OrderController extends BaseController
{
    private OrderService $orderService;

    public function __construct()
    {
        parent::__construct();
        $this->orderService = new OrderService($this->db);
    }

    /**
     * Create a new order
     * 
     * POST /api/orders/create
     * 
     * Body:
     * {
     *   "user_id": 1,
     *   "payment_method": "card",
     *   "items": [
     *     {"product_id": 1, "quantity": 2},
     *     {"product_id": 2, "quantity": 1}
     *   ]
     * }
     */
    public function create()
    {
        $data = $this->getRequestData();

        // Validate required fields
        $required = ['user_id', 'payment_method', 'items'];
        $missing = $this->validateRequired($data, $required);

        if (!empty($missing)) {
            return $this->sendValidationError(
                'Missing required fields',
                array_fill_keys($missing, 'This field is required')
            );
        }

        // Validate items array
        if (!is_array($data['items']) || empty($data['items'])) {
            return $this->sendValidationError(
                'Invalid items data',
                ['items' => 'Items must be a non-empty array']
            );
        }

        // Calculate total amount (this could also be done in the service)
        $totalAmount = 0;
        foreach ($data['items'] as $item) {
            if (empty($item['product_id']) || empty($item['quantity'])) {
                return $this->sendValidationError(
                    'Invalid item data',
                    ['items' => 'Each item must have product_id and quantity']
                );
            }
        }

        try {
            // Create order via service
            $orderData = [
                'user_id' => $data['user_id'],
                'total_amount' => 0, // Will be calculated by service
                'payment_method' => $data['payment_method'],
                'notes' => $data['notes'] ?? null
            ];

            $order = $this->orderService->createOrder($orderData, $data['items']);

            return $this->sendSuccess('Order created successfully', $order);

        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    /**
     * Get order details
     * 
     * GET /api/orders/details?order_id=1
     */
    public function details()
    {
        $params = $this->getQueryParams();

        if (empty($params['order_id'])) {
            return $this->sendValidationError(
                'Order ID is required',
                ['order_id' => 'This field is required']
            );
        }

        try {
            $details = $this->orderService->getOrderDetails((int)$params['order_id']);
            return $this->sendSuccess('Order details retrieved', $details);

        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    /**
     * Cancel an order
     * 
     * POST /api/orders/cancel
     * 
     * Body:
     * {
     *   "order_id": 1,
     *   "reason": "Customer requested cancellation"
     * }
     */
    public function cancel()
    {
        $data = $this->getRequestData();

        // Validate required fields
        $required = ['order_id', 'reason'];
        $missing = $this->validateRequired($data, $required);

        if (!empty($missing)) {
            return $this->sendValidationError(
                'Missing required fields',
                array_fill_keys($missing, 'This field is required')
            );
        }

        try {
            $cancelled = $this->orderService->cancelOrder(
                (int)$data['order_id'],
                $data['reason']
            );

            if ($cancelled) {
                return $this->sendSuccess('Order cancelled successfully', [
                    'order_id' => $data['order_id'],
                    'status' => 'cancelled'
                ]);
            } else {
                return $this->sendError('Failed to cancel order', 400);
            }

        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }
}
