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
            name TEXT NOT NULL,
            price REAL NOT NULL,
            stock INTEGER DEFAULT 0,
            description TEXT,
            image TEXT
        )");
        $this->pdo->exec("CREATE TABLE categories (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL
        )");
        $this->pdo->exec("CREATE TABLE cart (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            quantity INTEGER DEFAULT 1,
            size TEXT DEFAULT 'S'
        )");
    }

    private function seedData(): void
    {
        $this->pdo->exec("INSERT INTO categories VALUES (1, 'Áo'), (2, 'Quần'), (3, 'Váy & Đầm')");
        $this->pdo->exec("INSERT INTO products VALUES
            (50, 1, 'Áo Thun Cotton Basic Trắng', 180000, 10, 'Cotton 100%', 'at_01.jpg'),
            (51, 1, 'Áo Sơ Mi Linen Xanh', 320000, 5, 'Linen', 'asm_01.jpg'),
            (52, 1, 'Áo Khoác Bomber Kaki Đen', 550000, 12, 'Bomber', 'ak_01.jpg'),
            (53, 1, 'Áo Len Cổ Tròn Xám', 415000, 8, 'Len', 'al_01.jpg'),
            (54, 1, 'Áo Polo Thể Thao Đỏ', 290000, 3, 'Polo', 'ap_01.jpg'),
            (58, 1, 'Áo Thun Graphic Phối Màu', 210000, 15, 'Graphic', 'atg_01.jpg'),
            (65, 2, 'Quần Jeans Slimfit Xanh', 690000, 5, 'Jeans', 'qj_01.jpg'),
            (75, 3, 'Váy Maxi Voan Hồng', 850000, 6, 'Maxi', 'vm_01.jpg')
        ");
    }

    public function testGetDefinitionsReturnsArray(): void
    {
        $defs = $this->registry->getDefinitions();
        $this->assertIsArray($defs);
        $this->assertGreaterThanOrEqual(3, count($defs));
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

    public function testPrepareCheckoutRequiresLogin(): void
    {
        $result = $this->registry->execute('prepare_checkout', ['product_ids' => [50]]);
        $this->assertTrue($result['requires_login']);
    }

    public function testPrepareCheckoutAddsProductsToCart(): void
    {
        $registry = new ToolRegistry($this->pdo, 123);
        $result = $registry->execute('prepare_checkout', [
            'product_ids' => [50],
            'quantity' => 2,
            'size' => 'M',
        ]);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('redirect_url', $result);

        $stmt = $this->pdo->prepare("SELECT product_id, quantity, size FROM cart WHERE user_id = ?");
        $stmt->execute([123]);
        $cartItem = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals(50, (int)$cartItem['product_id']);
        $this->assertEquals(2, (int)$cartItem['quantity']);
        $this->assertEquals('M', $cartItem['size']);
    }
}
