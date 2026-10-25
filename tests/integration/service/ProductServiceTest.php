<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization of ProductService against the seeded catalog (read-only, so nothing to clean up).
 * Expected ids, order and keys are the values observed on the unmodified code.
 */
final class ProductServiceTest extends TestCase
{
    private PDO $pdo;
    private ProductService $products;

    protected function setUp(): void
    {
        $host = (string)(getenv('DB_HOST') ?: '');
        if ($host === '') {
            $this->markTestSkipped('DB_HOST not set; live service test skipped');
        }
        $this->pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, getenv('DB_NAME') ?: 'shop_db'),
            (string)(getenv('DB_USER') ?: 'shop_user'),
            (string)(getenv('DB_PASS') ?: 'shop_pass'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->products = new ProductService($this->pdo);
    }

    public function testSearchByCategoryAndPriceRange(): void
    {
        $result = $this->products->search(['category_id' => 1, 'min_price' => 100000, 'max_price' => 300000]);

        $this->assertSame([50, 60, 58, 61, 54], array_column($result['products'], 'id'));
        $this->assertSame([180000.0, 195000.0, 210000.0, 250000.0, 290000.0], array_column($result['products'], 'price'));
        $this->assertSame(['page' => 1, 'limit' => null, 'total' => 5, 'total_pages' => 1], $result['pagination']);
        $this->assertArrayHasKey('cache', $result);
        $this->assertEqualsCanonicalizing(
            [
                'id', 'category_id', 'subcategory_id', 'name', 'price', 'stock', 'image', 'description',
                'category_name', 'category', 'subcategory', 'subcategory_name', 'sizes', 'variants',
                'colors', 'canonical_colors', 'available_colors', 'available_sizes',
            ],
            array_keys($result['products'][0])
        );
        $this->assertIsInt($result['products'][0]['id']);
        $this->assertIsInt($result['products'][0]['category_id']);
        $this->assertIsInt($result['products'][0]['stock']);
    }

    public function testSearchByFreeTextTerm(): void
    {
        $result = $this->products->search(['search' => 'áo thun']);

        $this->assertSame([50, 58], array_column($result['products'], 'id'));
        $this->assertSame(['Áo Thun Cotton Basic Trắng', 'Áo Thun Graphic Phối Màu'], array_column($result['products'], 'name'));
        $this->assertSame(['page' => 1, 'limit' => null, 'total' => 2, 'total_pages' => 1], $result['pagination']);
    }

    public function testSearchByProductReferenceRoutesToDetail(): void
    {
        $result = $this->products->search(['search' => '#50']);

        $this->assertSame('search_products', $result['routed_from']);
        $this->assertSame('get_product_detail', $result['routed_to']);
        $this->assertSame([50], array_column($result['products'], 'id'));
        $this->assertSame(['page' => 1, 'limit' => 1, 'total' => 1, 'total_pages' => 1], $result['pagination']);
    }

    public function testDetailOfProduct50(): void
    {
        $result = $this->products->detail(['product_id' => 50]);

        $this->assertSame(['product', 'cache'], array_keys($result));
        $this->assertSame(
            ['product_detail_static_cache_hit' => true, 'cache_policy' => 'static_metadata_only_fresh_price_stock'],
            $result['cache']
        );
        $product = $result['product'];
        $this->assertSame(
            [
                'id', 'category_id', 'subcategory_id', 'name', 'description', 'image', 'category_name', 'category',
                'subcategory', 'subcategory_name', 'sizes', 'available_colors', 'avg_rating', 'total_reviews',
                'price', 'stock', 'variants', 'colors', 'canonical_colors', 'available_sizes',
            ],
            array_keys($product)
        );
        $this->assertSame(50, $product['id']);
        $this->assertSame(1, $product['category_id']);
        $this->assertSame('Áo Thun Cotton Basic Trắng', $product['name']);
        $this->assertSame('at_basic_01.jpg', $product['image']);
        $this->assertSame('Áo', $product['category_name']);
        $this->assertSame('tops', $product['category']);
        $this->assertSame(['trắng'], $product['available_colors']);
        $this->assertSame(['S', 'M', 'L', 'XL'], $product['available_sizes']);
        $this->assertSame(['S', 'M', 'L', 'XL'], array_column($product['sizes'], 'size_name'));
        $this->assertNull($product['avg_rating']);
        $this->assertSame(0, $product['total_reviews']);
        $this->assertSame(180000.0, $product['price']);
        $this->assertSame((int)$this->pdo->query('SELECT stock FROM products WHERE id = 50')->fetchColumn(), $product['stock']);
    }

    public function testDetailErrors(): void
    {
        $this->assertSame(['error' => 'Product ID required'], $this->products->detail([]));
        $this->assertSame(['error' => 'Product not found'], $this->products->detail(['product_id' => 999999]));
    }

    public function testCategories(): void
    {
        $this->assertSame(
            [
                'categories' => [
                    ['id' => 1, 'name' => 'Áo', 'canonical_key' => 'tops', 'family' => 'apparel'],
                    ['id' => 2, 'name' => 'Quần', 'canonical_key' => 'bottoms', 'family' => 'apparel'],
                    ['id' => 3, 'name' => 'Váy & Đầm', 'canonical_key' => 'dresses_skirts', 'family' => 'apparel'],
                    ['id' => 4, 'name' => 'Phụ kiện', 'canonical_key' => 'accessories', 'family' => 'accessory'],
                    ['id' => 5, 'name' => 'Giày dép', 'canonical_key' => 'footwear', 'family' => 'footwear'],
                ],
            ],
            $this->products->categories()
        );
    }
}
