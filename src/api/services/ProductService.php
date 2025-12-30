<?php
// src/api/Services/ProductService.php

namespace App\Services;

use App\Core\Database;
use App\Repositories\ProductRepository;

/**
 * ProductService - Business logic for product management
 */
class ProductService
{
    private Database $db;
    private ProductRepository $productRepo;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->productRepo = new ProductRepository($db);
    }

    /**
     * Get all products with filters
     * 
     * @param array $filters Filter criteria
     * @return array Products list
     */
    public function getProducts(array $filters = []): array
    {
        $category = $filters['category'] ?? null;
        $limit = $filters['limit'] ?? 10;

        return $this->productRepo->getAll($category, $limit);
    }

    /**
     * Get product by ID
     * 
     * @param int $productId Product ID
     * @return array Product data
     * @throws \Exception If product not found
     */
    public function getProduct(int $productId): array
    {
        $product = $this->productRepo->findById($productId);

        if (!$product) {
            throw new \Exception("Product ID $productId not found");
        }

        return $product;
    }

    /**
     * Create new product
     * 
     * @param array $data Product data
     * @return array Created product
     * @throws \Exception On validation or creation error
     */
    public function createProduct(array $data): array
    {
        // Validate
        $this->validateProductData($data);

        // Create
        $productId = $this->productRepo->create($data);

        // Return created product
        return $this->productRepo->findById($productId);
    }

    /**
     * Update product
     * 
     * @param int $productId Product ID
     * @param array $data Data to update
     * @return array Updated product
     * @throws \Exception On error
     */
    public function updateProduct(int $productId, array $data): array
    {
        // Check product exists
        $product = $this->productRepo->findById($productId);
        if (!$product) {
            throw new \Exception("Product ID $productId not found");
        }

        // Update
        $updated = $this->productRepo->update($productId, $data);

        if (!$updated) {
            throw new \Exception("Failed to update product");
        }

        // Return updated product
        return $this->productRepo->findById($productId);
    }

    /**
     * Validate product data
     * 
     * @param array $data Product data
     * @throws \Exception On validation failure
     */
    private function validateProductData(array $data): void
    {
        if (empty($data['name'])) {
            throw new \Exception("Product name is required");
        }

        if (empty($data['sku'])) {
            throw new \Exception("Product SKU is required");
        }

        if (!isset($data['price']) || $data['price'] < 0) {
            throw new \Exception("Valid product price is required");
        }
    }
}
