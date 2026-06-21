<?php
/**
 * Agentic Orchestrator
 * LLM + Tool Registry → response (text + products data)
 * - Loads conversation history from DB for context
 * - Enhanced system prompt for precise keyword extraction
 * - Fallback: rule-based engine khi LLM không available
 * - Tự lưu chat messages vào DB
 */
require_once __DIR__ . '/../../cache/Cache.php';
require_once __DIR__ . '/ToolRegistry.php';
require_once __DIR__ . '/llm/LLMFactory.php';
require_once __DIR__ . '/engine.php';

class AgenticOrchestrator {
    private PDO $pdo;
    private int $sessionId;
    private ?int $userId;
    private ?LLMProvider $llm = null;
    private ToolRegistry $toolRegistry;
    private ChatbotEngine $fallbackEngine;
    private array $messages = [];
    private int $maxTurns = 3;
    private array $collectedProducts = [];

    private const SYSTEM_PROMPT = <<<'PROMPT'
Bạn là nhân viên tư vấn bán hàng của Fashion Shop - cửa hàng thời trang online bán áo, quần, váy đầm, phụ kiện.

PHONG CÁCH NÓI CHUYỆN:
- Tự nhiên, thân thiện, như nhân viên bán hàng ngoài đời thực
- Xưng hô: "mình" (hoặc "em"), gọi khách là "bạn"
- Ngắn gọn, đi vào vấn đề, không lan man

QUY TẮC TƯ VẤN - TUÂN THỦ NGHIÊM NGẶT:
1. Khi user hỏi sản phẩm → LẬP TỨC dùng search_products. QUAN TRỌNG: phải trích xuất CHÍNH XÁC tên loại sản phẩm từ câu hỏi.
   - VD: "áo khoác dưới 500k" → search="áo khoác", max_price=500000 (KHÔNG search="áo")
   - VD: "áo thun trắng" → search="áo thun" (KHÔNG search="áo")
   - VD: "áo gile" → search="áo gile" (KHÔNG search="áo")
   - VD: "áo polo" → search="áo polo" (KHÔNG search="áo")
   - VD: "áo len cổ tròn" → search="áo len" (KHÔNG search="áo")
   - VD: "quần jeans ống rộng" → search="quần jeans" (KHÔNG search="quần")
   - VD: "váy maxi hoa" → search="váy maxi" (KHÔNG search="váy")
   LUÔN dùng từ khóa ĐẦY ĐỦ và CHÍNH XÁC nhất, không dùng từ khóa chung chung.
   Nếu không chắc chắn loại áo, hãy dùng đúng cụm từ user nói.

2. Khi search_products trả về kết quả → HIỂN THỊ TẤT CẢ sản phẩm phù hợp, không giới hạn số lượng. Nếu không có kết quả → nói rất tiếc shop chưa có sản phẩm phù hợp và gợi ý từ khóa khác.

3. Khi user chưa nói rõ (chỉ nói "giới thiệu áo" "có áo nào đẹp không") → CHỦ ĐỘNG hỏi lại: bạn cần áo phong cách gì? form ôm hay rộng? giá tầm bao nhiêu?

4. Khi user hỏi size → dùng suggest_size. Nếu thiếu chiều cao/cân nặng → hỏi luôn

5. Khi user hỏi phối đồ → dùng get_outfit

6. Khi user hỏi chính sách → dùng get_faq

7. Khi user hỏi sản phẩm: LUÔN gọi search_products, KHÔNG dùng lại kết quả từ lịch sử trò chuyện. Mỗi câu hỏi là một yêu cầu mới.

8. Khi user hỏi đơn hàng → hướng dẫn vào Đơn hàng của tôi trong trang cá nhân

9. Nếu không hiểu câu hỏi → hỏi lại lịch sự

10. Luôn để link sản phẩm dạng: https://localhost:8090/product.php?id=XX
PROMPT;

    public function __construct(PDO $pdo, int $sessionId, ?int $userId) {
        $this->pdo = $pdo;
        $this->sessionId = $sessionId;
        $this->userId = $userId;
        $this->toolRegistry = new ToolRegistry($pdo);
        $this->fallbackEngine = new ChatbotEngine($pdo, $sessionId, $userId);
        $this->llm = LLMFactory::fromEnv();
    }

    public function respond(string $message): array {
        $this->collectedProducts = [];

        if ($this->llm === null) {
            $text = $this->fallbackEngine->respond($message);
            $this->collectedProducts = $this->fallbackEngine->lastProducts ?? [];
            $this->saveMessages($message, $text, $this->collectedProducts);
            return $this->buildResponse($text);
        }

        try {
            $result = $this->processWithLLM($message);
            $this->saveMessages($message, $result['message'], $result['products']);
            return $result;
        } catch (Throwable $e) {
            error_log("LLM error: " . $e->getMessage());
            $text = $this->fallbackEngine->respond($message);
            $this->collectedProducts = $this->fallbackEngine->lastProducts ?? [];
            $this->saveMessages($message, $text, $this->collectedProducts);
            return $this->buildResponse($text);
        }
    }

    /**
     * Load previous conversation history from DB.
     */
    private function loadHistory(): array {
        $history = [];
        try {
            // Always load fresh from DB — no cache to avoid stale context
            $stmt = $this->pdo->prepare(
                "SELECT role, message FROM chat_messages 
                 WHERE session_id = ? 
                 ORDER BY id ASC 
                 LIMIT 20"
            );
            $stmt->execute([$this->sessionId]);
            $rows = $stmt->fetchAll();
            foreach ($rows as $r) {
                $history[] = [
                    'role' => $r['role'] === 'user' ? 'user' : 'assistant',
                    'content' => $r['message'],
                ];
            }
        } catch (Throwable $e) {
            error_log("History load error: " . $e->getMessage());
        }
        return $history;
    }

    /**
     * Save user + bot messages to DB.
     */
    private function saveMessages(string $userMsg, string $botMsg, array $products): void {
        try {
            $stmt = $this->pdo->prepare("INSERT INTO chat_messages (session_id, role, message, metadata) VALUES (?, 'user', ?, ?)");
            $stmt->execute([$this->sessionId, $userMsg, json_encode(['orchestrator' => 'agentic'], JSON_UNESCAPED_UNICODE)]);

            $meta = !empty($products) ? json_encode(['products' => $products], JSON_UNESCAPED_UNICODE) : null;
            $stmt = $this->pdo->prepare("INSERT INTO chat_messages (session_id, role, message, metadata) VALUES (?, 'bot', ?, ?)");
            $stmt->execute([$this->sessionId, $botMsg, $meta]);

            $this->pdo->prepare("UPDATE chat_sessions SET updated_at = NOW() WHERE id = ?")->execute([$this->sessionId]);


        } catch (Throwable $e) {
            error_log("Save message error: " . $e->getMessage());
        }
    }

    private function processWithLLM(string $message): array {
        // Build messages with history context
        $this->messages = [['role' => 'system', 'content' => self::SYSTEM_PROMPT]];

        // Load conversation history (excluding current message)
        $history = $this->loadHistory();
        $this->messages = array_merge($this->messages, $history);

        // Add current user message
        $this->messages[] = ['role' => 'user', 'content' => $message];

        $tools = $this->toolRegistry->getDefinitions();

        for ($turn = 0; $turn < $this->maxTurns; $turn++) {
            $response = $this->llm->chat($this->messages, $tools);

            $assistantMsg = ['role' => 'assistant', 'content' => $response->content];
            if ($response->hasToolCalls()) {
                $assistantMsg['tool_calls'] = array_map(fn($tc) => [
                    'id' => $tc->id,
                    'type' => 'function',
                    'function' => ['name' => $tc->name, 'arguments' => json_encode($tc->arguments, JSON_UNESCAPED_UNICODE)],
                ], $response->toolCalls);
            }
            $this->messages[] = $assistantMsg;

            if (!$response->hasToolCalls()) {
                return $this->buildResponse($response->content);
            }

            foreach ($response->toolCalls as $tc) {
                $start = microtime(true);
                $result = [];
                $ok = true;

                try {
                    $result = $this->toolRegistry->execute($tc->name, $tc->arguments);
                    $resultStr = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $this->harvestProducts($tc->name, $result);
                } catch (Throwable $e) {
                    $ok = false;
                    $resultStr = json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
                }

                $this->logToolExecution($tc->name, $tc->arguments, $result, (int)((microtime(true) - $start) * 1000), $ok);

                // Trim tool result if too long to avoid context overflow
                if (mb_strlen($resultStr) > 10000) {
                    $resultStr = mb_substr($resultStr, 0, 10000) . '...[TRUNCATED]';
                }
                $this->messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $tc->id,
                    'content' => $resultStr,
                ];
            }
        }

        return $this->buildResponse("Xin lỗi, mình cần thêm thông tin. Bạn nói rõ hơn được không ạ?");
    }

    private function harvestProducts(string $toolName, array $result): void {
        $baseUrl = getBaseUrl();

        if ($toolName === 'search_products' && isset($result['products'])) {
            foreach ($result['products'] as $p) {
                $pId = (int)($p['id'] ?? 0);
                if ($pId === 0) continue;
                $this->collectedProducts[] = [
                    'id' => $pId,
                    'name' => $p['name'] ?? '',
                    'price' => (float)($p['price'] ?? 0),
                    'stock' => (int)($p['stock'] ?? 0),
                    'image' => $p['image'] ?? '',
                    'image_url' => ($p['image'] ?? '') ? $baseUrl . '/images/' . $p['image'] : '',
                    'url' => $baseUrl . '/product.php?id=' . $pId,
                ];
            }
        }

        if ($toolName === 'get_product_detail' && isset($result['product'])) {
            $p = $result['product'];
            $pId = (int)($p['id'] ?? 0);
            if ($pId === 0) return;
            $this->collectedProducts[] = [
                'id' => $pId,
                'name' => $p['name'] ?? '',
                'price' => (float)($p['price'] ?? 0),
                'stock' => (int)($p['stock'] ?? 0),
                'image' => $p['image'] ?? '',
                'image_url' => ($p['image'] ?? '') ? $baseUrl . '/images/' . $p['image'] : '',
                'url' => $baseUrl . '/product.php?id=' . $pId,
            ];
        }
    }

    private function buildResponse(string $text): array {
        // Safety net: if LLM mentioned products but harvest missed them,
        // extract product IDs from text and fetch from DB
        $products = $this->collectedProducts;
        if (empty($products) && preg_match_all('/product\.php\?id=(\d+)/', $text, $m)) {
            $mentionedIds = array_unique(array_map('intval', $m[1]));
            foreach ($mentionedIds as $pid) {
                try {
                    $stmt = $this->pdo->prepare("SELECT id, name, price, stock, image FROM products WHERE id = ?");
                    $stmt->execute([$pid]);
                    $p = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($p) {
                        $baseUrl = getBaseUrl();
                        $products[] = [
                            'id' => (int)$p['id'],
                            'name' => $p['name'],
                            'price' => (float)$p['price'],
                            'stock' => (int)($p['stock'] ?? 0),
                            'image' => $p['image'] ?? '',
                            'image_url' => ($p['image'] ?? '') ? $baseUrl . '/images/' . $p['image'] : '',
                            'url' => $baseUrl . '/product.php?id=' . (int)$p['id'],
                        ];
                    }
                } catch (Throwable $e) {}
            }
        }
        return ['message' => $text, 'products' => $products];
    }

    private function logToolExecution(string $tool, array $args, $result, int $duration, bool $success): void {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO tool_executions (session_id, tool_name, arguments, result, duration_ms, success, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([$this->sessionId, $tool, json_encode($args, JSON_UNESCAPED_UNICODE),
                is_array($result) ? json_encode($result, JSON_UNESCAPED_UNICODE) : $result, $duration, $success ? 1 : 0]);
        } catch (Throwable $e) {}
    }
}
