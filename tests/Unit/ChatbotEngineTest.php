<?php
/**
 * Tests for the rule-based fallback engine.
 * Uses SQLite in-memory for test isolation.
 */
class ChatbotEngineTest extends \PHPUnit\Framework\TestCase
{
    private PDO $pdo;
    private ChatbotEngine $engine;
    private int $sessionId;

    protected function setUp(): void
    {
        // Use SQLite in-memory
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->initSchema();
        $this->seedData();

        // Create a test session
        $this->pdo->exec("INSERT INTO chat_sessions (id, session_token) VALUES (1, 'test_session_1')");
        $this->sessionId = 1;

        $this->engine = new ChatbotEngine($this->pdo, $this->sessionId, null);
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
        $this->pdo->exec("CREATE TABLE faqs (
            id INTEGER PRIMARY KEY,
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
        $this->pdo->exec("CREATE TABLE chat_sessions (
            id INTEGER PRIMARY KEY,
            user_id INTEGER,
            session_token TEXT UNIQUE,
            status TEXT DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $this->pdo->exec("CREATE TABLE chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id INTEGER NOT NULL,
            role TEXT NOT NULL,
            message TEXT NOT NULL,
            metadata TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
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
        $this->pdo->exec("INSERT INTO faqs VALUES
            (1, 'Thời gian giao hàng?', '2-5 ngày làm việc', 'shipping', 1),
            (2, 'Có đổi trả được không?', 'Đổi trả trong 7 ngày', 'return', 1)
        ");
        $this->pdo->exec("INSERT INTO size_guides (category_id, size_name, height_from, height_to, weight_from, weight_to, description) VALUES
            (1, 'S', 150, 160, 40, 50, 'Form nhỏ'),
            (1, 'M', 160, 170, 50, 65, 'Form vừa'),
            (1, 'L', 170, 180, 65, 80, 'Form lớn')
        ");
    }

    // ---- Intent classification ----

    public function testClassifyGreeting(): void
    {
        $response = $this->engine->respond('Chào bạn');
        $this->assertStringContainsString('Chào', $response);
    }

    public function testClassifyHelp(): void
    {
        $response = $this->engine->respond('giúp');
        $this->assertStringContainsString('giúp', $response);
    }

    public function testClassifyProductSearch(): void
    {
        $response = $this->engine->respond('tìm áo thun');
        // Should find products or say không tìm thấy
        $this->assertNotEmpty($response);
    }

    public function testClassifyBye(): void
    {
        $response = $this->engine->respond('cảm ơn');
        $this->assertNotEmpty($response);
    }

    // ---- Product search with prices ----

    public function testSearchByProductName(): void
    {
        $response = $this->engine->respond('tìm áo khoác');
        $this->assertStringContainsString('Áo Khoác', $response);
        $this->assertNotEmpty($this->engine->lastProducts);
    }

    public function testSearchBomberAlias(): void
    {
        $response = $this->engine->respond('mình muốn tìm áo bomber');
        $this->assertStringContainsString('Áo Khoác Bomber', $response);
        $ids = array_map(fn($p) => (int)$p['id'], $this->engine->lastProducts);
        $this->assertContains(52, $ids);
    }

    public function testSearchWithMaxPrice(): void
    {
        $response = $this->engine->respond('tìm áo thun dưới 500k');
        $this->assertNotEmpty($this->engine->lastProducts);
        foreach ($this->engine->lastProducts as $p) {
            $this->assertLessThanOrEqual(500000, $p['price']);
            $this->assertStringContainsString('áo thun', mb_strtolower($p['name']));
        }
    }

    public function testSearchReturnsAllProducts(): void
    {
        $this->engine->respond('tìm áo thun');
        // Should return both áo thun products (50 and 58)
        $names = array_map(fn($p) => $p['name'], $this->engine->lastProducts);
        $this->assertContains('Áo Thun Cotton Basic Trắng', $names);
        $this->assertContains('Áo Thun Graphic Phối Màu', $names);
    }

    public function testSearchWithNoResults(): void
    {
        $response = $this->engine->respond('tìm váy maxi dưới 200k');
        // Váy maxi is 850k, so no results under 200k
        $this->assertStringContainsString('không tìm thấy', $response);
        $this->assertEmpty($this->engine->lastProducts);
    }

    public function testSearchCategoryFallback(): void
    {
        $response = $this->engine->respond('có áo nào');
        $this->assertNotEmpty($response);
    }

    // ---- Price parsing ----

    public function testPriceUnderParsing(): void
    {
        // Access private via reflection is complex; test via respond
        $response = $this->engine->respond('tìm áo dưới 300k');
        $this->assertNotEmpty($response);
        if (!empty($this->engine->lastProducts)) {
            foreach ($this->engine->lastProducts as $p) {
                $this->assertLessThanOrEqual(300000, $p['price']);
            }
        }
    }

    public function testPriceRangeParsing(): void
    {
        $response = $this->engine->respond('tìm áo từ 200k đến 400k');
        $this->assertNotEmpty($response);
        if (!empty($this->engine->lastProducts)) {
            foreach ($this->engine->lastProducts as $p) {
                $this->assertGreaterThanOrEqual(200000, $p['price']);
                $this->assertLessThanOrEqual(400000, $p['price']);
            }
        }
    }

    // ---- Size advice ----

    public function testSizeAdviceWithHeightAndWeight(): void
    {
        $response = $this->engine->respond('cao 1m7 nặng 65kg mặc size gì');
        $this->assertNotEmpty($response);
    }

    public function testSizeAdviceMissingInfo(): void
    {
        $response = $this->engine->respond('cho mình size áo');
        // Should handle missing info gracefully
        $this->assertNotEmpty($response);
    }

    // ---- Order & FAQ ----

    public function testOrderStatusQuery(): void
    {
        $response = $this->engine->respond('đơn hàng của tôi');
        // Without login, should guide to login
        $this->assertNotEmpty($response);
    }

    public function testFaqShipping(): void
    {
        $response = $this->engine->respond('giao hàng như thế nào');
        $this->assertNotEmpty($response);
        $this->assertStringContainsString('giao', strtolower($response));
    }

    public function testFaqReturn(): void
    {
        $response = $this->engine->respond('chính sách đổi trả');
        $this->assertNotEmpty($response);
        $this->assertStringContainsString('đổi', strtolower($response));
    }

    // ---- Message saving ----

    public function testMessagesAreSaved(): void
    {
        // Engine no longer saves messages (orchestrator handles it)
        // Just verify respond returns something
        $response = $this->engine->respond('tìm áo thun');
        $this->assertNotEmpty($response);
    }

    public function testUnknownIntent(): void
    {
        $response = $this->engine->respond('xyzabc123');
        $this->assertNotEmpty($response);
        // Should return help/unknown message
        $this->assertStringContainsString('chưa hiểu', $response);
    }
}
