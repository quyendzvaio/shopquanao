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
    private string $cacheDir = '';
    /** @var array<string,mixed>|null Cache statics and env to restore in tearDown */
    private ?array $saved = null;

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
        $this->isolateCacheAndReranker();
        $this->products = new ProductService($this->pdo);
    }

    protected function tearDown(): void
    {
        if ($this->saved === null) {
            return;
        }
        foreach (['dir', 'redis', 'redisChecked'] as $name) {
            $this->cacheProperty($name)->setValue(null, $this->saved[$name]);
        }
        $this->saved['rerankerUrl'] === false ? putenv('RERANKER_URL') : putenv('RERANKER_URL=' . $this->saved['rerankerUrl']);
        $this->removeDir($this->cacheDir);
        $this->saved = null;
    }

    public function testSearchByCategoryAndPriceRange(): void
    {
        $args = ['category_id' => 1, 'min_price' => 100000, 'max_price' => 300000];
        $this->assertSame(0, $this->cacheEntryCount(), 'cache must start cold');
        $result = $this->products->search($args);
        $this->assertFalse($result['cache']['product_cache_hit'], 'cold call must run the SQL search');
        $this->assertGreaterThan(0, $this->cacheEntryCount());

        $this->assertSame([50, 60, 58, 61, 54], array_column($result['products'], 'id'));
        $this->assertSame([180000.0, 195000.0, 210000.0, 250000.0, 290000.0], array_column($result['products'], 'price'));
        $this->assertSame(['page' => 1, 'limit' => null, 'total' => 5, 'total_pages' => 1], $result['pagination']);
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

        $warm = $this->products->search($args);
        $this->assertTrue($warm['cache']['product_cache_hit'], 'second call is served from the cache');
        $this->assertSame(array_column($result['products'], 'id'), array_column($warm['products'], 'id'));
        $this->assertSame($result['pagination'], $warm['pagination']);
    }

    public function testSearchByFreeTextTerm(): void
    {
        $this->assertSame(0, $this->cacheEntryCount(), 'cache must start cold');
        $result = $this->products->search(['search' => 'áo thun']);
        $this->assertFalse($result['cache']['product_cache_hit'], 'cold call must run the SQL search');

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
        $this->assertNull(Cache::getProductDetailStatic(50), 'cache must start cold');
        $result = $this->products->detail(['product_id' => 50]);
        $this->assertNotNull(Cache::getProductDetailStatic(50), 'cold call ran the static SQL and stored it');

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

        $this->assertSame($result, $this->products->detail(['product_id' => 50]), 'warm call returns the same product');
    }

    public function testDetailErrors(): void
    {
        $this->assertSame(['error' => 'Product ID required'], $this->products->detail([]));
        $this->assertSame(['error' => 'Product not found'], $this->products->detail(['product_id' => 999999]));
    }

    public function testCategories(): void
    {
        $this->assertNull(Cache::getCategories(), 'cache must start cold');
        $cold = $this->products->categories();
        $this->assertNotNull(Cache::getCategories(), 'cold call ran the SQL and stored it');
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
            $cold
        );
        $this->assertSame($cold, $this->products->categories(), 'warm call returns the same categories');
    }

    /**
     * Cold path made deterministic: Redis off (so the dev app's shared keys are never read), the file
     * cache pointed at a fresh empty directory, and the reranker sidecar unreachable so the order is the
     * SQL order (price ascending). Everything is restored in tearDown.
     */
    private function isolateCacheAndReranker(): void
    {
        $this->saved = [
            'dir' => $this->cacheProperty('dir')->getValue(),
            'redis' => $this->cacheProperty('redis')->getValue(),
            'redisChecked' => $this->cacheProperty('redisChecked')->getValue(),
            'rerankerUrl' => getenv('RERANKER_URL'),
        ];
        $this->cacheDir = sys_get_temp_dir() . '/shop_cache_test_' . bin2hex(random_bytes(6));
        mkdir($this->cacheDir, 0755, true);
        $this->cacheProperty('dir')->setValue(null, $this->cacheDir);
        $this->cacheProperty('redis')->setValue(null, null);
        $this->cacheProperty('redisChecked')->setValue(null, true);
        putenv('RERANKER_URL=http://127.0.0.1:1');
    }

    private function cacheProperty(string $name): ReflectionProperty
    {
        return new ReflectionProperty(Cache::class, $name);
    }

    private function cacheEntryCount(): int
    {
        $count = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->cacheDir, FilesystemIterator::SKIP_DOTS)) as $file) {
            $count += $file->isFile() ? 1 : 0;
        }
        return $count;
    }

    private function removeDir(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
