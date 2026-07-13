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
require_once __DIR__ . '/ChatbotMemory.php';
require_once __DIR__ . '/evaluator/AgentEvaluator.php';

class AgenticOrchestrator {
    private PDO $pdo;
    private int $sessionId;
    private ?int $userId;
    private ?LLMProvider $llm = null;
    private ToolRegistry $toolRegistry;
    private ChatbotEngine $fallbackEngine;
    private ChatbotMemory $memory;
    private array $messages = [];
    private int $maxTurns = 3;
    private array $collectedProducts = [];
    private array $collectedKnowledgeSources = [];
    private array $toolAttempts = [];
    private array $evaluationMetadata = [];

    private const SYSTEM_PROMPT = <<<'PROMPT'
Bạn là nhân viên tư vấn bán hàng của Fashion Shop - cửa hàng thời trang online bán áo, quần, váy đầm, phụ kiện.

PHONG CÁCH NÓI CHUYỆN:
- Lịch sự, chuyên nghiệp, giống nhân viên tư vấn bán hàng
- Xưng hô: "mình" (hoặc "em"), gọi khách là "bạn"
- Ngắn gọn, đi vào vấn đề, không lan man
- Không dùng emoji, icon trang trí, markdown đậm/nghiêng hoặc ký tự gây rối mắt
- Không chèn URL sản phẩm trong nội dung trả lời; giao diện đã hiển thị thẻ sản phẩm có thể bấm được

QUY TẮC TƯ VẤN - TUÂN THỦ NGHIÊM NGẶT:
1. Khi user hỏi sản phẩm → LẬP TỨC dùng search_products. QUAN TRỌNG: phải trích xuất CHÍNH XÁC tên loại sản phẩm từ câu hỏi.
   - VD: "áo khoác dưới 500k" → search="áo khoác", max_price=500000 (KHÔNG search="áo")
   - VD: "áo bomber" → search="áo khoác bomber" (vì bomber là một loại áo khoác)
   - VD: "áo thun trắng" → search="áo thun" (KHÔNG search="áo")
   - VD: "áo gile" → search="áo gile" (KHÔNG search="áo")
   - VD: "áo polo" → search="áo polo" (KHÔNG search="áo")
   - VD: "áo len cổ tròn" → search="áo len" (KHÔNG search="áo")
   - VD: "quần jeans ống rộng" → search="quần jeans" (KHÔNG search="quần")
   - VD: "váy maxi hoa" → search="váy maxi" (KHÔNG search="váy")
   LUÔN dùng từ khóa ĐẦY ĐỦ và CHÍNH XÁC nhất, không dùng từ khóa chung chung.
   Nếu không chắc chắn loại áo, hãy dùng đúng cụm từ user nói.

2. Khi search_products trả về kết quả → nói ngắn gọn số lượng sản phẩm phù hợp và nhắc khách bấm vào thẻ sản phẩm bên dưới để xem chi tiết. Không liệt kê URL trong nội dung trả lời. Nếu không có kết quả → chỉ nói không có sau khi đã gọi tool; không tự suy đoán từ lịch sử hay kiến thức chung.

3. Khi user chưa nói rõ (chỉ nói "giới thiệu áo" "có áo nào đẹp không") → CHỦ ĐỘNG hỏi lại: bạn cần áo phong cách gì? form ôm hay rộng? giá tầm bao nhiêu?

4. Khi user hỏi size → dùng suggest_size. Nếu thiếu chiều cao/cân nặng → hỏi luôn

5. Hiện chatbot KHÔNG hỗ trợ tư vấn phối đồ/outfit. Nếu user hỏi phối đồ, hãy nói ngắn gọn rằng hiện mình chỉ hỗ trợ tìm sản phẩm, xem chi tiết sản phẩm, tư vấn size và chính sách shop.

6. Khi user hỏi CSKH/chính sách/tri thức shop (đổi trả, hoàn tiền, phí ship, giao hàng, bảo hành, thanh toán, bán sỉ, thông tin cửa hàng, điều kiện áp dụng) → BẮT BUỘC dùng retrieve_knowledge trước khi trả lời. Chỉ trả lời dựa trên đoạn tri thức tool trả về. Nếu dữ liệu chưa đủ, nói rõ là hiện chưa có đủ thông tin trong dữ liệu shop và hỏi thêm.

6b. Với câu hỏi mixed intent vừa có sản phẩm vừa có chính sách, ví dụ "áo bomber này đổi size được không" → có thể gọi cả search_products/get_product_detail và retrieve_knowledge, rồi tổng hợp ngắn gọn.

7. Khi user hỏi sản phẩm: LUÔN gọi search_products, KHÔNG dùng lại kết quả từ lịch sử trò chuyện. Mỗi câu hỏi là một yêu cầu mới.
   Được dùng Slot Memory để bổ sung phần còn thiếu trong câu hiện tại, ví dụ user nói "dưới 300k" sau khi đã nói "áo thun" thì search="áo thun", max_price=300000.

8. Khi user hỏi đơn hàng → hướng dẫn vào Đơn hàng của tôi trong trang cá nhân

8b. Khi user hỏi trạng thái đơn hàng cá nhân hoặc "đơn của tôi" → dùng get_order_status. Nếu tool yêu cầu đăng nhập thì yêu cầu user đăng nhập.

9. Chatbot KHÔNG thêm giỏ hàng, KHÔNG chuẩn bị checkout, KHÔNG chuyển trang thanh toán. Khi user muốn mua/thanh toán, hãy hướng dẫn họ bấm vào thẻ sản phẩm hoặc vào trang chi tiết sản phẩm để tự thêm giỏ hàng/thanh toán.

10. Nếu không hiểu câu hỏi → hỏi lại lịch sự

11. Tuyệt đối không đưa link localhost, URL raw hoặc emoji vào câu trả lời.

12. Không hiển thị chain-of-thought hoặc các bước suy nghĩ nội bộ. Bạn có thể dùng tool theo mô hình ReAct, nhưng người dùng chỉ thấy câu trả lời cuối cùng.
PROMPT;

    public function __construct(PDO $pdo, int $sessionId, ?int $userId) {
        $this->pdo = $pdo;
        $this->sessionId = $sessionId;
        $this->userId = $userId;
        $this->toolRegistry = new ToolRegistry($pdo, $userId);
        $this->fallbackEngine = new ChatbotEngine($pdo, $sessionId, $userId);
        $this->llm = LLMFactory::fromEnv();
        $this->memory = new ChatbotMemory($pdo, $sessionId, $userId, $this->llm);
        $this->memory->ensureSchema();
    }

    public function respond(string $message): array {
        $this->collectedProducts = [];
        $this->collectedKnowledgeSources = [];
        $this->toolAttempts = [];
        $this->evaluationMetadata = [];
        $memoryContext = $this->memory->rememberUserMessage($message);

        if ($this->llm === null) {
            $this->fallbackEngine = new ChatbotEngine($this->pdo, $this->sessionId, $this->userId, $this->memory->getContextForEngine($memoryContext));
            $text = $this->fallbackEngine->respond($message);
            $this->collectedProducts = $this->fallbackEngine->lastProducts ?? [];
            $this->collectedKnowledgeSources = $this->fallbackEngine->lastKnowledgeSources ?? [];
            $this->saveMessages($message, $text, $this->collectedProducts);
            $this->memory->refreshSummary($message, $text);
            return $this->buildResponse($text);
        }

        try {
            $result = $this->processWithLLM($message, $memoryContext);
            $this->saveMessages($message, $result['message'], $result['products']);
            $this->memory->refreshSummary($message, $result['message']);
            return $result;
        } catch (Throwable $e) {
            error_log("LLM error: " . $e->getMessage());
            $this->fallbackEngine = new ChatbotEngine($this->pdo, $this->sessionId, $this->userId, $this->memory->getContextForEngine($memoryContext));
            $text = $this->fallbackEngine->respond($message);
            $this->collectedProducts = $this->fallbackEngine->lastProducts ?? [];
            $this->collectedKnowledgeSources = $this->fallbackEngine->lastKnowledgeSources ?? [];
            $this->saveMessages($message, $text, $this->collectedProducts);
            $this->memory->refreshSummary($message, $text);
            return $this->buildResponse($text);
        }
    }

    /**
     * Load previous conversation history from DB.
     */
    private function loadHistory(): array {
        $history = [];
        try {
            // Keep only the latest messages; older context is compressed in ChatbotMemory.
            $stmt = $this->pdo->prepare(
                "SELECT role, message FROM chat_messages 
                 WHERE session_id = ? 
                 ORDER BY id DESC 
                 LIMIT 6"
            );
            $stmt->execute([$this->sessionId]);
            $rows = array_reverse($stmt->fetchAll());
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

            $metaData = [];
            if (!empty($products)) $metaData['products'] = $products;
            if (!empty($this->collectedKnowledgeSources)) $metaData['knowledge_sources'] = $this->collectedKnowledgeSources;
            if (!empty($this->evaluationMetadata)) $metaData['evaluation'] = $this->evaluationMetadata;
            $meta = !empty($metaData) ? json_encode($metaData, JSON_UNESCAPED_UNICODE) : null;
            $stmt = $this->pdo->prepare("INSERT INTO chat_messages (session_id, role, message, metadata) VALUES (?, 'bot', ?, ?)");
            $stmt->execute([$this->sessionId, $botMsg, $meta]);

            $this->pdo->prepare("UPDATE chat_sessions SET updated_at = NOW() WHERE id = ?")->execute([$this->sessionId]);


        } catch (Throwable $e) {
            error_log("Save message error: " . $e->getMessage());
        }
    }

    private function processWithLLM(string $message, array $memoryContext): array {
        // Build messages with compressed memory + latest turns.
        $this->messages = [[
            'role' => 'system',
            'content' => self::SYSTEM_PROMPT . "\n\n" . $this->memory->buildPromptBlock($memoryContext),
        ]];

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
                $finalText = $this->evaluateAndRepair($message, $response->content);
                return $this->buildResponse($finalText);
            }

            foreach ($response->toolCalls as $tc) {
                $start = microtime(true);
                $result = [];
                $ok = true;

                try {
                    $result = $this->toolRegistry->execute($tc->name, $tc->arguments);
                    $resultStr = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $this->harvestProducts($tc->name, $result);
                    $this->harvestKnowledgeSources($tc->name, $result);
                } catch (Throwable $e) {
                    $ok = false;
                    $resultStr = json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
                }

                $this->logToolExecution($tc->name, $tc->arguments, $result, (int)((microtime(true) - $start) * 1000), $ok);
                $this->toolAttempts[] = [
                    'tool_name' => $tc->name,
                    'tool_arguments' => $tc->arguments,
                    'tool_result' => $result,
                    'tool_latency_ms' => (int)((microtime(true) - $start) * 1000),
                    'success' => $ok,
                ];

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

    private function evaluateAndRepair(string $userMessage, string $draftAnswer): string {
        $attempt = $this->latestEvaluableAttempt();
        if ($attempt === null) {
            return $draftAnswer;
        }

        $taskType = AgentEvaluator::taskTypeForTool($attempt['tool_name']);
        if ($taskType === null) {
            return $draftAnswer;
        }

        $evaluator = new AgentEvaluator($this->llm);
        $retryState = [
            'total_steps' => 0,
            'tool_retries' => 0,
            'answer_revisions' => 0,
            'query_rewrites' => 0,
        ];
        $evaluation = $this->runEvaluation($evaluator, $taskType, $userMessage, $draftAnswer, $attempt, $retryState);

        if ($evaluation->nextAction === 'return' && $evaluation->passed) {
            return $draftAnswer;
        }
        if ($evaluation->nextAction === 'ask_user' && $evaluation->questionForUser) {
            return $evaluation->questionForUser;
        }
        if ($evaluation->nextAction === 'deny' && $evaluation->safeFallbackMessage) {
            return $evaluation->safeFallbackMessage;
        }

        if ($evaluation->nextAction === 'retry_tool' && empty($attempt['success'])) {
            $retryState['total_steps']++;
            $retryState['tool_retries']++;
            $retryAttempt = $this->retryToolAttempt($attempt);
            if ($retryAttempt !== null && $retryAttempt['success']) {
                $retryDraft = $this->generateAnswerFromRetry($userMessage, $retryAttempt);
                $retryEvaluation = $this->runEvaluation($evaluator, $taskType, $userMessage, $retryDraft, $retryAttempt, $retryState);
                if ($retryEvaluation->nextAction === 'return' && $retryEvaluation->passed) {
                    return $retryDraft;
                }
                return $retryEvaluation->safeFallbackMessage ?: $this->fallbackMessageForTask($taskType);
            }
        }

        if ($evaluation->nextAction === 'revise_answer') {
            $retryState['total_steps']++;
            $retryState['answer_revisions']++;
            $revised = $this->reviseDraftAnswer($userMessage, $draftAnswer, $attempt, $evaluation);
            if ($revised !== '') {
                $recheck = $this->runEvaluation($evaluator, $taskType, $userMessage, $revised, $attempt, $retryState);
                if ($recheck->nextAction === 'return' && $recheck->passed) {
                    return $revised;
                }
                return $recheck->safeFallbackMessage ?: $this->fallbackMessageForTask($taskType);
            }
        }

        return $evaluation->safeFallbackMessage ?: $this->fallbackMessageForTask($taskType);
    }

    private function latestEvaluableAttempt(): ?array {
        for ($i = count($this->toolAttempts) - 1; $i >= 0; $i--) {
            $attempt = $this->toolAttempts[$i];
            if (AgentEvaluator::taskTypeForTool((string)($attempt['tool_name'] ?? '')) !== null) {
                return $attempt;
            }
        }
        return null;
    }

    private function runEvaluation(
        AgentEvaluator $evaluator,
        string $taskType,
        string $userMessage,
        string $draftAnswer,
        array $attempt,
        array $retryState
    ): AgentEvaluationResult {
        $start = microtime(true);
        $evaluation = $evaluator->evaluate([
            'task_type' => $taskType,
            'user_query' => $userMessage,
            'extracted_requirements' => $attempt['tool_arguments'] ?? [],
            'tool_name' => $attempt['tool_name'] ?? '',
            'tool_arguments' => $attempt['tool_arguments'] ?? [],
            'tool_result' => $attempt['tool_result'] ?? [],
            'draft_answer' => $draftAnswer,
            'runtime_context' => [
                'authenticated' => $this->userId !== null,
                'user_id' => $this->userId,
            ],
            'retry_state' => $retryState,
        ]);
        $metadata = $evaluation->toArray();
        $metadata['trace_id'] = bin2hex(random_bytes(8));
        $metadata['attempt'] = ($retryState['total_steps'] ?? 0) + 1;
        $metadata['tool_name'] = $attempt['tool_name'] ?? '';
        $metadata['tool_latency_ms'] = (int)($attempt['tool_latency_ms'] ?? 0);
        $metadata['evaluation_latency_ms'] = (int)((microtime(true) - $start) * 1000);
        $this->evaluationMetadata[] = $metadata;
        $this->logEvaluation($metadata);
        return $evaluation;
    }

    private function retryToolAttempt(array $attempt): ?array {
        $toolName = (string)($attempt['tool_name'] ?? '');
        if ($toolName === '') {
            return null;
        }

        $start = microtime(true);
        $result = [];
        $ok = true;
        try {
            $result = $this->toolRegistry->execute($toolName, $attempt['tool_arguments'] ?? []);
            $this->harvestProducts($toolName, $result);
            $this->harvestKnowledgeSources($toolName, $result);
        } catch (Throwable $e) {
            $ok = false;
            $result = ['error' => $e->getMessage()];
        }

        $duration = (int)((microtime(true) - $start) * 1000);
        $this->logToolExecution($toolName, $attempt['tool_arguments'] ?? [], $result, $duration, $ok);
        $retryAttempt = [
            'tool_name' => $toolName,
            'tool_arguments' => $attempt['tool_arguments'] ?? [],
            'tool_result' => $result,
            'tool_latency_ms' => $duration,
            'success' => $ok,
        ];
        $this->toolAttempts[] = $retryAttempt;
        return $retryAttempt;
    }

    private function generateAnswerFromRetry(string $userMessage, array $attempt): string {
        $result = json_encode($attempt['tool_result'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($result === false) {
            $result = '{}';
        }
        if (mb_strlen($result) > 8000) {
            $result = mb_substr($result, 0, 8000) . '...[TRUNCATED]';
        }

        try {
            $response = $this->llm?->chat([
                [
                    'role' => 'system',
                    'content' => 'Bạn là nhân viên tư vấn Fashion Shop. Trả lời ngắn gọn, chỉ dựa trên tool result, không bịa dữ liệu, không chèn URL raw.',
                ],
                [
                    'role' => 'user',
                    'content' => "Câu hỏi: $userMessage\nTool: {$attempt['tool_name']}\nTool result: $result",
                ],
            ], [], 'none');
            return trim($response?->content ?? '');
        } catch (Throwable $e) {
            error_log('Retry answer generation failed: ' . $e->getMessage());
            return '';
        }
    }

    private function reviseDraftAnswer(
        string $userMessage,
        string $draftAnswer,
        array $attempt,
        AgentEvaluationResult $evaluation
    ): string {
        $result = json_encode($attempt['tool_result'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($result === false) {
            $result = '{}';
        }
        if (mb_strlen($result) > 8000) {
            $result = mb_substr($result, 0, 8000) . '...[TRUNCATED]';
        }

        try {
            $response = $this->llm?->chat([
                [
                    'role' => 'system',
                    'content' => 'Bạn sửa câu trả lời chatbot. Chỉ trả câu trả lời cuối cho khách. Không giải thích nội bộ, không markdown, không URL raw, không bịa dữ liệu ngoài tool result.',
                ],
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'user_query' => $userMessage,
                        'tool_name' => $attempt['tool_name'] ?? '',
                        'tool_result' => $result,
                        'draft_answer' => $draftAnswer,
                        'evaluation_issues' => $evaluation->issues,
                        'revision_instruction' => $evaluation->revisionInstruction,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            ], [], 'none');
            return trim($response?->content ?? '');
        } catch (Throwable $e) {
            error_log('Answer revision failed: ' . $e->getMessage());
            return '';
        }
    }

    private function fallbackMessageForTask(string $taskType): string {
        return match ($taskType) {
            'product_search' => 'Mình chưa tìm thấy sản phẩm đáp ứng đầy đủ các điều kiện hiện tại. Bạn có thể mở rộng khoảng giá hoặc điều chỉnh một tiêu chí tìm kiếm.',
            'product_detail' => 'Mình chưa lấy được đầy đủ thông tin chính xác của sản phẩm này. Vui lòng chọn lại sản phẩm hoặc thử lại sau.',
            'size_advice' => 'Mình chưa đủ dữ liệu để tư vấn size phù hợp. Vui lòng cung cấp chiều cao, cân nặng và sản phẩm bạn đang quan tâm.',
            'order_status' => 'Hiện mình chưa thể xác minh trạng thái đơn hàng. Bạn có thể kiểm tra trong mục Đơn hàng của tôi hoặc thử lại sau.',
            default => 'Mình chưa đủ thông tin để trả lời chính xác. Bạn vui lòng thử lại sau.',
        };
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
                    'stock_status' => ((int)($p['stock'] ?? 0) > 0) ? 'in_stock' : 'out_of_stock',
                    'available_sizes' => [],
                    'available_colors' => [],
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
            $availableSizes = [];
            foreach ($p['sizes'] ?? [] as $size) {
                if (is_array($size) && isset($size['size_name'])) {
                    $availableSizes[] = (string)$size['size_name'];
                } elseif (is_string($size)) {
                    $availableSizes[] = $size;
                }
            }
            $this->collectedProducts[] = [
                'id' => $pId,
                'name' => $p['name'] ?? '',
                'price' => (float)($p['price'] ?? 0),
                'stock' => (int)($p['stock'] ?? 0),
                'stock_status' => ((int)($p['stock'] ?? 0) > 0) ? 'in_stock' : 'out_of_stock',
                'available_sizes' => array_values(array_unique($availableSizes)),
                'available_colors' => [],
                'image' => $p['image'] ?? '',
                'image_url' => ($p['image'] ?? '') ? $baseUrl . '/images/' . $p['image'] : '',
                'url' => $baseUrl . '/product.php?id=' . $pId,
            ];
        }
    }

    private function harvestKnowledgeSources(string $toolName, array $result): void {
        if ($toolName !== 'retrieve_knowledge' || empty($result['results']) || !is_array($result['results'])) {
            return;
        }

        foreach ($result['results'] as $item) {
            $source = [
                'source' => (string)($item['source'] ?? ''),
                'title' => (string)($item['title'] ?? ''),
                'category' => (string)($item['category'] ?? ''),
                'score' => isset($item['score']) ? (float)$item['score'] : null,
            ];
            $key = md5(json_encode($source, JSON_UNESCAPED_UNICODE));
            $this->collectedKnowledgeSources[$key] = $source;
        }
        $this->collectedKnowledgeSources = array_values($this->collectedKnowledgeSources);
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
                            'stock_status' => ((int)($p['stock'] ?? 0) > 0) ? 'in_stock' : 'out_of_stock',
                            'available_sizes' => [],
                            'available_colors' => [],
                            'image' => $p['image'] ?? '',
                            'image_url' => ($p['image'] ?? '') ? $baseUrl . '/images/' . $p['image'] : '',
                            'url' => $baseUrl . '/product.php?id=' . (int)$p['id'],
                        ];
                    }
                } catch (Throwable $e) {}
            }
        }
        $response = ['message' => $text, 'products' => $products];
        if (!empty($this->collectedKnowledgeSources)) {
            $response['knowledge_sources'] = $this->collectedKnowledgeSources;
        }
        return $response;
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

    private function logEvaluation(array $metadata): void {
        $safe = [
            'trace_id' => $metadata['trace_id'] ?? '',
            'task_type' => $metadata['task_type'] ?? '',
            'attempt' => $metadata['attempt'] ?? 1,
            'tool_name' => $metadata['tool_name'] ?? '',
            'tool_latency_ms' => $metadata['tool_latency_ms'] ?? 0,
            'evaluation_latency_ms' => $metadata['evaluation_latency_ms'] ?? 0,
            'criteria_scores' => $metadata['criteria_scores'] ?? [],
            'weighted_score' => $metadata['weighted_score'] ?? 0,
            'hard_failures' => $metadata['hard_constraint_failures'] ?? [],
            'next_action' => $metadata['next_action'] ?? '',
            'failure_type' => $metadata['failure_type'] ?? '',
        ];
        $this->logToolExecution('agent_evaluator', [], $safe, (int)($safe['evaluation_latency_ms'] ?? 0), true);
    }
}
