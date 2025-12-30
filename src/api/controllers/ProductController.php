<?php
// src/api/Controllers/ProductController.php

namespace App\Controllers;

use App\Core\BaseController;
use App\Services\ProductService;

/**
 * ProductController - Handles product-related API endpoints
 */
class ProductController extends BaseController
{
    private ProductService $productService;

    public function __construct()
    {
        parent::__construct();
        $this->productService = new ProductService($this->db);
    }

    /**
     * Get all products
     * 
     * GET /api/products/list?category=Electronics&limit=10
     */
    public function list()
    {
        $params = $this->getQueryParams();

        $filters = [
            'category' => $params['category'] ?? null,
            'limit' => isset($params['limit']) ? (int)$params['limit'] : 10
        ];

        try {
            $products = $this->productService->getProducts($filters);
            
            return $this->sendSuccess('Products retrieved successfully', [
                'products' => $products,
                'count' => count($products)
            ]);

        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    /**
     * Get product details
     * 
     * GET /api/products/details?product_id=1
     */
    public function details()
    {
        $params = $this->getQueryParams();

        if (empty($params['product_id'])) {
            return $this->sendValidationError(
                'Product ID is required',
                ['product_id' => 'This field is required']
            );
        }

        try {
            $product = $this->productService->getProduct((int)$params['product_id']);
            return $this->sendSuccess('Product details retrieved', $product);

        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    /**
     * Create new product
     * 
     * POST /api/products/create
     * 
     * Body:
     * {
     *   "name": "New Product",
     *   "sku": "PROD-001",
     *   "price": 99.99,
     *   "stock": 10,
     *   "category": "Electronics"
     * }
     */
    public function create()
    {
        $data = $this->getRequestData();

        // Validate required fields
        $required = ['name', 'sku', 'price'];
        $missing = $this->validateRequired($data, $required);

        if (!empty($missing)) {
            return $this->sendValidationError(
                'Missing required fields',
                array_fill_keys($missing, 'This field is required')
            );
        }

        try {
            $product = $this->productService->createProduct($data);
            
            return $this->sendSuccess('Product created successfully', $product);

        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    /**
     * Update product
     * 
     * POST /api/products/update
     * 
     * Body:
     * {
     *   "product_id": 1,
     *   "name": "Updated Name",
     *   "price": 129.99
     * }
     */
    public function update()
    {
        $data = $this->getRequestData();

        if (empty($data['product_id'])) {
            return $this->sendValidationError(
                'Product ID is required',
                ['product_id' => 'This field is required']
            );
        }

        $productId = (int)$data['product_id'];
        unset($data['product_id']); // Remove ID from update data

        try {
            $product = $this->productService->updateProduct($productId, $data);
            
            return $this->sendSuccess('Product updated successfully', $product);

        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }
}
