<?php
/**
 * Tool Registry + Tool Executor
 * Định nghĩa tools cho LLM function calling + execute tool gọi API nội bộ.
 * Hỗ trợ reranking qua sidecar Python (cross-encoder).
 */

require_once __DIR__ . '/../../cache/Cache.php';
require_once __DIR__ . '/KnowledgeRetriever.php';

class ToolRegistry {
    private PDO $pdo;
    private ?int $userId;
    private array $tools = [];
    private KnowledgeRetriever $knowledgeRetriever;

    /** Tối thiểu bao nhiêu kết quả thì kích hoạt rerank */
    private const RERANK_MIN_RESULTS = 5;

    /** Timeout cho reranker sidecar (ms) — fallback về gốc nếu quá chậm */
    private const RERANK_TIMEOUT_MS = 2000;
    /** Tối đa bao nhiêu items gửi xuống reranker (phần còn lại giữ nguyên thứ tự) */
    private const RERANK_MAX_ITEMS = 20;
    private const SEARCH_CACHE_VERSION = 2;

    public function __construct(PDO $pdo, ?int $userId = null) {
        $this->pdo = $pdo;
        $this->userId = $userId;
        $this->knowledgeRetriever = new KnowledgeRetriever($pdo);
        $this->registerAll();
    }

    public function getDefinitions(): array {
        return array_values($this->tools);
    }

    public function execute(string $toolName, array $arguments): array {
        $handler = 'execute' . str_replace(' ', '', ucwords(str_replace('_', ' ', $toolName)));
        if (method_exists($this, $handler)) {
            return $this->$handler($arguments);
        }
        throw new RuntimeException("Unknown tool: $toolName");
    }

    private function registerAll(): void {
        $this->tools['search_products'] = [
            'type' => 'function',
            'function' => [
                'name' => 'search_products',
                'description' => 'Tìm kiếm sản phẩm theo từ khóa, giá, danh mục. 
BẮT BUỘC dùng tool này MỖI KHI user hỏi về sản phẩm, kể cả khi câu trả lời đã có trong lịch sử.
CÁCH DÙNG: Trích xuất CHÍNH XÁC cụm từ sản phẩm từ câu hỏi, KHÔNG được rút gọn.
- "áo khoác dưới 500k" → search="áo khoác", max_price=500000
- "áo bomber" → search="áo khoác bomber"
- "áo thun trắng" → search="áo thun" 
- "áo gile lông cừu" → search="áo gile"
- "áo polo thể thao" → search="áo polo"
- "áo len cổ tròn" → search="áo len"
- "quần jeans ống rộng" → search="quần jeans"
- "váy maxi hoa" → search="váy maxi"
- "quần tây công sở" → search="quần tây"
- "chân váy chữ A" → search="chân váy"
QUAN TRỌNG: search là tìm theo tên sản phẩm (LIKE), nên cần từ khóa CHÍNH XÁC.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'search' => [
                            'type' => 'string',
                            'description' => 'Từ khóa tìm kiếm tên sản phẩm. TUYỆT ĐỐI KHÔNG được rút gọn: 
- Nếu user nói "áo khoác" → search="áo khoác" (KHÔNG search="áo")
- Nếu user nói "áo thun" → search="áo thun" (KHÔNG search="áo")
- Nếu user nói "áo gile" → search="áo gile" (KHÔNG search="áo")
- Nếu user nói "áo len" → search="áo len" (KHÔNG search="áo")
- Nếu user nói "áo polo" → search="áo polo" (KHÔNG search="áo")
Lấy đúng cụm từ user đã nói, không thêm bớt.',
                        ],
                        'category_id' => [
                            'type' => 'integer',
                            'enum' => [1, 2, 3, 4],
                            'description' => 'Danh mục: 1=Áo, 2=Quần, 3=Váy & Đầm, 4=Phụ kiện. Chỉ dùng khi search không đủ xác định. VD: "áo" không có loại cụ thể → category_id=1',
                        ],
                        'min_price' => ['type' => 'number', 'description' => 'Giá thấp nhất (VNĐ)'],
                        'max_price' => ['type' => 'number', 'description' => 'Giá cao nhất (VNĐ)'],
                    ],
                    'required' => ['search'],
                ],
            ],
        ];

        $this->tools['get_product_detail'] = [
            'type' => 'function',
            'function' => [
                'name' => 'get_product_detail',
                'description' => 'Lấy thông tin chi tiết sản phẩm: mô tả, giá, tồn kho, kích cỡ, đánh giá. Dùng khi người dùng hỏi về sản phẩm cụ thể.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => ['type' => 'integer', 'description' => 'ID sản phẩm'],
                    ],
                    'required' => ['product_id'],
                ],
            ],
        ];

        $this->tools['suggest_size'] = [
            'type' => 'function',
            'function' => [
                'name' => 'suggest_size',
                'description' => 'Tư vấn size phù hợp dựa trên chiều cao và cân nặng. Dùng khi người dùng hỏi mặc size gì, chọn size.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'height' => ['type' => 'integer', 'description' => 'Chiều cao (cm)'],
                        'weight' => ['type' => 'integer', 'description' => 'Cân nặng (kg)'],
                        'category_id' => ['type' => 'integer', 'description' => 'ID danh mục: 1=Áo, 2=Quần, 3=Váy & Đầm'],
                    ],
                    'required' => ['height', 'weight'],
                ],
            ],
        ];

        $this->tools['retrieve_knowledge'] = [
            'type' => 'function',
            'function' => [
                'name' => 'retrieve_knowledge',
                'description' => 'Truy xuất tri thức thật của shop từ RAG/VectorDB và fallback Markdown/FAQ DB. BẮT BUỘC dùng khi user hỏi chính sách, đổi trả, hoàn tiền, giao hàng, phí ship, thanh toán, bảo hành, bán sỉ, thông tin shop, hoặc hướng dẫn CSKH. Có thể dùng cùng search_products/get_product_detail cho câu hỏi vừa có sản phẩm vừa có chính sách.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Câu hỏi hoặc từ khóa cần tra cứu theo đúng ý user.',
                        ],
                        'category' => [
                            'type' => 'string',
                            'enum' => ['shipping', 'return', 'payment', 'warranty', 'wholesale', 'general', 'order', 'size', 'shop_info', 'policy'],
                            'description' => 'Nhóm tri thức nếu xác định được. Để trống nếu câu hỏi rộng hoặc mixed intent.',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Số đoạn tri thức tối đa cần lấy, mặc định 5.',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];

        $this->tools['get_categories'] = [
            'type' => 'function',
            'function' => [
                'name' => 'get_categories',
                'description' => 'Lấy danh sách danh mục sản phẩm.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => (object)[],
                ],
            ],
        ];

        $this->tools['get_order_status'] = [
            'type' => 'function',
            'function' => [
                'name' => 'get_order_status',
                'description' => 'Tra cứu trạng thái đơn hàng của user đã đăng nhập. Dùng khi user hỏi đơn hàng của tôi, trạng thái đơn, đơn đã giao chưa. Nếu chưa đăng nhập, tool sẽ yêu cầu đăng nhập.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'order_id' => ['type' => 'integer', 'description' => 'Mã đơn hàng nếu user cung cấp. Bỏ trống để lấy các đơn gần nhất.'],
                    ],
                ],
            ],
        ];
    }

    // ---- Handlers ----

    private function executeSearchProducts(array $args): array {
        $args = $this->normalizeSearchArgs($args);
        if ($this->isSqlite()) {
            return $this->executeSearchProductsDirect($args);
        }

        $queryParams = [
            'search' => $args['search'] ?? '',
            'sort' => 'price_asc',
            '_v' => self::SEARCH_CACHE_VERSION,
        ];
        if (!empty($args['category_id'])) $queryParams['category'] = $args['category_id'];
        if (!empty($args['min_price'])) $queryParams['min_price'] = $args['min_price'];
        if (!empty($args['max_price'])) $queryParams['max_price'] = $args['max_price'];

        // Check cache first
        $cached = Cache::getSearchResult($queryParams);
        if ($cached !== null) {
            return $cached;
        }

        $base = getInternalApiUrl();
        $url = "$base/api/products?" . http_build_query($queryParams);
        $result = $this->fetchJson($url);

        // Rerank if enough results
        if (!isset($result['error']) && !empty($result['products'])) {
            $result = $this->applyRerank($args['search'] ?? '', $result);
        }

        // Only cache successful results (no error)
        if (!isset($result['error'])) {
            Cache::setSearchResult($queryParams, $result);
        }

        return $result;
    }

    private function executeGetProductDetail(array $args): array {
        $id = $args['product_id'] ?? 0;
        if (!$id) return ['error' => 'Product ID required'];

        // Check cache
        $cached = Cache::getProductDetail($id);
        if ($cached !== null) {
            return $cached;
        }

        $url = getInternalApiUrl() . "/api/products/$id";
        $result = $this->fetchJson($url);

        if (!isset($result['error'])) {
            Cache::setProductDetail($id, $result);
        }

        return $result;
    }

    private function executeSuggestSize(array $args): array {
        $queryParams = [
            'height' => $args['height'] ?? 0,
            'weight' => $args['weight'] ?? 0,
            'category_id' => $args['category_id'] ?? '',
        ];

        // Check cache
        $cached = Cache::getSizeGuide($queryParams);
        if ($cached !== null) {
            return $cached;
        }

        $params = http_build_query($queryParams);
        $url = getInternalApiUrl() . "/api/size-guide?$params";
        $result = $this->fetchJson($url);

        if (!isset($result['error'])) {
            Cache::setSizeGuide($queryParams, $result);
        }

        return $result;
    }

    private function executeRetrieveKnowledge(array $args): array {
        $query = trim((string)($args['query'] ?? ''));
        if ($query === '') return ['results' => [], 'message' => 'Query is required'];
        $category = isset($args['category']) && $args['category'] !== '' ? (string)$args['category'] : null;
        $limit = isset($args['limit']) ? (int)$args['limit'] : 5;
        $result = $this->knowledgeRetriever->search($query, $category, $limit);
        $result['guidance'] = 'Chỉ trả lời dựa trên results. Nếu results rỗng hoặc chưa đủ, hãy nói chưa có đủ thông tin trong dữ liệu shop và hỏi thêm.';
        return $result;
    }

    private function executeGetOrderStatus(array $args): array {
        if ($this->userId === null) {
            return [
                'requires_login' => true,
                'message' => 'Bạn cần đăng nhập để mình kiểm tra đơn hàng.',
                'login_url' => $this->absoluteUrl('/login.php'),
            ];
        }

        $orderId = isset($args['order_id']) ? (int)$args['order_id'] : 0;
        if ($orderId > 0) {
            $stmt = $this->pdo->prepare("SELECT id, total_price, status, created_at FROM orders WHERE id = ? AND user_id = ?");
            $stmt->execute([$orderId, $this->userId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order) {
                return ['orders' => [], 'message' => 'Không tìm thấy đơn hàng này trong tài khoản của bạn.'];
            }
            return ['orders' => [$this->castOrder($order)]];
        }

        $stmt = $this->pdo->prepare("SELECT id, total_price, status, created_at FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
        $stmt->execute([$this->userId]);
        $orders = array_map(fn($o) => $this->castOrder($o), $stmt->fetchAll(PDO::FETCH_ASSOC));
        return [
            'orders' => $orders,
            'message' => empty($orders) ? 'Bạn chưa có đơn hàng nào.' : 'Đây là các đơn hàng gần nhất của bạn.',
        ];
    }

    private function executeGetCategories(array $args): array {
        if ($this->isSqlite()) {
            $stmt = $this->pdo->query("SELECT id, name FROM categories ORDER BY id");
            return ['categories' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
        }

        // Check cache
        $cached = Cache::getCategories();
        if ($cached !== null) {
            return $cached;
        }

        $url = getInternalApiUrl() . "/api/categories";
        $result = $this->fetchJson($url);

        if (!isset($result['error'])) {
            Cache::setCategories($result);
        }

        return $result;
    }

    private function executeSearchProductsDirect(array $args): array {
        $args = $this->normalizeSearchArgs($args);
        $sql = "SELECT p.id, p.category_id, p.name, p.price, p.stock, p.image, c.name as category_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE 1=1";
        $params = [];

        if (!empty($args['category_id'])) {
            $sql .= " AND p.category_id = ?";
            $params[] = (int)$args['category_id'];
        }
        if (!empty($args['min_price'])) {
            $sql .= " AND p.price >= ?";
            $params[] = (float)$args['min_price'];
        }
        if (!empty($args['max_price'])) {
            $sql .= " AND p.price <= ?";
            $params[] = (float)$args['max_price'];
        }

        $sql .= " ORDER BY p.price ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $search = mb_strtolower(trim((string)($args['search'] ?? '')));
        if ($search !== '') {
            $words = array_values(array_filter(preg_split('/\s+/u', $search) ?: [], fn($w) => mb_strlen($w) >= 2));
            $products = array_values(array_filter($products, function($p) use ($search) {
                return mb_strpos(mb_strtolower($p['name'] ?? ''), $search) !== false;
            }));
            if (empty($products) && count($words) > 1) {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                $allProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $products = array_values(array_filter($allProducts, function($p) use ($words) {
                    $name = mb_strtolower($p['name'] ?? '');
                    foreach ($words as $word) {
                        if (mb_strpos($name, $word) === false) return false;
                    }
                    return true;
                }));
            }
        }

        foreach ($products as &$p) {
            $p['id'] = (int)$p['id'];
            $p['category_id'] = $p['category_id'] !== null ? (int)$p['category_id'] : null;
            $p['price'] = (float)$p['price'];
            $p['stock'] = (int)($p['stock'] ?? 0);
        }
        unset($p);

        return [
            'products' => $products,
            'pagination' => [
                'page' => 1,
                'limit' => null,
                'total' => count($products),
                'total_pages' => 1,
            ],
        ];
    }

    private function normalizeSearchArgs(array $args): array {
        $search = mb_strtolower(trim((string)($args['search'] ?? '')));
        if ($search === '') return $args;

        $aliases = [
            'áo bomber' => 'áo khoác bomber',
            'ao bomber' => 'áo khoác bomber',
            'bomber' => 'áo khoác bomber',
            'áo blazer' => 'áo vest',
            'ao blazer' => 'áo vest',
            'quần jean' => 'quần jeans',
            'quan jean' => 'quần jeans',
        ];

        foreach ($aliases as $needle => $normalized) {
            if (mb_strpos($search, $needle) !== false) {
                $args['search'] = $normalized;
                if (empty($args['category_id']) && str_starts_with($normalized, 'áo')) $args['category_id'] = 1;
                if (empty($args['category_id']) && str_starts_with($normalized, 'quần')) $args['category_id'] = 2;
                return $args;
            }
        }

        return $args;
    }

    private function absoluteUrl(string $path): string {
        if (function_exists('getBaseUrl')) return rtrim(getBaseUrl(), '/') . $path;
        return $path;
    }

    private function castOrder(array $order): array {
        return [
            'id' => (int)$order['id'],
            'total_price' => (float)($order['total_price'] ?? 0),
            'status' => (string)($order['status'] ?? ''),
            'created_at' => (string)($order['created_at'] ?? ''),
        ];
    }

    // ---- Reranking ----

    /**
     * Apply reranker sidecar nếu có đủ kết quả.
     * Fallback về thứ tự gốc nếu reranker không phản hồi.
     */
    private function applyRerank(string $query, array $result): array {
        if (count($result['products']) < self::RERANK_MIN_RESULTS) {
            return $result; // quá ít, không cần rerank
        }

        // Limit items gửi xuống reranker để tránh timeout
        $productsToRerank = array_slice($result["products"], 0, self::RERANK_MAX_ITEMS);

        $texts = array_map(fn($p) => $p['name'] ?? '', $productsToRerank);
        $texts = array_values($texts); // re-index

        try {
            $sorted = $this->callReranker($query, $texts);
            if ($sorted === null) {
                return $result; // fallback
            }

            // Sắp xếp lại products theo sorted indices (chỉ phần được rerank)
            $reranked = [];
            $rerankedKeys = [];
            foreach ($sorted as $idx) {
                if (isset($productsToRerank[$idx])) {
                    $key = array_search($productsToRerank[$idx], $result["products"], true);
                    if ($key !== false) {
                        $rerankedKeys[$key] = true;
                        $reranked[] = $result["products"][$key];
                    }
                }
            }

            // Giữ lại các sản phẩm không được rerank (giữ nguyên thứ tự)
            foreach ($result["products"] as $i => $p) {
                if (!isset($rerankedKeys[$i])) {
                    $reranked[] = $p;
                }
            }

            $result["products"] = $reranked;
        } catch (Throwable $e) {
            error_log("Reranker fallback: " . $e->getMessage());
        }

        return $result;
    }

    /**
     * Gọi Python sidecar reranker.
     * Trả về mảng indices đã sorted theo relevance (cao→thấp), hoặc null nếu lỗi.
     */
    private function callReranker(string $query, array $texts): ?array {
        $url = $this->getRerankerUrl();
        if ($url === null) return null;

        $payload = json_encode([
            'query' => $query,
            'texts' => $texts,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => intdiv(self::RERANK_TIMEOUT_MS, 1000) ?: 1, // 2 second max
            CURLOPT_CONNECTTIMEOUT => 1, // 1 second connect
        ]);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $info = curl_getinfo($ch);
        curl_close($ch);

        if ($raw === false || $raw === '' || $httpCode !== 200) {
            $body = $raw === false ? '' : $raw;
            error_log("Reranker HTTP $httpCode, body=" . substr($body, 0, 200));
            return null;
        }

        $data = json_decode($raw, true);
        if (!isset($data['sorted_indices']) || !is_array($data['sorted_indices'])) {
            return null;
        }

        // Log latency để monitoring
        if (isset($data['elapsed_ms'])) {
            error_log("Reranker latency: {$data['elapsed_ms']}ms for " . count($texts) . " items");
        }

        return $data['sorted_indices'];
    }

    /**
     * Lấy URL của reranker sidecar từ env hoặc fallback localhost.
     */
    private function getRerankerUrl(): ?string {
        $url = getenv('RERANKER_URL');
        if ($url !== false && $url !== '') {
            return rtrim($url, '/') . '/rerank';
        }
        // Mặc định dùng Docker service name
        return 'http://reranker:8000/rerank';
    }

    private function isSqlite(): bool {
        try {
            return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        } catch (Throwable $e) {
            return false;
        }
    }

    private function fetchJson(string $url): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $raw === '') {
            return ['error' => "HTTP request failed: $httpCode"];
        }

        $data = json_decode($raw, true);
        return $data ?: ['error' => 'Invalid JSON response'];
    }
}
