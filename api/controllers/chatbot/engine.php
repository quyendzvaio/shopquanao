<?php
/**
 * Chatbot intent classifier + knowledge retrieval
 * Uses keyword matching + DB queries (no external AI API).
 */
require_once __DIR__ . '/KnowledgeRetriever.php';

class ChatbotEngine {
    private $pdo;
    private $sessionId;
    private $userId;
    private $context = [];
    public $lastProducts = [];
    public $lastKnowledgeSources = [];

    const INTENTS = [
        'greeting'       => ['chào', 'hello', 'hi', 'xin chào', 'hey', 'helo', 'hí', 'chao'],
        'product_search' => ['tìm', 'kiếm', 'sản phẩm', 'có áo', 'có quần', 'có váy', 'còn hàng', 'giá', 'bao nhiêu', 'mua', 'rẻ', 'đắt', 'áo', 'quần', 'váy', 'đầm', 'khoác', 'thun', 'len', 'polo', 'gile', 'sơ mi', 'vest', 'hoodie', 'jean', 'jeans', 'baggy', 'jogger', 'short', 'kaki', 'bò', 'dưới', 'trên', 'khoảng', 'từ', 'đến', 'maxi', 'chân váy', 'phụ kiện', 'túi', 'mũ'],
        'product_detail' => ['chi tiết', 'thông tin', 'mô tả', 'hình ảnh', 'size', 'kích thước', 'chất liệu'],
        'size_advice'    => ['size', 'mặc size', 'chọn size', 'cao', 'nặng', 'cân nặng', 'chiều cao', 'kg', 'mặc vừa'],
        'order_status'   => ['đơn hàng', 'đơn', 'order', 'tra cứu', 'trạng thái đơn', 'đang giao', 'chờ xử lý'],
        'faq_shipping'   => ['giao hàng', 'vận chuyển', 'ship', 'phí ship', 'giao', 'nhận hàng', 'bao lâu'],
        'faq_return'     => ['đổi trả', 'trả hàng', 'đổi hàng', 'hoàn tiền', 'trả lại'],
        'faq_payment'    => ['thanh toán', 'chuyển khoản', 'momo', 'vnpay', 'cod', 'trả tiền', 'atm', 'visa'],
        'faq_warranty'   => ['bảo hành', 'bảo vệ', 'lỗi', 'hỏng'],
        'faq_wholesale'  => ['bán sỉ', 'buôn', 'sỉ', 'số lượng lớn'],
        'help'           => ['giúp', 'hỗ trợ', 'có thể', 'làm gì', 'tính năng', 'tư vấn', 'hướng dẫn'],
        'bye'            => ['tạm biệt', 'bye', 'cảm ơn', 'thank', 'goodbye', 'bái bai'],
        'unknown'        => [],
    ];

    // Keyword → tên sản phẩm trong DB (dùng cho name LIKE)
    // Sắp xếp theo độ dài GIẢM DẦN để khớp chính xác trước
    const SEARCH_KEYWORDS = [
        'áo sơ mi caro' => 'áo sơ mi',
        'áo sơ mi' => 'áo sơ mi',
        'áo bomber' => 'áo khoác bomber',
        'áo khoác da' => 'áo khoác',
        'áo khoác bomber' => 'áo khoác',
        'áo khoác nỉ' => 'áo khoác',
        'áo khoác' => 'áo khoác',
        'áo hoodie' => 'áo hoodie',
        'áo gile' => 'áo gile',
        'áo vest' => 'áo vest',
        'áo blazer' => 'áo vest',
        'áo len' => 'áo len',
        'áo polo' => 'áo polo',
        'áo thun' => 'áo thun',
        'áo phông' => 'áo phông',
        'áo dài tay' => 'áo dài tay',
        'áo' => null, // category match only
        'quần jeans' => 'quần jeans',
        'quần jean' => 'quần jeans',
        'quần tây' => 'quần tây',
        'quần kaki' => 'quần kaki',
        'quần baggy' => 'quần baggy',
        'quần jogger' => 'quần jogger',
        'quần short' => 'quần short',
        'quần bò' => 'quần',
        'quần' => null,
        'váy maxi' => 'váy maxi',
        'váy đầm' => 'váy đầm',
        'chân váy' => 'váy',
        'đầm' => 'váy đầm',
        'váy' => null,
        'phụ kiện' => null,
        'túi xách' => 'túi',
        'đồng hồ' => 'đồng hồ',
        'thắt lưng' => 'thắt lưng',
        'kính mát' => 'kính',
        'mũ' => 'mũ',
        'bông tai' => 'bông tai',
        'vòng cổ' => 'vòng cổ',
    ];

    const CATEGORY_MAP = [
        'áo' => 1, 'áo bomber' => 1, 'áo khoác' => 1, 'áo thun' => 1, 'áo len' => 1, 'áo sơ mi' => 1,
        'áo hoodie' => 1, 'áo gile' => 1, 'áo vest' => 1, 'áo polo' => 1, 'áo phông' => 1,
        'áo dài tay' => 1, 'quần' => 2, 'quần jean' => 2, 'quần tây' => 2, 'quần short' => 2,
        'quần kaki' => 2, 'quần baggy' => 2, 'quần jogger' => 2, 'quần bò' => 2,
        'váy' => 3, 'váy maxi' => 3, 'váy đầm' => 3, 'chân váy' => 3, 'đầm' => 3,
        'phụ kiện' => 4, 'túi' => 4, 'mũ' => 4, 'kính' => 4,
    ];

    public function __construct($pdo, $sessionId, $userId = null, $context = []) {
        $this->pdo = $pdo;
        $this->sessionId = $sessionId;
        $this->userId = $userId;
        $this->context = $context;
    }

    public function respond($message) {
        $this->lastProducts = [];
        $this->lastKnowledgeSources = [];
        if ($this->isOutfitRequest($message)) {
            return "Hiện mình không hỗ trợ tư vấn phối đồ. Mình có thể hỗ trợ bạn tìm sản phẩm, xem chi tiết sản phẩm, tư vấn size và chính sách shop.";
        }
        if ($this->isPurchaseAction($message)) {
            return "Mình không thể thêm giỏ hàng hoặc thanh toán thay bạn. Bạn vui lòng bấm vào thẻ sản phẩm hoặc trang chi tiết sản phẩm để tự thêm giỏ hàng và thanh toán nhé.";
        }
        if ($this->isProductPolicyRequest($message)) {
            $productPart = $this->handleProductSearch($message);
            $policyPart = $this->handlePolicyKnowledge($message);
            return trim($productPart . "\n\n" . $policyPart);
        }
        if ($this->isPolicyRequest($message)) {
            return $this->handlePolicyKnowledge($message);
        }
        if ($this->isSizeAdviceRequest($message)) {
            return $this->handleSizeAdvice($message);
        }
        $intent = $this->classify($message);
        $response = $this->execute($intent, $message);
        return $response;
    }

    private function classify($message) {
        $msg = mb_strtolower(trim($message));
        $scores = [];
        foreach (self::INTENTS as $intent => $keywords) {
            if (empty($keywords)) continue;
            $score = 0;
            foreach ($keywords as $kw) {
                $count = mb_substr_count($msg, $kw);
                if ($count > 0) {
                    $score += $count * (strlen($kw) / max(1, strlen($msg)) * 100);
                }
            }
            if ($score > 0) $scores[$intent] = round($score, 2);
        }
        if (empty($scores)) return 'unknown';
        arsort($scores);
        $topScore = current($scores);
        if ($topScore < 5) {
            if (preg_match('/\d+/', $msg)) return 'product_detail';
            return 'unknown';
        }
        return key($scores);
    }

    private function execute($intent, $message) {
        $handler = 'handle' . str_replace(' ', '', ucwords(str_replace('_', ' ', $intent)));
        if (method_exists($this, $handler)) return $this->$handler($message);
        return $this->handleUnknown($message);
    }

    private function handleGreeting($msg) {
        return 'Chào bạn, mình là trợ lý tư vấn của Fashion Shop. Hôm nay bạn muốn tìm sản phẩm nào?';
    }

    private function handleHelp($msg) {
        return "Mình có thể hỗ trợ bạn các nội dung sau:\n\n"
            . "- Tìm sản phẩm, ví dụ: \"áo khoác dưới 500k\"\n"
            . "- Tư vấn size, ví dụ: \"cao 1m7 nặng 65kg\"\n"
            . "- Tra cứu đơn hàng, ví dụ: \"đơn hàng của tôi\"\n"
            . "- Giải đáp chính sách đổi trả, giao hàng hoặc thanh toán\n\n"
            . "Bạn muốn mình hỗ trợ phần nào? Khi cần xem lại danh sách này, bạn có thể gõ \"giúp\".";
    }

    private function handleProductSearch($msg) {
        $this->lastProducts = [];
        $msgLower = mb_strtolower($msg);

        // 1. Xác định loại sản phẩm chính xác nhất (keyword dài nhất khớp trước)
        $matchedName = null;
        $matchedCatId = null;
        $matchedKeyword = '';

        // Sắp xếp keywords theo độ dài giảm dần
        $keywords = array_keys(self::SEARCH_KEYWORDS);
        usort($keywords, fn($a, $b) => mb_strlen($b) - mb_strlen($a));

        foreach ($keywords as $kw) {
            if (mb_strpos($msgLower, $kw) !== false) {
                $matchedKeyword = $kw;
                $matchedName = self::SEARCH_KEYWORDS[$kw];
                $matchedCatId = self::CATEGORY_MAP[$kw] ?? null;
                break;
            }
        }

        $slots = $this->context['slots'] ?? [];
        if (!$matchedName && !$matchedCatId && !empty($slots['product_type'])) {
            $productType = mb_strtolower((string)$slots['product_type']);
            $matchedKeyword = $productType;
            $matchedName = self::SEARCH_KEYWORDS[$productType] ?? $productType;
            $matchedCatId = $slots['category_id'] ?? (self::CATEGORY_MAP[$productType] ?? null);
            if (in_array($productType, ['áo', 'quần', 'váy', 'phụ kiện'], true)) {
                $matchedName = null;
            }
        }

        // 2. Xây SQL
        $sql = "SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE 1=1";
        $params = [];

        $isSqlite = $this->isSqlite();

        // 3. Filter theo loại sản phẩm
        if ($matchedName && !$isSqlite) {
            $sql .= " AND p.name LIKE ?";
            $params[] = "%$matchedName%";
        } elseif ($matchedCatId) {
            $sql .= " AND p.category_id = ?";
            $params[] = $matchedCatId;
        }

        if (!$isSqlite && !empty($slots['color']) && mb_strpos($msgLower, mb_strtolower((string)$slots['color'])) !== false) {
            $sql .= " AND p.name LIKE ?";
            $params[] = "%" . $slots['color'] . "%";
        }

        // 4. Price filter
        $priceMin = null; $priceMax = null;
        if (preg_match('/(\d+)\s*(k|nghìn|ngàn)\s*(đến|->|tới|toi|-)\s*(\d+)\s*k?/ui', $msg, $m)) {
            $priceMin = (float)$m[1] * 1000;
            $priceMax = (float)$m[4] * 1000;
        } elseif (preg_match('/(dưới|du?o[ỉ]?i|nhỏ hơn|<|<=)\s*(\d+)\s*k?/ui', $msg, $m)) {
            $multiplier = (mb_strpos($msg, $m[2].'k') !== false || mb_strpos($msg, $m[2].'K') !== false) ? 1000 : 1;
            // Nếu số <= 1000 thì coi là nghìn
            $val = (float)$m[2];
            $priceMax = $val * (($val < 1000 && $multiplier == 1) ? 1000 : $multiplier);
        } elseif (preg_match('/(trên|tren|lớn hơn|lon hon|>|>=|từ)\s*(\d+)\s*k?/ui', $msg, $m)) {
            $multiplier = (mb_strpos($msg, $m[2].'k') !== false) ? 1000 : 1;
            $val = (float)$m[2];
            $priceMin = $val * (($val < 1000 && $multiplier == 1) ? 1000 : $multiplier);
        }

        if ($priceMin === null && !empty($slots['min_price']) && !preg_match('/(trên|tren|lớn hơn|lon hon|>|>=|từ)\s*\d+/ui', $msg)) {
            $priceMin = (float)$slots['min_price'];
        }
        if ($priceMax === null && !empty($slots['max_price']) && !preg_match('/(dưới|du?o[ỉ]?i|nhỏ hơn|<|<=)\s*\d+/ui', $msg)) {
            $priceMax = (float)$slots['max_price'];
        }

        if ($priceMin !== null) { $sql .= " AND p.price >= ?"; $params[] = $priceMin; }
        if ($priceMax !== null) { $sql .= " AND p.price <= ?"; $params[] = $priceMax; }

        // 5. Stock > 0 mặc định (chỉ show hàng còn)
        $sql .= " AND p.stock > 0";

        // 6. Order + NO LIMIT — trả về tất cả
        $sql .= " ORDER BY p.price ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll();

        if ($isSqlite) {
            $products = array_values(array_filter($products, function($p) use ($matchedName, $slots, $msgLower) {
                $nameLower = mb_strtolower($p['name'] ?? '');
                if ($matchedName && mb_strpos($nameLower, mb_strtolower($matchedName)) === false) {
                    return false;
                }
                if (!empty($slots['color']) && mb_strpos($msgLower, mb_strtolower((string)$slots['color'])) !== false
                    && mb_strpos($nameLower, mb_strtolower((string)$slots['color'])) === false) {
                    return false;
                }
                return true;
            }));
        }

        if (!$products) {
            return "Hiện mình không tìm thấy sản phẩm phù hợp với yêu cầu của bạn. Bạn có thể thử từ khóa khác như \"áo khoác dưới 500k\", \"áo thun\", \"quần jeans\" hoặc \"váy maxi\".";
        }

        $base = getBaseUrl();
        $count = count($products);
        $productNames = array_map(fn($p) => (string)$p['name'], array_slice($products, 0, 3));
        $response = "Mình tìm thấy $count sản phẩm phù hợp";
        if (!empty($productNames)) {
            $response .= ": " . implode(', ', $productNames);
            if ($count > 3) {
                $response .= " và một số sản phẩm khác";
            }
        }
        $response .= ". Bạn có thể bấm vào thẻ sản phẩm bên dưới để xem chi tiết.";

        foreach ($products as $p) {
            $url = "$base/product.php?id={$p['id']}";
            $this->lastProducts[] = [
                'id' => (int)$p['id'],
                'name' => $p['name'],
                'price' => (float)$p['price'],
                'stock' => (int)($p['stock'] ?? 0),
                'image' => $p['image'] ?? '',
                'image_url' => $base . '/images/' . ($p['image'] ?? ''),
                'url' => $url,
            ];
        }

        return $response;
    }

    private function handleProductDetail($msg) {
        $id = null;
        if (preg_match('/\b(\d+)\b/', $msg, $m)) $id = (int)$m[0];
        if (!$id) return 'Bạn muốn xem chi tiết sản phẩm nào? Bạn có thể gửi mã sản phẩm để mình kiểm tra.';

        $stmt = $this->pdo->prepare("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.id = ?");
        $stmt->execute([$id]);
        $p = $stmt->fetch();
        if (!$p) return "Mình chưa tìm thấy sản phẩm #$id.";

        $base = getBaseUrl();
        $this->lastProducts[] = [
            'id' => (int)$p['id'], 'name' => $p['name'], 'price' => (float)$p['price'],
            'stock' => (int)($p['stock'] ?? 0), 'image' => $p['image'] ?? '',
            'image_url' => $base . '/images/' . ($p['image'] ?? ''),
            'url' => $base . '/product.php?id=' . (int)$p['id'],
        ];

        $stmt = $this->pdo->prepare("SELECT size_name FROM product_sizes WHERE product_id = ?");
        $stmt->execute([$id]);
        $sizes = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $stmt = $this->pdo->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as total FROM reviews WHERE product_id = ?");
        $stmt->execute([$id]);
        $rating = $stmt->fetch();

        $stockText = ($p['stock'] ?? 0) > 0 ? "Còn {$p['stock']}" : "Hết hàng";
        $sizeText = $sizes ? implode(', ', $sizes) : 'Freesize';
        $ratingText = $rating['total'] > 0 ? round($rating['avg_rating'], 1) . "/5" : "Chưa có đánh giá";

        return "{$p['name']}\n"
            . "Giá: " . number_format($p['price']) . "đ\n"
            . "Tình trạng: $stockText\n"
            . "Size: $sizeText\n"
            . "Đánh giá: $ratingText\n"
            . "Mô tả: {$p['description']}";
    }

    private function handleSizeAdvice($msg) {
        $height = null; $weight = null;
        if (preg_match('/(\d+)\s*m\s*(\d+)/i', $msg, $m)) { $height = (int)$m[1] * 100 + (int)$m[2]; }
        elseif (preg_match('/(\d+)\s*cm/i', $msg, $m)) { $height = (int)$m[1]; }
        elseif (preg_match('/1m(\d+)/i', $msg, $m)) { $height = 100 + (int)$m[1]; }
        elseif (preg_match('/cao\s*(\d+)/ui', $msg, $m)) { $height = (int)$m[1]; if ($height < 50) $height *= 100; }
        if (preg_match('/nặng\s*(\d+)/ui', $msg, $m)) { $weight = (int)$m[1]; }
        elseif (preg_match('/(\d+)\s*kg/i', $msg, $m)) { $weight = (int)$m[1]; }

        if (!$height || !$weight) {
            return "Để tư vấn size chính xác hơn, bạn cho mình biết chiều cao và cân nặng nhé. Ví dụ: \"cao 1m7 nặng 65kg\".";
        }

        $catId = null;
        foreach (['áo' => 1, 'quần' => 2, 'váy' => 3, 'đầm' => 3] as $k => $v) {
            if (mb_strpos($msg, $k) !== false) { $catId = $v; break; }
        }

        $orderBy = $this->isSqlite()
            ? "ORDER BY CASE size_name WHEN 'S' THEN 1 WHEN 'M' THEN 2 WHEN 'L' THEN 3 WHEN 'XL' THEN 4 ELSE 5 END"
            : "ORDER BY FIELD(size_name, 'S','M','L','XL')";
        $sql = "SELECT * FROM size_guides " . ($catId ? "WHERE category_id = ? " : "") . $orderBy;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($catId ? [$catId] : []);
        $sizes = $stmt->fetchAll();

        if (!$sizes) return "Chưa có bảng size cho danh mục này.";

        $response = "Tư vấn size cho chiều cao {$height}cm và cân nặng {$weight}kg:\n\n";
        $recommended = null;
        foreach ($sizes as $s) {
            $hOk = (!$s['height_from'] || $height >= (int)$s['height_from']) && (!$s['height_to'] || $height <= (int)$s['height_to']);
            $wOk = (!$s['weight_from'] || $weight >= (int)$s['weight_from']) && (!$s['weight_to'] || $weight <= (int)$s['weight_to']);
            if ($hOk && $wOk && !$recommended) $recommended = $s;
        }

        if ($recommended) {
            $response .= "Size phù hợp: " . strtoupper($recommended['size_name']) . "\n{$recommended['description']}\n\n";
        } else {
            $response .= "Bạn đang nằm ngoài bảng size tham khảo. Nếu thích mặc rộng, bạn nên chọn size lớn hơn; nếu thích ôm vừa, chọn size nhỏ hơn.\n\n";
        }

        $response .= "Bảng size tham khảo:\n";
        foreach ($sizes as $s) {
            $isRec = $recommended && $s['size_name'] === $recommended['size_name'];
            $response .= ($isRec ? "Khuyến nghị - " : "") . strtoupper($s['size_name']) . ": ";
            $response .= "Cao " . ($s['height_from'] ?? '?') . "-" . ($s['height_to'] ?? '?') . "cm, ";
            $response .= "Nặng " . ($s['weight_from'] ?? '?') . "-" . ($s['weight_to'] ?? '?') . "kg";
            $response .= "\n";
        }
        return $response;
    }

    private function handleOrderStatus($msg) {
        if (!$this->userId) return "Bạn vui lòng đăng nhập để tra cứu đơn hàng.";
        $orderId = null;
        if (preg_match('/#?\s*(\d+)/', $msg, $m)) $orderId = (int)$m[1];

        if ($orderId) {
            $stmt = $this->pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
            $stmt->execute([$orderId, $this->userId]);
            $order = $stmt->fetch();
            if (!$order) return "Không tìm thấy đơn hàng #$orderId.";
            return "Đơn hàng #{$order['id']}\n"
                . "Tổng tiền: " . number_format($order['total_price']) . "đ\n"
                . "Trạng thái: {$order['status']}\n"
                . "Ngày tạo: {$order['created_at']}";
        } else {
            $stmt = $this->pdo->prepare("SELECT id, total_price, status, created_at FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
            $stmt->execute([$this->userId]);
            $orders = $stmt->fetchAll();
            if (!$orders) return "Bạn chưa có đơn hàng nào.";
            $response = "Các đơn hàng gần đây của bạn:\n\n";
            foreach ($orders as $o) {
                $response .= "#{$o['id']} - " . number_format($o['total_price']) . "đ - {$o['status']}\n{$o['created_at']}\n";
            }
            return $response . "\nGõ \"đơn #\" + số để xem chi tiết.";
        }
    }

    private function handleFaqShipping($msg) { return $this->queryFaq('shipping'); }
    private function handleFaqReturn($msg) { return $this->queryFaq('return'); }
    private function handleFaqPayment($msg) { return $this->queryFaq('payment'); }
    private function handleFaqWarranty($msg) { return $this->queryFaq('warranty'); }
    private function handleFaqWholesale($msg) { return $this->queryFaq('wholesale'); }

    private function queryFaq($category) {
        return $this->handlePolicyKnowledge($category);
    }

    private function handleBye($msg) {
        return ['Cảm ơn bạn. Chúc bạn một ngày tốt lành.', 'Hẹn gặp lại bạn.', 'Cảm ơn bạn đã ghé Fashion Shop.'][array_rand([0,1,2])];
    }

    private function handleUnknown($msg) {
        return "Mình chưa hiểu rõ yêu cầu của bạn.\n\n"
            . "Bạn có thể gõ \"giúp\" để xem các nội dung mình hỗ trợ, hoặc hỏi trực tiếp:\n"
            . "- \"tìm áo khoác dưới 500k\"\n"
            . "- \"chọn size cho 1m7 65kg\"\n"
            . "- \"shop đổi trả trong bao lâu\"";
    }

    private function isOutfitRequest(string $message): bool {
        return (bool)preg_match('/phối đồ|mặc với|kết hợp|set đồ|outfit|phối với/ui', $message);
    }

    private function isPurchaseAction(string $message): bool {
        return (bool)preg_match('/thêm vào giỏ|thêm giỏ|checkout|thanh toán giúp|mua .*giúp|đặt hàng giúp|chốt đơn/ui', $message);
    }

    private function isPolicyRequest(string $message): bool {
        return (bool)preg_match('/đổi trả|trả hàng|đổi hàng|\bđổi\b|không vừa|hoàn tiền|giao hàng|phí ship|ship|vận chuyển|bảo hành|thanh toán|cod|momo|vnpay|bán sỉ|hàng sale|sale|lỗi đường may|lỗi sản phẩm/ui', $message);
    }

    private function isProductPolicyRequest(string $message): bool {
        return $this->isPolicyRequest($message)
            && (bool)preg_match('/bomber|hoodie|polo|jeans|sơ mi|áo thun|áo khoác|váy maxi|chân váy|quần tây|quần short|mã\s*\d+|sản phẩm này/ui', $message);
    }

    private function isSizeAdviceRequest(string $message): bool {
        return (bool)preg_match('/\bsize\b|chọn size|mặc size|kích cỡ|mặc vừa/ui', $message)
            && !(bool)preg_match('/mã\s*\d+|sản phẩm\s*mã|chi tiết/ui', $message);
    }

    private function handlePolicyKnowledge(string $message): string {
        $retriever = new KnowledgeRetriever($this->pdo);
        $category = $this->inferPolicyCategory($message);
        $result = $retriever->search($message, $category, 5);
        $rows = $result['results'] ?? [];
        $this->lastKnowledgeSources = array_map(fn($item) => [
            'source' => (string)($item['source'] ?? ''),
            'title' => (string)($item['title'] ?? ''),
            'category' => (string)($item['category'] ?? ''),
            'score' => isset($item['score']) ? (float)$item['score'] : null,
        ], $rows);

        if (!$rows) {
            return "Hiện mình chưa có đủ thông tin trong dữ liệu chính sách shop. Bạn mô tả rõ hơn giúp mình nhé.";
        }

        $facts = $this->extractPolicyFacts($message, $rows);
        if ($facts !== '') return $facts;

        $response = "Theo chính sách shop:\n";
        foreach (array_slice($rows, 0, 2) as $row) {
            $content = trim(strip_tags((string)($row['content'] ?? '')));
            $content = preg_replace('/\s+/u', ' ', $content);
            if (mb_strlen($content) > 260) $content = mb_substr($content, 0, 260) . '...';
            $response .= "- " . $content . "\n";
        }
        return trim($response);
    }

    private function inferPolicyCategory(string $message): ?string {
        $msg = mb_strtolower($message);
        if (preg_match('/hoàn tiền|refund/ui', $msg)) return 'policy';
        if (preg_match('/đổi|trả|sale|size/ui', $msg)) return 'return';
        if (preg_match('/ship|giao|vận chuyển/ui', $msg)) return 'shipping';
        if (preg_match('/bảo hành|lỗi/ui', $msg)) return 'warranty';
        if (preg_match('/thanh toán|cod|momo|vnpay/ui', $msg)) return 'payment';
        if (preg_match('/bán sỉ|sỉ/ui', $msg)) return 'wholesale';
        return null;
    }

    private function extractPolicyFacts(string $message, array $rows): string {
        $text = mb_strtolower($message);
        $corpus = implode("\n", array_map(fn($r) => (string)($r['content'] ?? ''), $rows));
        $lines = preg_split('/\R/u', $corpus) ?: [];
        $matched = [];

        foreach ($lines as $line) {
            $clean = trim(preg_replace('/^[\-\*\d\.\s]+/u', '', strip_tags($line)) ?? '');
            if ($clean === '') continue;
            $cleanLower = mb_strtolower($clean);
            if (mb_strpos($text, 'sale') !== false && (mb_strpos($cleanLower, 'sale') !== false || mb_strpos($cleanLower, '50') !== false)) $matched[] = $clean;
            if ((mb_strpos($text, 'đổi') !== false || mb_strpos($text, 'trả') !== false) && preg_match('/7 ngày|tem mác|chưa qua sử dụng|mã đơn hàng|lý do đổi trả|đổi size|size\/màu/ui', $clean)) $matched[] = $clean;
            if ((mb_strpos($text, 'lỗi') !== false || mb_strpos($text, 'ship') !== false) && preg_match('/shop chịu|khách thanh toán|1-3 ngày|vận chuyển/ui', $clean)) $matched[] = $clean;
            if ((mb_strpos($text, 'ship') !== false || mb_strpos($text, '300') !== false || mb_strpos($text, '500') !== false) && preg_match('/500|30,000|50,000|miễn phí ship/ui', $clean)) $matched[] = $clean;
            if (mb_strpos($text, 'hoàn tiền') !== false && preg_match('/hoàn tiền|3-7 ngày/ui', $clean)) $matched[] = $clean;
        }

        $matched = array_values(array_unique($matched));
        if (!$matched) return '';
        usort($matched, function($a, $b) use ($text) {
            $scoreA = (preg_match('/shop chịu|đổi size|khách thanh toán|vận chuyển hai chiều/ui', $a) ? 5 : 0)
                + (preg_match('/1-3 ngày|3-7 ngày/ui', $a) ? 3 : 0)
                + (mb_strpos($text, 'mấy ngày') !== false && preg_match('/ngày/ui', $a) ? 2 : 0);
            $scoreB = (preg_match('/shop chịu|đổi size|khách thanh toán|vận chuyển hai chiều/ui', $b) ? 5 : 0)
                + (preg_match('/1-3 ngày|3-7 ngày/ui', $b) ? 3 : 0)
                + (mb_strpos($text, 'mấy ngày') !== false && preg_match('/ngày/ui', $b) ? 2 : 0);
            return $scoreB <=> $scoreA;
        });
        return "Theo chính sách shop:\n- " . implode("\n- ", array_slice($matched, 0, 6));
    }

    private function saveContext($intent, $message, $response) {
        $stmt = $this->pdo->prepare("INSERT INTO chat_messages (session_id, role, message, metadata) VALUES (?, 'user', ?, ?)");
        $stmt->execute([$this->sessionId, $message, json_encode(['intent' => $intent])]);
        $meta = !empty($this->lastProducts) ? json_encode(['products' => $this->lastProducts], JSON_UNESCAPED_UNICODE) : null;
        $stmt = $this->pdo->prepare("INSERT INTO chat_messages (session_id, role, message, metadata) VALUES (?, 'bot', ?, ?)");
        $stmt->execute([$this->sessionId, $response, $meta]);
        $this->pdo->prepare("UPDATE chat_sessions SET updated_at = NOW() WHERE id = ?")->execute([$this->sessionId]);
    }

    private function isSqlite(): bool {
        try {
            return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        } catch (Throwable $e) {
            return false;
        }
    }
}

function getBaseUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8090';
    return "$protocol://$host";
}

/**
 * Get internal API base URL (for PHP-to-PHP calls within same container).
 * Uses port 80 internally since PHP runs inside Apache on default HTTP port.
 */
function getInternalApiUrl() {
    return 'http://localhost';
}
