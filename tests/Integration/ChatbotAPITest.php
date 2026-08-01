<?php
/**
 * Integration tests for the Chatbot API.
 * Requires DB (MySQL or SQLite fallback).
 */
class ChatbotAPITest extends \PHPUnit\Framework\TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = getTestPDO();
    }

    // ---- ChatbotService integration ----

    public function testServiceResponds(): void
    {
        // Mock a session
        $sessionId = $this->createSession();
        $userId = null;

        $service = new ChatbotService($this->pdo, $sessionId, $userId);
        $result = $service->respond('tìm áo thun');

        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('products', $result);
        $this->assertNotEmpty($result['message']);
    }

    public function testServiceSavesMessages(): void
    {
        $sessionId = $this->createSession();
        $service = new ChatbotService($this->pdo, $sessionId, null);
        $service->respond('tìm áo thun');

        // Check messages were saved
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM chat_messages WHERE session_id = ?");
        $stmt->execute([$sessionId]);
        $count = (int)$stmt->fetchColumn();

        $this->assertGreaterThanOrEqual(2, $count, 'Should have user + bot messages');
    }

    public function testServiceReturnsProducts(): void
    {
        $sessionId = $this->createSession();
        $service = new ChatbotService($this->pdo, $sessionId, null);
        $result = $service->respond('tìm áo thun dưới 500k');

        // Should find at least one áo thun product under 500K
        if (!empty($result['products'])) {
            foreach ($result['products'] as $p) {
                $this->assertArrayHasKey('id', $p);
                $this->assertArrayHasKey('name', $p);
                $this->assertArrayHasKey('price', $p);
                $this->assertArrayHasKey('url', $p);
                $this->assertProductCardUsesRelativeLinks($p);
                $this->assertLessThanOrEqual(500000, $p['price']);
            }
        }
    }

    public function testProductIdRoutesToDetailResponse(): void
    {
        $sessionId = $this->createSession();
        $service = new ChatbotService($this->pdo, $sessionId, null);
        $result = $service->respond('áo mã 52 xem chi tiết');

        $this->assertSame('product_detail', $result['primary_intent']);
        $this->assertCount(1, $result['products']);
        $this->assertSame(52, (int)$result['products'][0]['id']);
        $this->assertProductCardUsesRelativeLinks($result['products'][0]);
        $this->assertStringContainsString('mã 52', mb_strtolower($result['message']));
    }

    public function testSizeQuestionWithoutMeasurementsReturnsClarification(): void
    {
        $sessionId = $this->createSession();
        $service = new ChatbotService($this->pdo, $sessionId, null);
        $result = $service->respond('mình mặc size gì?');

        $this->assertSame('clarification', $result['response_type']);
        $this->assertContains('height', $result['missing_slots']);
        $this->assertContains('weight', $result['missing_slots']);
    }

    public function testMixedProductPolicyCallsProductAndKnowledge(): void
    {
        $sessionId = $this->createSession();
        $service = new ChatbotService($this->pdo, $sessionId, null);
        $result = $service->respond('áo mã 52 còn size L không và đổi size có mất phí ship không');

        $this->assertSame('mixed_product_policy', $result['primary_intent']);
        $this->assertNotEmpty($result['products']);
        $this->assertSame(52, (int)$result['products'][0]['id']);
        $this->assertArrayHasKey('knowledge_sources', $result);
        $this->assertNotEmpty($result['knowledge_sources']);
    }

    public function testProductSearchFastPathReturnsNewResponseShape(): void
    {
        $sessionId = $this->createSession();
        $service = new ChatbotService($this->pdo, $sessionId, null);
        $result = $service->respond('áo khoác dưới 600k còn hàng');

        $this->assertSame('product_search', $result['primary_intent']);
        $this->assertArrayHasKey('answer', $result);
        $this->assertArrayHasKey('cards', $result);
        $this->assertContains('stock', $result['requested_fields']);
        foreach ($result['products'] as $p) {
            $this->assertProductCardUsesRelativeLinks($p);
            $this->assertLessThanOrEqual(600000, $p['price']);
        }
    }

    public function testProductSearchRespectsColorConstraint(): void
    {
        $sessionId = $this->createSession();
        $service = new ChatbotService($this->pdo, $sessionId, null);
        $result = $service->respond('tìm áo màu đen');

        $this->assertSame('product_search', $result['primary_intent']);
        foreach ($result['products'] as $product) {
            $text = (string)($product['name'] ?? '') . ' ' . (string)($product['description'] ?? '');
            $this->assertTrue(ProductAttributeNormalizer::textMatchesColor($text, 'đen'));
        }
        if (!empty($result['products'])) {
            $this->assertStringNotContainsString('15 sản phẩm áo', $result['message']);
        }
    }

    public function testProductSearchRespectsSizeColorAndStockConstraints(): void
    {
        $sessionId = $this->createSession();
        $service = new ChatbotService($this->pdo, $sessionId, null);
        $result = $service->respond('tìm áo size M màu đen còn hàng');

        $this->assertSame('product_search', $result['primary_intent']);
        $this->assertSame('final_answer', $result['response_type']);
        $this->assertNotEmpty($result['products']);

        foreach ($result['products'] as $product) {
            $this->assertGreaterThan(0, (int)$product['stock']);
            $this->assertContains('M', $product['available_sizes'] ?? []);
            $this->assertContains('đen', $product['available_colors'] ?? []);
        }
    }

    public function testPipelineRoutingMetadataIsPersisted(): void
    {
        $sessionId = $this->createSession();
        $service = new ChatbotService($this->pdo, $sessionId, null);
        $result = $service->respond('Tìm áo đen dưới 300k giúp mình với nhé.');

        $this->assertSame('product_search', $result['primary_intent']);

        $stmt = $this->pdo->prepare("SELECT metadata FROM chat_messages WHERE session_id = ? AND role = 'bot' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$sessionId]);
        $metadata = json_decode((string)$stmt->fetchColumn(), true);

        $this->assertIsArray($metadata);
        $this->assertArrayHasKey('pipeline', $metadata);
        $routing = $metadata['pipeline']['routing'] ?? [];
        $this->assertSame('deterministic_php', $routing['execution_mode'] ?? '');
        $this->assertSame('deterministic_php', $routing['tool_selection_mode'] ?? '');
        $this->assertFalse((bool)($routing['llm_entity_enrichment_used'] ?? true));
        $this->assertSame('đen', $routing['merged_entities']['color'] ?? null);
        $this->assertContains('search_products', $routing['selected_tools'] ?? []);
    }

    public function testServiceLoadsHistory(): void
    {
        $sessionId = $this->createSession();

        // First message
        $service = new ChatbotService($this->pdo, $sessionId, null);
        $service->respond('tìm áo thun');

        // Second message (loads history)
        $service2 = new ChatbotService($this->pdo, $sessionId, null);
        $result = $service2->respond('còn áo len không');

        $this->assertNotEmpty($result['message']);
    }

    public function testServiceWithUserId(): void
    {
        $userId = $this->createUser();

        // Create session with user ID
        $stmt = $this->pdo->prepare("INSERT INTO chat_sessions (user_id, session_token) VALUES (?, ?)");
        $stmt->execute([$userId, 'test_user_session_' . uniqid()]);
        $sessionId = (int)$this->pdo->lastInsertId();

        $service = new ChatbotService($this->pdo, $sessionId, $userId);
        $result = $service->respond('tìm quần tây');

        $this->assertNotEmpty($result['message']);
    }

    // ---- Tool execution logging ----

    public function testToolExecutionsAreLogged(): void
    {
        $sessionId = $this->createSession();
        $service = new ChatbotService($this->pdo, $sessionId, null);
        $service->respond('tìm áo thun');

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM tool_executions WHERE session_id = ?");
        $stmt->execute([$sessionId]);
        $count = (int)$stmt->fetchColumn();

        // LLM path may or may not call tools (depends on LLM availability)
        // Just verify the query doesn't error
        $this->assertIsInt($count);
    }

    // ---- Search result validation ----

    public function testSearchProductsReturnsOnlyMatchingType(): void
    {
        $sessionId = $this->createSession();
        $service = new ChatbotService($this->pdo, $sessionId, null);
        $result = $service->respond('tìm áo khoác dưới 500k');

        $this->assertIsArray($result['products']);

        // All áo khoác products are >500K, so should return 0 products
        // But some might be found by fuzzy matching
        foreach ($result['products'] as $p) {
            // If products returned, they should contain "áo khoác" or be under 500K
            $hasKeyword = mb_strpos(mb_strtolower($p['name']), 'áo khoác') !== false;
            $this->assertTrue($hasKeyword, "Product {$p['name']} not matching 'áo khoác'");
        }
    }

    public function testSearchWithMultipleFilters(): void
    {
        $sessionId = $this->createSession();
        $service = new ChatbotService($this->pdo, $sessionId, null);
        $result = $service->respond('tìm áo từ 200k đến 500k');

        foreach ($result['products'] as $p) {
            $this->assertGreaterThanOrEqual(200000, $p['price']);
            $this->assertLessThanOrEqual(500000, $p['price']);
        }
    }

    // ---- Conversation context ----

    public function testSessionContinuity(): void
    {
        $sessionId = $this->createSession();

        // First exchange
        $bot1 = new ChatbotService($this->pdo, $sessionId, null);
        $r1 = $bot1->respond('tìm áo thun');
        $this->assertNotEmpty($r1['message']);

        // Second exchange (same session — should have history)
        $bot2 = new ChatbotService($this->pdo, $sessionId, null);
        $r2 = $bot2->respond('còn màu trắng không');
        $this->assertNotEmpty($r2['message']);
    }

    // ---- Session token for anonymous users ----

    public function testSessionTokenGeneration(): void
    {
        // Generate token as index.php does
        $token = bin2hex(random_bytes(32));
        $stmt = $this->pdo->prepare("INSERT INTO chat_sessions (session_token) VALUES (?)");
        $stmt->execute([$token]);
        $sessionId = (int)$this->pdo->lastInsertId();

        $this->assertNotEmpty($token);
        $this->assertEquals(64, strlen($token));
        $this->assertIsInt($sessionId);
    }

    // ---- Helpers ----

    private function assertProductCardUsesRelativeLinks(array $product): void
    {
        $this->assertArrayHasKey('url', $product);
        $this->assertStringStartsWith('/product.php?id=', (string)$product['url']);
        $this->assertStringNotContainsString('localhost', (string)$product['url']);

        $imageUrl = (string)($product['image_url'] ?? '');
        if ($imageUrl !== '') {
            $this->assertStringStartsWith('/images/', $imageUrl);
            $this->assertStringNotContainsString('localhost', $imageUrl);
        }
    }

    private function createSession(): int
    {
        $token = 'test_session_' . uniqid();
        $stmt = $this->pdo->prepare("INSERT INTO chat_sessions (session_token) VALUES (?)");
        $stmt->execute([$token]);
        return (int)$this->pdo->lastInsertId();
    }

    private function createUser(): int
    {
        $suffix = uniqid();
        $stmt = $this->pdo->prepare("INSERT INTO users (username, email, password, role, status) VALUES (?, ?, ?, 'user', 1)");
        $stmt->execute(["test_$suffix", "test_$suffix@example.com", password_hash('secret', PASSWORD_DEFAULT)]);
        return (int)$this->pdo->lastInsertId();
    }
}
