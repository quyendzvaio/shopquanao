<?php

require_once __DIR__ . '/../controllers/chatbot/ToolRegistry.php';

/** Transitional application-service boundary for product/category reads. */
final class ProductService
{
    private ToolRegistry $legacy;

    public function __construct(PDO $pdo, ?int $userId = null)
    {
        $this->legacy = new ToolRegistry($pdo, $userId);
    }

    public function search(array $arguments): array
    {
        return $this->legacy->execute('search_products', $arguments);
    }

    public function detail(array $arguments): array
    {
        return $this->legacy->execute('get_product_detail', $arguments);
    }

    public function categories(): array
    {
        return $this->legacy->execute('get_categories', []);
    }
}
