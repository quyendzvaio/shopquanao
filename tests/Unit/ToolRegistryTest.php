<?php
/**
 * Tests for tool execution + reranker integration.
 * Uses SQLite in-memory for test isolation.
 * Reranker calls are mocked (no Python sidecar needed).
 */
class ToolRegistryTest extends \PHPUnit\Framework\TestCase
{
    private PDO $pdo;
    private ToolRegistry $registry;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->initSchema();
        $this->seedData();
        $this->registry = new ToolRegistry($this->pdo);
    }

    private function initSchema(): void
    {
        $this->pdo->exec("CREATE TABLE products (
            id INTEGER PRIMARY KEY,
            category_id INTEGER,
            subcategory_id INTEGER,
            name TEXT NOT NULL,
            price REAL NOT NULL,
            stock INTEGER DEFAULT 0,
            description TEXT,
            image TEXT
        )");
        $this->pdo->exec("CREATE TABLE categories (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            canonical_key TEXT NOT NULL,
            family TEXT NOT NULL
        )");
        $this->pdo->exec("CREATE TABLE product_subcategories (
            id INTEGER PRIMARY KEY, category_id INTEGER NOT NULL,
            canonical_key TEXT NOT NULL, display_name TEXT NOT NULL
        )");
        $this->pdo->exec("CREATE TABLE colors (
            id INTEGER PRIMARY KEY, canonical_key TEXT NOT NULL,
            display_name TEXT NOT NULL, external_code TEXT
        )");
        $this->pdo->exec("CREATE TABLE product_variants (
            id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL,
            variant_key TEXT NOT NULL, sku TEXT, color_id INTEGER, size TEXT,
            price REAL, stock INTEGER, is_active INTEGER NOT NULL DEFAULT 1
        )");
        $this->pdo->exec("CREATE TABLE cart (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            quantity INTEGER DEFAULT 1,
            size TEXT DEFAULT 'S'
        )");
        $this->pdo->exec("CREATE TABLE faqs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            question TEXT NOT NULL,
            answer TEXT NOT NULL,
            category TEXT DEFAULT 'general',
            priority INTEGER DEFAULT 0
        )");
        $this->pdo->exec("CREATE TABLE size_guides (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER,
            category_id INTEGER,
            size_name TEXT NOT NULL,
            height_from INTEGER,
            height_to INTEGER,
            weight_from INTEGER,
            weight_to INTEGER,
            description TEXT
        )");
        $this->pdo->exec("CREATE TABLE product_sizes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER,
            size_name TEXT
        )");
        $this->pdo->exec("CREATE TABLE orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            total_price REAL,
            status TEXT DEFAULT 'Pending',
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
    }

    private function seedData(): void
    {
        $this->pdo->exec("INSERT INTO categories VALUES
            (1, 'Áo', 'tops', 'apparel'), (2, 'Quần', 'bottoms', 'apparel'),
            (3, 'Váy & Đầm', 'dresses_skirts', 'apparel')");
        $this->pdo->exec("INSERT INTO products
            (id, category_id, name, price, stock, description, image) VALUES
            (50, 1, 'Áo Thun Cotton Basic Trắng', 180000, 10, 'Cotton 100%', 'at_01.jpg'),
            (51, 1, 'Áo Sơ Mi Linen Xanh', 320000, 5, 'Linen', 'asm_01.jpg'),
            (52, 1, 'Áo Khoác Bomber Kaki Đen', 550000, 12, 'Bomber', 'ak_01.jpg'),
            (63, 1, 'Áo Sơ Mi Caro Đỏ Đen', 350000, 1, 'Flanel caro đỏ đen', 'asm_02.jpg'),
            (53, 1, 'Áo Len Cổ Tròn Xám', 415000, 8, 'Len', 'al_01.jpg'),
            (54, 1, 'Áo Polo Thể Thao Đỏ', 290000, 3, 'Polo', 'ap_01.jpg'),
            (58, 1, 'Áo Thun Graphic Phối Màu', 210000, 15, 'Graphic', 'atg_01.jpg'),
            (65, 2, 'Quần Jeans Slimfit Xanh', 690000, 5, 'Jeans', 'qj_01.jpg'),
            (75, 3, 'Váy Maxi Voan Hồng', 850000, 6, 'Maxi', 'vm_01.jpg')
        ");
        foreach ([50, 51, 52, 53, 54, 58, 63, 65, 75] as $productId) {
            foreach (['S', 'M', 'L', 'XL'] as $size) {
                $stmt = $this->pdo->prepare("INSERT INTO product_sizes (product_id, size_name) VALUES (?, ?)");
                $stmt->execute([$productId, $size]);
            }
        }
        $this->pdo->exec("INSERT INTO faqs (question, answer, category, priority) VALUES
            ('Có đổi trả được không?', 'Đổi trả trong 7 ngày nếu sản phẩm còn nguyên tem mác.', 'return', 1),
            ('Phí ship thế nào?', 'Miễn phí ship đơn từ 500,000đ.', 'shipping', 1)
        ");
        $this->pdo->exec("INSERT INTO orders (id, user_id, total_price, status, created_at) VALUES
            (1, 123, 180000, 'Đang giao', '2026-07-01 10:00:00')
        ");
    }

    public function testGetDefinitionsReturnsArray(): void
    {
        $defs = $this->registry->getDefinitions();
        $this->assertIsArray($defs);
        $this->assertGreaterThanOrEqual(3, count($defs));
    }

    public function testRemovedSalesToolsAreNotExposed(): void
    {
        $names = array_map(fn($tool) => $tool['function']['name'], $this->registry->getDefinitions());

        $this->assertNotContains('get_outfit', $names);
        $this->assertNotContains('prepare_checkout', $names);
        $this->assertNotContains('get_faq', $names);
        $this->assertContains('retrieve_knowledge', $names);
        $this->assertContains('search_products', $names);
        $this->assertContains('get_product_detail', $names);
        $this->assertContains('suggest_size', $names);
        $this->assertContains('get_order_status', $names);
    }

    public function testExecuteSearchProductsWithSearchReturnsProducts(): void
    {
        $result = $this->registry->execute('search_products', ['search' => 'áo']);
        $this->assertArrayHasKey('products', $result);
        $this->assertGreaterThanOrEqual(5, count($result['products']));
    }

    public function testSearchBomberFindsBomberJacket(): void
    {
        $result = $this->registry->execute('search_products', ['search' => 'áo bomber']);
        $this->assertArrayHasKey('products', $result);
        $ids = array_map(fn($p) => (int)$p['id'], $result['products']);
        $this->assertContains(52, $ids);
    }

    public function testExecuteUnknownToolThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->registry->execute('nonexistent_tool', []);
    }

    public function testGetCategoriesReturnsList(): void
    {
        $result = $this->registry->execute('get_categories', []);
        $this->assertArrayHasKey('categories', $result);
    }

    public function testSearchProductsFiltersByPrice(): void
    {
        $result = $this->registry->execute('search_products', [
            'search' => 'áo',
            'min_price' => 300000,
            'max_price' => 500000,
        ]);
        $this->assertArrayHasKey('products', $result);
        foreach ($result['products'] as $p) {
            $this->assertGreaterThanOrEqual(300000, $p['price']);
            $this->assertLessThanOrEqual(500000, $p['price']);
        }
    }

    public function testSearchProductsFiltersByVietnameseCanonicalColor(): void
    {
        $result = $this->registry->execute('search_products', [
            'search' => 'áo',
            'category_id' => 1,
            'color' => 'black',
        ]);

        $this->assertNotEmpty($result['products']);
        foreach ($result['products'] as $product) {
            $this->assertSame(1, (int)$product['category_id']);
            $this->assertTrue(ProductAttributeNormalizer::textMatchesColor($product['name'] . ' ' . $product['description'], 'đen'));
        }
    }

    public function testSearchProductsCombinesColorAndPrice(): void
    {
        $result = $this->registry->execute('search_products', [
            'search' => 'áo',
            'category_id' => 1,
            'color' => 'đen',
            'max_price' => 500000,
        ]);

        $ids = array_map(fn($p) => (int)$p['id'], $result['products']);
        $this->assertSame([63], $ids);
    }

    public function testSearchProductsCombinesColorSizeAndStock(): void
    {
        $result = $this->registry->execute('search_products', [
            'search' => 'áo',
            'category_id' => 1,
            'color' => 'den',
            'size' => 'm',
            'in_stock' => true,
        ]);

        $this->assertNotEmpty($result['products']);
        foreach ($result['products'] as $product) {
            $this->assertGreaterThan(0, (int)$product['stock']);
            $this->assertContains('M', ProductAttributeNormalizer::productSizes($product));
            $this->assertTrue(ProductAttributeNormalizer::textMatchesColor($product['name'] . ' ' . $product['description'], 'đen'));
        }
    }

    public function testSearchProductsReturnsEmptyForMissingColor(): void
    {
        $result = $this->registry->execute('search_products', [
            'search' => 'áo',
            'category_id' => 1,
            'color' => 'tím',
        ]);

        $this->assertSame([], $result['products']);
        $this->assertSame(0, (int)$result['pagination']['total']);
    }

    public function testRemovedSalesToolsThrowUnknownTool(): void
    {
        foreach (['get_outfit', 'prepare_checkout', 'get_faq'] as $toolName) {
            try {
                $this->registry->execute($toolName, []);
                $this->fail("$toolName should not be executable");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Unknown tool', $e->getMessage());
            }
        }
    }

    public function testRetrieveKnowledgeFindsPolicy(): void
    {
        $result = $this->registry->execute('retrieve_knowledge', [
            'query' => 'đổi trả trong bao lâu',
            'category' => 'return',
        ]);

        $this->assertArrayHasKey('results', $result);
        $this->assertNotEmpty($result['results']);
        $joined = mb_strtolower(implode(' ', array_map(fn($r) => $r['content'], $result['results'])));
        $this->assertStringContainsString('7 ngày', $joined);
    }

    public function testGetOrderStatusRequiresLogin(): void
    {
        $result = $this->registry->execute('get_order_status', []);
        $this->assertTrue($result['requires_login']);
    }

    public function testGetOrderStatusReturnsRecentOrders(): void
    {
        $registry = new ToolRegistry($this->pdo, 123);
        $result = $registry->execute('get_order_status', []);
        $this->assertArrayHasKey('orders', $result);
        $this->assertSame(1, (int)$result['orders'][0]['id']);
        $this->assertSame('Đang giao', $result['orders'][0]['status']);
    }
}
