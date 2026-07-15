<?php
/**
 * Conversation memory for the sales chatbot.
 *
 * Short-term memory is stored per chat session and works for guests.
 * Long-term memory is stored per logged-in user only.
 */

class ChatbotMemory {
    private PDO $pdo;
    private int $sessionId;
    private ?int $userId;
    private ?LLMProvider $llm;

    private const DEFAULT_SLOTS = [
        'product_type' => null,
        'category_id' => null,
        'color' => null,
        'style' => null,
        'size' => null,
        'height_cm' => null,
        'weight_kg' => null,
        'budget' => null,
        'min_price' => null,
        'max_price' => null,
        'gender' => null,
        'occasion' => null,
        'material' => null,
    ];

    private const DEFAULT_LONG_TERM = [
        'preferences' => [
            'favorite_brand' => null,
            'favorite_country' => null,
            'favorite_texture' => null,
            'favorite_style' => null,
            'favorite_color' => null,
            'avoid_ingredient' => [],
            'avoid_material' => [],
            'avoid_style' => [],
        ],
        'stable_facts' => [
            'skin_type' => null,
            'skin_tone' => null,
            'pregnant' => null,
            'body_shape' => null,
            'usual_size' => null,
        ],
        'important_events' => [],
        'feedback' => [],
        'purchase_history' => [],
    ];

    private const PRODUCT_KEYWORDS = [
        'áo sơ mi' => ['product_type' => 'áo sơ mi', 'category_id' => 1],
        'áo bomber' => ['product_type' => 'áo khoác bomber', 'category_id' => 1],
        'áo khoác' => ['product_type' => 'áo khoác', 'category_id' => 1],
        'áo hoodie' => ['product_type' => 'áo hoodie', 'category_id' => 1],
        'áo gile' => ['product_type' => 'áo gile', 'category_id' => 1],
        'áo vest' => ['product_type' => 'áo vest', 'category_id' => 1],
        'áo blazer' => ['product_type' => 'áo vest', 'category_id' => 1],
        'áo len' => ['product_type' => 'áo len', 'category_id' => 1],
        'áo polo' => ['product_type' => 'áo polo', 'category_id' => 1],
        'áo thun' => ['product_type' => 'áo thun', 'category_id' => 1],
        'áo phông' => ['product_type' => 'áo phông', 'category_id' => 1],
        'áo' => ['product_type' => 'áo', 'category_id' => 1],
        'quần jeans' => ['product_type' => 'quần jeans', 'category_id' => 2],
        'quần jean' => ['product_type' => 'quần jeans', 'category_id' => 2],
        'quần tây' => ['product_type' => 'quần tây', 'category_id' => 2],
        'quần kaki' => ['product_type' => 'quần kaki', 'category_id' => 2],
        'quần baggy' => ['product_type' => 'quần baggy', 'category_id' => 2],
        'quần jogger' => ['product_type' => 'quần jogger', 'category_id' => 2],
        'quần short' => ['product_type' => 'quần short', 'category_id' => 2],
        'quần' => ['product_type' => 'quần', 'category_id' => 2],
        'váy maxi' => ['product_type' => 'váy maxi', 'category_id' => 3],
        'chân váy' => ['product_type' => 'chân váy', 'category_id' => 3],
        'váy đầm' => ['product_type' => 'váy đầm', 'category_id' => 3],
        'đầm' => ['product_type' => 'váy đầm', 'category_id' => 3],
        'váy' => ['product_type' => 'váy', 'category_id' => 3],
        'túi xách' => ['product_type' => 'túi xách', 'category_id' => 4],
        'đồng hồ' => ['product_type' => 'đồng hồ', 'category_id' => 4],
        'thắt lưng' => ['product_type' => 'thắt lưng', 'category_id' => 4],
        'kính mát' => ['product_type' => 'kính mát', 'category_id' => 4],
        'mũ' => ['product_type' => 'mũ', 'category_id' => 4],
        'phụ kiện' => ['product_type' => 'phụ kiện', 'category_id' => 4],
    ];

    public function __construct(PDO $pdo, int $sessionId, ?int $userId = null, ?LLMProvider $llm = null) {
        $this->pdo = $pdo;
        $this->sessionId = $sessionId;
        $this->userId = $userId;
        $this->llm = $llm;
    }

    public function ensureSchema(): void {
        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS chat_session_memory (
                session_id int NOT NULL PRIMARY KEY,
                summary text DEFAULT NULL,
                slots longtext DEFAULT NULL,
                created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            if ($this->userId === null) {
                return;
            }
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS user_long_term_memory (
                user_id int NOT NULL PRIMARY KEY,
                preferences longtext DEFAULT NULL,
                stable_facts longtext DEFAULT NULL,
                important_events longtext DEFAULT NULL,
                feedback longtext DEFAULT NULL,
                purchase_history longtext DEFAULT NULL,
                created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
        } catch (Throwable $e) {
            error_log("Memory schema error: " . $e->getMessage());
        }
    }

    public function rememberUserMessage(string $message): array {
        $memory = $this->load();
        $slots = array_merge(self::DEFAULT_SLOTS, $memory['slots']);
        $slots = array_merge($slots, $this->extractSlots($message));
        $memory['slots'] = $slots;
        $this->saveSessionMemory($memory['summary'], $slots);

        if ($this->userId !== null) {
            $longTerm = $this->loadLongTerm();
            $longTerm = $this->mergeLongTerm($longTerm, $message);
            $this->saveLongTerm($longTerm);
            $memory['long_term'] = $longTerm;
        }

        $memory['slots'] = $slots;
        return $memory;
    }

    public function refreshSummary(string $userMsg, string $botMsg): void {
        $memory = $this->load();
        $summary = $this->generateSummary($memory['summary'], $memory['slots'], $userMsg, $botMsg);
        $this->saveSessionMemory($summary, $memory['slots']);
    }

    public function refreshSummaryWithoutLlm(string $userMsg, string $botMsg): void {
        $memory = $this->load();
        $llm = $this->llm;
        $this->llm = null;
        $summary = $this->generateSummary($memory['summary'], $memory['slots'], $userMsg, $botMsg);
        $this->llm = $llm;
        $this->saveSessionMemory($summary, $memory['slots']);
    }

    public function load(): array {
        $this->ensureSchema();
        $summary = '';
        $slots = self::DEFAULT_SLOTS;

        try {
            $stmt = $this->pdo->prepare("SELECT summary, slots FROM chat_session_memory WHERE session_id = ?");
            $stmt->execute([$this->sessionId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $summary = (string)($row['summary'] ?? '');
                $decoded = $this->decodeJson($row['slots'] ?? null, []);
                if (is_array($decoded)) $slots = array_merge($slots, $decoded);
            }
        } catch (Throwable $e) {
            error_log("Memory load error: " . $e->getMessage());
        }

        return [
            'summary' => $summary,
            'slots' => $slots,
            'long_term' => $this->userId !== null ? $this->loadLongTerm() : null,
        ];
    }

    public function buildPromptBlock(array $memory): string {
        $lines = [
            "MEMORY CONTEXT",
            "- Dùng summary + slot memory dưới đây thay cho việc đọc lại toàn bộ chat.",
            "- Short-term và slot memory áp dụng cho cả guest. Long-term memory chỉ có khi user đã đăng nhập.",
            "- Khi gọi search_products, hãy dùng slot còn hiệu lực để bổ sung phần user đang nói thiếu, ví dụ product_type, category_id, budget/max_price.",
            "",
            "Conversation Summary:",
            $memory['summary'] !== '' ? $memory['summary'] : "- Chưa có summary đáng tin cậy.",
            "",
            "Slot Memory:",
            json_encode($this->compactArray($memory['slots'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        if (!empty($memory['long_term'])) {
            $lines[] = "";
            $lines[] = "Long-term User Memory:";
            $lines[] = json_encode($this->compactArray($memory['long_term']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return implode("\n", $lines);
    }

    public function getContextForEngine(array $memory): array {
        return [
            'summary' => $memory['summary'] ?? '',
            'slots' => $memory['slots'] ?? self::DEFAULT_SLOTS,
            'long_term' => $memory['long_term'] ?? null,
        ];
    }

    private function extractSlots(string $message): array {
        $msg = mb_strtolower($message);
        $slots = [];

        $keywords = array_keys(self::PRODUCT_KEYWORDS);
        usort($keywords, fn($a, $b) => mb_strlen($b) - mb_strlen($a));
        foreach ($keywords as $kw) {
            if (mb_strpos($msg, $kw) !== false) {
                $slots = array_merge($slots, self::PRODUCT_KEYWORDS[$kw]);
                break;
            }
        }

        if (preg_match('/(dưới|duoi|nhỏ hơn|không quá|tối đa|budget|ngân sách|tầm|khoảng)\s*(\d+(?:[.,]\d+)?)\s*(k|nghìn|ngàn|tr|triệu)?/ui', $message, $m)) {
            $price = $this->normalizePrice($m[2], $m[3] ?? '');
            $slots['budget'] = $price;
            $slots['max_price'] = $price;
        } elseif (preg_match('/(trên|tren|từ|thấp nhất)\s*(\d+(?:[.,]\d+)?)\s*(k|nghìn|ngàn|tr|triệu)?/ui', $message, $m)) {
            $slots['min_price'] = $this->normalizePrice($m[2], $m[3] ?? '');
        }

        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(k|nghìn|ngàn)\s*(đến|toi|tới|-|->)\s*(\d+(?:[.,]\d+)?)\s*(k|nghìn|ngàn)?/ui', $message, $m)) {
            $slots['min_price'] = $this->normalizePrice($m[1], $m[2]);
            $slots['max_price'] = $this->normalizePrice($m[4], $m[5] ?? $m[2]);
            $slots['budget'] = $slots['max_price'];
        }

        if (preg_match('/(\d+)\s*m\s*(\d+)/i', $message, $m)) {
            $slots['height_cm'] = ((int)$m[1] * 100) + (int)$m[2];
        } elseif (preg_match('/(\d+)\s*cm/i', $message, $m)) {
            $slots['height_cm'] = (int)$m[1];
        } elseif (preg_match('/1m(\d+)/i', $message, $m)) {
            $slots['height_cm'] = 100 + (int)$m[1];
        }
        if (preg_match('/(?:nặng|can nang|cân nặng)?\s*(\d+)\s*kg/ui', $message, $m)) {
            $slots['weight_kg'] = (int)$m[1];
        }
        if (preg_match('/\b(size)\s*(xs|s|m|l|xl|xxl)\b/i', $message, $m)) {
            $slots['size'] = strtoupper($m[2]);
        }

        $colors = ['đen', 'trắng', 'xanh', 'đỏ', 'hồng', 'xám', 'be', 'nâu', 'vàng', 'tím', 'cam'];
        foreach ($colors as $color) {
            if (mb_strpos($msg, $color) !== false) {
                $slots['color'] = $color;
                break;
            }
        }

        foreach (['basic', 'công sở', 'thể thao', 'vintage', 'form rộng', 'slimfit', 'ôm', 'oversize'] as $style) {
            if (mb_strpos($msg, $style) !== false) {
                $slots['style'] = $style;
                break;
            }
        }

        foreach (['cotton', 'linen', 'len', 'kaki', 'jean', 'voan', 'lụa', 'da'] as $material) {
            if (mb_strpos($msg, $material) !== false) {
                $slots['material'] = $material;
                break;
            }
        }

        if (preg_match('/\b(nam|nữ|nu)\b/ui', $msg, $m)) {
            $slots['gender'] = $m[1] === 'nu' ? 'nữ' : $m[1];
        }

        return $slots;
    }

    private function mergeLongTerm(array $longTerm, string $message): array {
        $msg = mb_strtolower($message);
        $today = date('Y-m-d');

        if (preg_match('/(?:thích|ưng|rất thích|favorite|chuộng)\s+([a-z0-9\s\-]+|[^\.,!\n]+)/ui', $message, $m)) {
            $value = trim($m[1]);
            if ($value !== '') {
                $longTerm['feedback'][] = ['date' => $today, 'sentiment' => 'positive', 'text' => mb_substr($value, 0, 120)];
            }
        }
        if (preg_match('/(?:không thích|chê|bị|dị ứng|kích ứng|tránh)\s+([a-z0-9\s\-]+|[^\.,!\n]+)/ui', $message, $m)) {
            $value = trim($m[1]);
            if ($value !== '') {
                $longTerm['feedback'][] = ['date' => $today, 'sentiment' => 'negative', 'text' => mb_substr($value, 0, 120)];
                $longTerm['important_events'][] = ['date' => $today, 'event' => mb_substr("User cần tránh: $value", 0, 180)];
            }
        }

        foreach (['alcohol', 'cồn', 'fragrance', 'hương liệu', 'niacinamide'] as $ingredient) {
            if ((mb_strpos($msg, 'dị ứng') !== false || mb_strpos($msg, 'tránh') !== false || mb_strpos($msg, 'kích ứng') !== false)
                && mb_strpos($msg, $ingredient) !== false) {
                $normalized = $ingredient === 'cồn' ? 'Alcohol' : ucfirst($ingredient);
                $longTerm['preferences']['avoid_ingredient'][] = $normalized;
            }
        }

        foreach (['da', 'len', 'polyester', 'jean'] as $material) {
            if ((mb_strpos($msg, 'tránh') !== false || mb_strpos($msg, 'không thích') !== false) && mb_strpos($msg, $material) !== false) {
                $longTerm['preferences']['avoid_material'][] = $material;
            }
        }

        if (preg_match('/da\s+(dầu|khô|hỗn hợp|nhạy cảm)/ui', $message, $m)) {
            $longTerm['stable_facts']['skin_type'] = mb_strtolower($m[1]);
        }
        if (preg_match('/(?:hay mặc|size của mình|mình mặc)\s*(xs|s|m|l|xl|xxl)/ui', $message, $m)) {
            $longTerm['stable_facts']['usual_size'] = strtoupper($m[1]);
        }

        $longTerm['feedback'] = array_slice($this->dedupeList($longTerm['feedback']), -20);
        $longTerm['important_events'] = array_slice($this->dedupeList($longTerm['important_events']), -20);
        $longTerm['preferences']['avoid_ingredient'] = array_values(array_unique($longTerm['preferences']['avoid_ingredient']));
        $longTerm['preferences']['avoid_material'] = array_values(array_unique($longTerm['preferences']['avoid_material']));
        $longTerm['purchase_history'] = $this->loadPurchaseHistory();

        return $longTerm;
    }

    private function loadLongTerm(): array {
        $longTerm = self::DEFAULT_LONG_TERM;
        if ($this->userId === null) return $longTerm;

        try {
            $stmt = $this->pdo->prepare("SELECT preferences, stable_facts, important_events, feedback, purchase_history FROM user_long_term_memory WHERE user_id = ?");
            $stmt->execute([$this->userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                foreach (array_keys($longTerm) as $key) {
                    $decoded = $this->decodeJson($row[$key] ?? null, $longTerm[$key]);
                    if (is_array($decoded)) {
                        $longTerm[$key] = array_replace_recursive($longTerm[$key], $decoded);
                    }
                }
            }
        } catch (Throwable $e) {
            error_log("Long-term memory load error: " . $e->getMessage());
        }

        $longTerm['purchase_history'] = $this->loadPurchaseHistory();
        return $longTerm;
    }

    private function saveSessionMemory(string $summary, array $slots): void {
        try {
            if ($this->driverName() === 'sqlite') {
                $stmt = $this->pdo->prepare("INSERT INTO chat_session_memory (session_id, summary, slots, updated_at)
                    VALUES (?, ?, ?, CURRENT_TIMESTAMP)
                    ON CONFLICT(session_id) DO UPDATE SET summary = excluded.summary, slots = excluded.slots, updated_at = CURRENT_TIMESTAMP");
                $stmt->execute([$this->sessionId, $summary, json_encode($slots, JSON_UNESCAPED_UNICODE)]);
                return;
            }

            $stmt = $this->pdo->prepare("INSERT INTO chat_session_memory (session_id, summary, slots, updated_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE summary = VALUES(summary), slots = VALUES(slots), updated_at = NOW()");
            $stmt->execute([$this->sessionId, $summary, json_encode($slots, JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $e) {
            error_log("Session memory save error: " . $e->getMessage());
        }
    }

    private function saveLongTerm(array $longTerm): void {
        if ($this->userId === null) return;

        $payload = [
            json_encode($longTerm['preferences'], JSON_UNESCAPED_UNICODE),
            json_encode($longTerm['stable_facts'], JSON_UNESCAPED_UNICODE),
            json_encode($longTerm['important_events'], JSON_UNESCAPED_UNICODE),
            json_encode($longTerm['feedback'], JSON_UNESCAPED_UNICODE),
            json_encode($longTerm['purchase_history'], JSON_UNESCAPED_UNICODE),
        ];

        try {
            if ($this->driverName() === 'sqlite') {
                $stmt = $this->pdo->prepare("INSERT INTO user_long_term_memory
                    (user_id, preferences, stable_facts, important_events, feedback, purchase_history, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                    ON CONFLICT(user_id) DO UPDATE SET preferences = excluded.preferences, stable_facts = excluded.stable_facts,
                        important_events = excluded.important_events, feedback = excluded.feedback,
                        purchase_history = excluded.purchase_history, updated_at = CURRENT_TIMESTAMP");
                $stmt->execute(array_merge([$this->userId], $payload));
                return;
            }

            $stmt = $this->pdo->prepare("INSERT INTO user_long_term_memory
                (user_id, preferences, stable_facts, important_events, feedback, purchase_history, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE preferences = VALUES(preferences), stable_facts = VALUES(stable_facts),
                    important_events = VALUES(important_events), feedback = VALUES(feedback),
                    purchase_history = VALUES(purchase_history), updated_at = NOW()");
            $stmt->execute(array_merge([$this->userId], $payload));
        } catch (Throwable $e) {
            error_log("Long-term memory save error: " . $e->getMessage());
        }
    }

    private function generateSummary(string $oldSummary, array $slots, string $userMsg, string $botMsg): string {
        if ($this->llm !== null) {
            try {
                $prompt = "Tóm tắt memory hội thoại bán hàng bằng tiếng Việt, dạng bullet ngắn. "
                    . "Chỉ giữ nhu cầu, ràng buộc, sở thích, thông tin ổn định có ích cho lần tư vấn sau. "
                    . "Không quá 8 bullet.\n\n"
                    . "Summary cũ:\n" . ($oldSummary ?: "- Chưa có") . "\n\n"
                    . "Slot hiện tại:\n" . json_encode($this->compactArray($slots), JSON_UNESCAPED_UNICODE) . "\n\n"
                    . "Tin nhắn mới của khách:\n$userMsg\n\nPhản hồi bot:\n$botMsg";
                $response = $this->llm->chat([
                    ['role' => 'system', 'content' => 'Bạn là bộ nén Conversation Summary cho chatbot CSKH.'],
                    ['role' => 'user', 'content' => $prompt],
                ], [], 'none');
                $summary = trim($response->content);
                if ($summary !== '') return mb_substr($summary, 0, 1200);
            } catch (Throwable $e) {
                error_log("LLM summary error: " . $e->getMessage());
            }
        }

        $slotBullets = [];
        foreach ($this->compactArray($slots) as $key => $value) {
            $slotBullets[$key] = "- $key: " . (is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value);
        }

        $parts = [];
        foreach (preg_split('/\R/u', $oldSummary) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $replaceBySlot = false;
            foreach (array_keys($slotBullets) as $key) {
                if (preg_match('/^-\s*' . preg_quote((string)$key, '/') . '\s*:/u', $line)) {
                    $replaceBySlot = true;
                    break;
                }
            }
            if (!$replaceBySlot) $parts[] = $line;
        }

        $parts = array_merge($parts, array_values($slotBullets));
        $summary = implode("\n", array_values(array_unique($parts)));
        return mb_substr($summary, 0, 1200);
    }

    private function loadPurchaseHistory(): array {
        if ($this->userId === null) return [];

        try {
            $stmt = $this->pdo->prepare("
                SELECT o.created_at as date, p.name as product
                FROM orders o
                JOIN order_items oi ON oi.order_id = o.id
                JOIN products p ON p.id = oi.product_id
                WHERE o.user_id = ?
                ORDER BY o.created_at DESC
                LIMIT 10
            ");
            $stmt->execute([$this->userId]);
            return array_map(fn($r) => [
                'date' => substr((string)$r['date'], 0, 10),
                'product' => $r['product'],
            ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            return [];
        }
    }

    private function normalizePrice(string $number, string $unit): int {
        $value = (float)str_replace(',', '.', $number);
        $unit = mb_strtolower($unit);
        if (in_array($unit, ['tr', 'triệu'], true)) return (int)round($value * 1000000);
        if (in_array($unit, ['k', 'nghìn', 'ngàn'], true)) return (int)round($value * 1000);
        return (int)round($value < 1000 ? $value * 1000 : $value);
    }

    private function decodeJson(?string $json, $fallback) {
        if (!$json) return $fallback;
        $decoded = json_decode($json, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $fallback;
    }

    private function compactArray(array $data): array {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $nested = $this->compactArray($value);
                if ($nested !== []) $out[$key] = $nested;
            } elseif ($value !== null && $value !== '' && $value !== []) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    private function dedupeList(array $items): array {
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            $key = json_encode($item, JSON_UNESCAPED_UNICODE);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $item;
        }
        return $out;
    }

    private function driverName(): string {
        try {
            return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (Throwable $e) {
            return 'mysql';
        }
    }
}
