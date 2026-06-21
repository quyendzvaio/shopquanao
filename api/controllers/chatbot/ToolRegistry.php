<?php
/**
 * Tool Registry + Tool Executor
 * Định nghĩa tools cho LLM function calling + execute tool gọi API nội bộ.
 * Hỗ trợ reranking qua sidecar Python (cross-encoder).
 */

require_once __DIR__ . '/../../cache/Cache.php';

class ToolRegistry {
    private PDO $pdo;
    private array $tools = [];

    /** Tối thiểu bao nhiêu kết quả thì kích hoạt rerank */
    private const RERANK_MIN_RESULTS = 5;

    /** Timeout cho reranker sidecar (ms) — fallback về gốc nếu quá chậm */
    private const RERANK_TIMEOUT_MS = 2000;
    /** Tối đa bao nhiêu items gửi xuống reranker (phần còn lại giữ nguyên thứ tự) */
    private const RERANK_MAX_ITEMS = 20;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
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

        $this->tools['get_faq'] = [
            'type' => 'function',
            'function' => [
                'name' => 'get_faq',
                'description' => 'Tra cứu FAQ (chính sách mua hàng, đổi trả, vận chuyển, thanh toán, bảo hành). Dùng khi người dùng hỏi về chính sách shop.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'category' => [
                            'type' => 'string',
                            'enum' => ['shipping', 'return', 'payment', 'warranty', 'wholesale', 'general', 'order', 'size'],
                            'description' => 'Danh mục câu hỏi',
                        ],
                        'search' => ['type' => 'string', 'description' => 'Từ khóa tìm kiếm trong FAQ'],
                    ],
                ],
            ],
        ];

        $this->tools['get_outfit'] = [
            'type' => 'function',
            'function' => [
                'name' => 'get_outfit',
                'description' => 'Gợi ý phối đồ dựa trên sản phẩm đang xem. Dùng khi người dùng hỏi phối đồ, mặc với gì, kết hợp với gì.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => ['type' => 'integer', 'description' => 'ID sản phẩm cần phối đồ'],
                        'search' => ['type' => 'string', 'description' => 'Tên sản phẩm cần phối đồ'],
                    ],
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
    }

    // ---- Handlers ----

    private function executeSearchProducts(array $args): array {
        $queryParams = [
            'search' => $args['search'] ?? '',
            'sort' => 'price_asc',
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

    private function executeGetFaq(array $args): array {
        $params = http_build_query(array_filter([
            'category' => $args['category'] ?? '',
            'search' => $args['search'] ?? '',
        ]));
        $url = getInternalApiUrl() . "/api/faq?$params";
        return $this->fetchJson($url);
    }

    private function executeGetOutfit(array $args): array {
        $filtered = array_filter([
            'product_id' => $args['product_id'] ?? '',
            'search' => $args['search'] ?? '',
        ]);

        // Check cache
        $cached = Cache::getOutfit($filtered);
        if ($cached !== null) {
            if (isset($cached['error'])) return ['outfits' => []];
            return $cached;
        }

        $params = http_build_query($filtered);
        $url = getInternalApiUrl() . "/api/outfit?$params";
        $result = $this->fetchJson($url);
        if (isset($result['error'])) $result = ['outfits' => []];

        Cache::setOutfit($filtered, $result);
        return $result;
    }

    private function executeGetCategories(array $args): array {
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
            error_log("Reranker HTTP $httpCode, body=" . substr($raw ?? '', 0, 200));
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
