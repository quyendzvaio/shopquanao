<?php
/**
 * Tool Registry + Tool Executor
 * Định nghĩa capability contracts cho PHP ToolPlanner và thực thi tool.
 * Hỗ trợ reranking sản phẩm qua sidecar Python TF-IDF.
 */

require_once __DIR__ . '/../../cache/Cache.php';
require_once __DIR__ . '/KnowledgeRetriever.php';
require_once __DIR__ . '/ProductAttributeNormalizer.php';
require_once __DIR__ . '/contracts/ChatbotToolGateway.php';
require_once __DIR__ . '/ToolDefinitionCatalog.php';

class ToolRegistry implements ChatbotToolGateway {
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
    private const SEARCH_CACHE_VERSION = 3;

    public function __construct(PDO $pdo, ?int $userId = null) {
        $this->pdo = $pdo;
        $this->userId = $userId;
        $this->knowledgeRetriever = new KnowledgeRetriever($pdo);
        $this->registerAll();
    }

    public function getDefinitions(): array {
        return ToolDefinitionCatalog::chatbotDefinitions();
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
                        'color' => ['type' => 'string', 'description' => 'Màu sắc đã chuẩn hóa nếu user nêu rõ.'],
                        'size' => ['type' => 'string', 'description' => 'Size user quan tâm nếu có.'],
                        'in_stock' => ['type' => 'boolean', 'description' => 'Chỉ ưu tiên sản phẩm còn hàng nếu user hỏi tồn kho/còn hàng.'],
                        'occasion' => ['type' => 'string', 'description' => 'Ngữ cảnh sử dụng do entity enrichment bổ sung.'],
                        'style' => ['type' => ['string', 'array'], 'description' => 'Phong cách mong muốn do entity enrichment bổ sung.'],
                        'avoid' => ['type' => ['string', 'array'], 'description' => 'Đặc điểm cần tránh do entity enrichment bổ sung.'],
                        'semantic_query' => ['type' => 'string', 'description' => 'Đoạn semantic constraint không dùng làm keyword LIKE cứng.'],
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
        $explicitProductId = $this->extractSearchProductId($args);
        if ($explicitProductId !== null) {
            $detail = $this->executeGetProductDetail(['product_id' => $explicitProductId]);
            if (isset($detail['product']) && is_array($detail['product'])) {
                $detail['products'] = [$this->productDetailToCard($detail['product'])];
                $detail['pagination'] = [
                    'page' => 1,
                    'limit' => 1,
                    'total' => 1,
                    'total_pages' => 1,
                ];
                $detail['routed_from'] = 'search_products';
                $detail['routed_to'] = 'get_product_detail';
            }
            return $detail;
        }

        $queryParams = [
            'search' => $args['search'] ?? '',
            'sort' => 'price_asc',
            '_v' => self::SEARCH_CACHE_VERSION,
        ];
        foreach (['category_id', 'min_price', 'max_price', 'color', 'size', 'in_stock', 'material', 'style', 'occasion', 'avoid', 'semantic_query'] as $key) {
            if (array_key_exists($key, $args) && $args[$key] !== null && $args[$key] !== '') {
                $queryParams[$key] = is_array($args[$key])
                    ? json_encode($args[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : $args[$key];
            }
        }

        $cachedIds = Cache::getProductSearchIds($queryParams);
        if ($cachedIds !== null && isset($cachedIds['product_ids']) && is_array($cachedIds['product_ids'])) {
            $freshProducts = $this->fetchFreshProductsByIds(array_map('intval', $cachedIds['product_ids']));
            $freshProducts = array_values(array_filter($freshProducts, fn($p) => ProductAttributeNormalizer::productMatchesConstraints($p, $args)));
            if (!empty($freshProducts)) {
                return [
                    'products' => $freshProducts,
                    'pagination' => [
                        'page' => 1,
                        'limit' => null,
                        'total' => count($freshProducts),
                        'total_pages' => 1,
                    ],
                    'cache' => ['product_cache_hit' => true, 'cache_policy' => 'ids_only_fresh_price_stock'],
                ];
            }
        }

        $result = $this->executeSearchProductsDirect($args);

        if (!isset($result['error']) && !empty($result['products'])) {
            $result = $this->applyRerank($args['search'] ?? '', $result);
        }

        if (!isset($result['error'])) {
            Cache::setProductSearchIds($queryParams, [
                'product_ids' => array_values(array_map(fn($p) => (int)$p['id'], $result['products'] ?? [])),
                'total' => (int)($result['pagination']['total'] ?? count($result['products'] ?? [])),
            ]);
            $result['cache'] = ['product_cache_hit' => false, 'cache_policy' => 'ids_only_fresh_price_stock'];
        }

        return $result;
    }

    private function extractSearchProductId(array $args): ?int {
        $search = trim((string)($args['search'] ?? ''));
        if ($search === '') return null;

        if (preg_match('/^(?:mã|ma|id|#|sản phẩm mã|san pham ma|product)?\s*#?\s*(\d+)$/ui', $search, $m)) {
            return max(1, (int)$m[1]);
        }

        return null;
    }

    private function productDetailToCard(array $product): array {
        return [
            'id' => (int)($product['id'] ?? 0),
            'category_id' => isset($product['category_id']) ? (int)$product['category_id'] : null,
            'name' => (string)($product['name'] ?? ''),
            'price' => (float)($product['price'] ?? 0),
            'stock' => (int)($product['stock'] ?? 0),
            'image' => (string)($product['image'] ?? ''),
            'category_name' => (string)($product['category_name'] ?? ''),
            'description' => (string)($product['description'] ?? ''),
            'sizes' => $product['sizes'] ?? [],
            'available_colors' => ProductAttributeNormalizer::extractColorsFromProduct($product),
        ];
    }

    private function executeGetProductDetail(array $args): array {
        $id = $args['product_id'] ?? 0;
        if (!$id) return ['error' => 'Product ID required'];

        $static = Cache::getProductDetailStatic($id);
        if ($static === null) {
            $static = $this->fetchProductDetailStatic($id);
            if (isset($static['error'])) {
                return $static;
            }
            Cache::setProductDetailStatic($id, $static);
        }

        $fresh = $this->fetchProductFreshById($id);
        if ($fresh === null) {
            return ['error' => 'Product not found'];
        }

        $product = array_merge($static['product'] ?? [], $fresh);
        return [
            'product' => $product,
            'cache' => [
                'product_detail_static_cache_hit' => true,
                'cache_policy' => 'static_metadata_only_fresh_price_stock',
            ],
        ];
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

        $result = $this->suggestSizeDirect($queryParams);

        if (!isset($result['error'])) {
            Cache::setSizeGuide($queryParams, $result);
        }

        return $result;
    }

    private function fetchFreshProductsByIds(array $ids): array {
        $ids = array_values(array_filter(array_unique($ids), fn($id) => $id > 0));
        if ($ids === []) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT p.id, p.category_id, p.name, p.price, p.stock, p.image, p.description, c.name as category_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE p.id IN ($placeholders)");
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $byId = [];
        foreach ($rows as $p) {
            $p['id'] = (int)$p['id'];
            $p['category_id'] = $p['category_id'] !== null ? (int)$p['category_id'] : null;
            $p['price'] = (float)$p['price'];
            $p['stock'] = (int)($p['stock'] ?? 0);
            $byId[$p['id']] = $p;
        }
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) $ordered[] = $byId[$id];
        }
        $this->attachProductSizes($ordered);
        $this->attachProductColors($ordered);
        return $ordered;
    }

    private function fetchProductFreshById(int $id): ?array {
        $stmt = $this->pdo->prepare("SELECT id, price, stock FROM products WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        return [
            'id' => (int)$row['id'],
            'price' => (float)$row['price'],
            'stock' => (int)($row['stock'] ?? 0),
        ];
    }

    private function fetchProductDetailStatic(int $id): array {
        $stmt = $this->pdo->prepare("SELECT p.id, p.category_id, p.name, p.description, p.image, c.name as category_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE p.id = ?");
        $stmt->execute([$id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) return ['error' => 'Product not found'];

        $product['id'] = (int)$product['id'];
        $product['category_id'] = $product['category_id'] !== null ? (int)$product['category_id'] : null;
        $product['sizes'] = [];
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM product_sizes WHERE product_id = ?");
            $stmt->execute([$id]);
            $product['sizes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
        $product['available_colors'] = ProductAttributeNormalizer::extractColorsFromProduct($product);

        try {
            $stmt = $this->pdo->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews FROM reviews WHERE product_id = ?");
            $stmt->execute([$id]);
            $rating = $stmt->fetch(PDO::FETCH_ASSOC);
            $product['avg_rating'] = $rating && $rating['avg_rating'] ? round((float)$rating['avg_rating'], 1) : null;
            $product['total_reviews'] = $rating ? (int)$rating['total_reviews'] : 0;
        } catch (Throwable $e) {
            $product['avg_rating'] = null;
            $product['total_reviews'] = 0;
        }

        return ['product' => $product];
    }

    private function suggestSizeDirect(array $queryParams): array {
        $height = (int)($queryParams['height'] ?? 0);
        $weight = (int)($queryParams['weight'] ?? 0);
        $catId = (int)($queryParams['category_id'] ?? 0);
        if ($height <= 0 || $weight <= 0) return ['error' => 'Height and weight are required'];

        $orderBy = $this->isSqlite()
            ? "ORDER BY category_id, CASE size_name WHEN 'S' THEN 1 WHEN 'M' THEN 2 WHEN 'L' THEN 3 WHEN 'XL' THEN 4 ELSE 5 END"
            : "ORDER BY category_id, FIELD(size_name, 'S','M','L','XL')";
        if ($catId > 0) {
            $stmt = $this->pdo->prepare("SELECT * FROM size_guides WHERE category_id = ? $orderBy");
            $stmt->execute([$catId]);
        } else {
            $stmt = $this->pdo->query("SELECT * FROM size_guides $orderBy");
        }
        $sizes = $this->dedupeSizeRows($stmt->fetchAll(PDO::FETCH_ASSOC));
        $recommended = null;
        foreach ($sizes as $s) {
            $hOk = (!$s['height_from'] || $height >= (int)$s['height_from'])
                && (!$s['height_to'] || $height <= (int)$s['height_to']);
            $wOk = (!$s['weight_from'] || $weight >= (int)$s['weight_from'])
                && (!$s['weight_to'] || $weight <= (int)$s['weight_to']);
            if ($hOk && $wOk) {
                $recommended = $s;
                break;
            }
        }
        return ['recommended' => $recommended, 'sizes' => $sizes];
    }

    private function dedupeSizeRows(array $rows): array {
        $seen = [];
        $sizes = [];
        foreach ($rows as $s) {
            if (!is_array($s)) continue;
            $key = implode('|', [
                $s['category_id'] ?? '',
                strtoupper((string)($s['size_name'] ?? '')),
                $s['height_from'] ?? '',
                $s['height_to'] ?? '',
                $s['weight_from'] ?? '',
                $s['weight_to'] ?? '',
            ]);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            foreach (['id', 'category_id', 'product_id', 'height_from', 'height_to', 'weight_from', 'weight_to'] as $field) {
                if (array_key_exists($field, $s) && $s[$field] !== null && $s[$field] !== '') {
                    $s[$field] = (int)$s[$field];
                }
            }
            $sizes[] = $s;
        }
        return $sizes;
    }

    private function executeRetrieveKnowledge(array $args): array {
        $query = trim((string)($args['query'] ?? ''));
        if ($query === '') return ['results' => [], 'message' => 'Query is required'];
        $category = isset($args['category']) && $args['category'] !== '' ? (string)$args['category'] : null;
        if (preg_match('/hoàn tiền|refund/ui', $query)) {
            $category = 'policy';
        }
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
        $cached = Cache::getCategories();
        if ($cached !== null) {
            return $cached;
        }

        $stmt = $this->pdo->query("SELECT id, name FROM categories ORDER BY id");
        $result = ['categories' => array_map(
            fn($category) => ['id' => (int)$category['id'], 'name' => (string)$category['name']],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        )];
        Cache::setCategories($result);
        return $result;
    }

    private function executeSearchProductsDirect(array $args): array {
        $args = $this->normalizeSearchArgs($args);
        $sql = "SELECT p.id, p.category_id, p.name, p.price, p.stock, p.image, p.description, c.name as category_name
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
        if (($args['in_stock'] ?? null) === true) {
            $sql .= " AND p.stock > 0";
        }
        if (!empty($args['size'])) {
            $size = ProductAttributeNormalizer::normalizeSize((string)$args['size']);
            if ($size !== null) {
                $sql .= " AND EXISTS (SELECT 1 FROM product_sizes ps WHERE ps.product_id = p.id AND UPPER(ps.size_name) = ?)";
                $params[] = $size;
            }
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

        $this->attachProductSizes($products);
        $products = array_values(array_filter($products, fn($p) => ProductAttributeNormalizer::productMatchesConstraints($p, $args)));
        $this->attachProductColors($products);

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
        if (!empty($args['color'])) {
            $normalizedColor = ProductAttributeNormalizer::normalizeColor((string)$args['color']);
            if ($normalizedColor !== null) {
                $args['color'] = $normalizedColor;
            }
        }
        if (!empty($args['size'])) {
            $normalizedSize = ProductAttributeNormalizer::normalizeSize((string)$args['size']);
            if ($normalizedSize !== null) {
                $args['size'] = $normalizedSize;
            }
        }
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

    private function attachProductSizes(array &$products): void {
        $ids = array_values(array_filter(array_map(fn($p) => (int)($p['id'] ?? 0), $products), fn($id) => $id > 0));
        if ($ids === []) return;

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            $stmt = $this->pdo->prepare("SELECT product_id, size_name FROM product_sizes WHERE product_id IN ($placeholders)");
            $stmt->execute($ids);
            $sizesByProduct = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $productId = (int)($row['product_id'] ?? 0);
                $size = ProductAttributeNormalizer::normalizeSize((string)($row['size_name'] ?? ''));
                if ($productId > 0 && $size !== null) {
                    $sizesByProduct[$productId][] = ['size_name' => $size];
                }
            }
            foreach ($products as &$product) {
                $id = (int)($product['id'] ?? 0);
                $product['sizes'] = array_values(array_unique($sizesByProduct[$id] ?? [], SORT_REGULAR));
            }
            unset($product);
        } catch (Throwable $e) {
            foreach ($products as &$product) {
                $product['sizes'] = $product['sizes'] ?? [];
            }
            unset($product);
        }
    }

    private function attachProductColors(array &$products): void {
        foreach ($products as &$product) {
            $product['available_colors'] = ProductAttributeNormalizer::extractColorsFromProduct($product);
        }
        unset($product);
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
