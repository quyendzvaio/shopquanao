<?php
/**
 * Chatbot intent classifier + knowledge retrieval
 * Uses keyword matching + DB queries (no external AI API).
 */
require_once __DIR__ . '/../../config.php';

class ChatbotEngine {
    private $pdo;
    private $sessionId;
    private $userId;
    private $context = [];
    public $lastProducts = [];

    const INTENTS = [
        'greeting'       => ['chào', 'hello', 'hi', 'xin chào', 'hey', 'helo', 'hí', 'chao'],
        'product_search' => ['tìm', 'kiếm', 'sản phẩm', 'có áo', 'có quần', 'có váy', 'còn hàng', 'giá', 'bao nhiêu', 'mua', 'rẻ', 'đắt', 'khoác', 'thun', 'len', 'jean', 'váy', 'đầm', 'dưới', 'trên', 'khoảng', 'từ', 'đến'],
        'product_detail' => ['chi tiết', 'thông tin', 'mô tả', 'hình ảnh', 'size', 'kích thước', 'chất liệu'],
        'size_advice'    => ['size', 'mặc size', 'chọn size', 'cao', 'nặng', 'cân nặng', 'chiều cao', 'kg', 'mặc vừa'],
        'order_status'   => ['đơn hàng', 'đơn', 'order', 'tra cứu', 'trạng thái đơn', 'đang giao', 'chờ xử lý'],
        'outfit'         => ['phối đồ', 'kết hợp', 'mặc với', 'set đồ', 'outfit', 'phong cách', 'mặc chung'],
        'faq_shipping'   => ['giao hàng', 'vận chuyển', 'ship', 'phí ship', 'giao', 'nhận hàng', 'bao lâu'],
        'faq_return'     => ['đổi trả', 'trả hàng', 'đổi hàng', 'hoàn tiền', 'trả lại'],
        'faq_payment'    => ['thanh toán', 'chuyển khoản', 'momo', 'vnpay', 'cod', 'trả tiền', 'atm', 'visa'],
        'faq_warranty'   => ['bảo hành', 'bảo vệ', 'lỗi', 'hỏng'],
        'faq_wholesale'  => ['bán sỉ', 'buôn', 'sỉ', 'số lượng lớn'],
        'cart'           => ['giỏ hàng', 'cart', 'thêm vào giỏ', 'xóa giỏ', 'sửa giỏ', 'giỏ'],
        'help'           => ['giúp', 'hỗ trợ', 'có thể', 'làm gì', 'tính năng', 'tư vấn', 'hướng dẫn'],
        'bye'            => ['tạm biệt', 'bye', 'cảm ơn', 'thank', 'goodbye', 'bái bai'],
        'unknown'        => [],
    ];

    // Keyword → tên sản phẩm trong DB (dùng cho name LIKE)
    // Sắp xếp theo độ dài GIẢM DẦN để khớp chính xác trước
    const SEARCH_KEYWORDS = [
        'áo sơ mi caro' => 'áo sơ mi',
        'áo sơ mi' => 'áo sơ mi',
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
        'quần jeans' => 'quần jean',
        'quần jean' => 'quần jean',
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
        'áo' => 1, 'áo khoác' => 1, 'áo thun' => 1, 'áo len' => 1, 'áo sơ mi' => 1,
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
        return '👋 Chào bạn! Mình là trợ lý Fashion Shop. Cần tư vấn gì hôm nay? Gõ "giúp" xem mình làm được gì nhé!';
    }

    private function handleHelp($msg) {
        return "🤖 **Mình có thể giúp gì?**\n\n"
            . "🔍 **Tìm sản phẩm:** \"áo khoác dưới 500k\"\n"
            . "📏 **Tư vấn size:** \"cao 1m7 nặng 65kg\"\n"
            . "👔 **Phối đồ:** \"áo thun trắng mặc với quần gì\"\n"
            . "📦 **Tra đơn:** \"đơn hàng của tôi\"\n"
            . "❓ **FAQ:** \"chính sách đổi trả?\"\n\n"
            . "Bạn muốn hỏi gì?";
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

        // 2. Xây SQL
        $sql = "SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE 1=1";
        $params = [];

        // 3. Filter theo loại sản phẩm
        if ($matchedName) {
            $sql .= " AND p.name LIKE ?";
            $params[] = "%$matchedName%";
        } elseif ($matchedCatId) {
            $sql .= " AND p.category_id = ?";
            $params[] = $matchedCatId;
        }

        // 4. Price filter
        $priceMin = null; $priceMax = null;
        if (preg_match('/(dưới|du?o[ỉ]?i|nhỏ hơn|<|<=)\s*(\d+)\s*k?/ui', $msg, $m)) {
            $multiplier = (mb_strpos($msg, $m[2].'k') !== false || mb_strpos($msg, $m[2].'K') !== false) ? 1000 : 1;
            // Nếu số <= 1000 thì coi là nghìn
            $val = (float)$m[2];
            $priceMax = $val * (($val < 1000 && $multiplier == 1) ? 1000 : $multiplier);
        } elseif (preg_match('/(trên|tren|lớn hơn|lon hon|>|>=|từ)\s*(\d+)\s*k?/ui', $msg, $m)) {
            $multiplier = (mb_strpos($msg, $m[2].'k') !== false) ? 1000 : 1;
            $val = (float)$m[2];
            $priceMin = $val * (($val < 1000 && $multiplier == 1) ? 1000 : $multiplier);
        } elseif (preg_match('/(\d+)\s*(k|nghìn|ngàn)\s*(đến|->|tới|tới)\s*(\d+)\s*k?/ui', $msg, $m)) {
            $priceMin = (float)$m[1] * 1000;
            $priceMax = (float)$m[4] * 1000;
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

        if (!$products) {
            return "😅 Rất tiếc, không tìm thấy sản phẩm phù hợp với yêu cầu của bạn. Bạn thử tìm với từ khóa khác nhé!\n\n"
                . "VD: \"áo khoác dưới 500k\", \"áo thun\", \"quần jeans\", \"váy maxi\"";
        }

        $base = getBaseUrl();
        $count = count($products);
        $response = "**Tìm thấy $count sản phẩm phù hợp:**\n\n";

        foreach ($products as $p) {
            $url = "$base/product.php?id={$p['id']}";
            $response .= "🖼️ **{$p['name']}** — 💰 " . number_format($p['price']) . "đ\n";
            $response .= "  📦 Còn {$p['stock']} | 🔗 $url\n\n";

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
        if (!$id) return 'Bạn muốn xem sản phẩm nào? Gõ "sp 50" nhé!';

        $stmt = $this->pdo->prepare("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.id = ?");
        $stmt->execute([$id]);
        $p = $stmt->fetch();
        if (!$p) return "Không tìm thấy sản phẩm #$id";

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

        $stockText = ($p['stock'] ?? 0) > 0 ? "✅ Còn {$p['stock']}" : "❌ Hết hàng";
        $sizeText = $sizes ? implode(', ', $sizes) : 'Freesize';
        $ratingText = $rating['total'] > 0 ? "⭐ " . round($rating['avg_rating'], 1) . "/5" : "⭐ Chưa có đánh giá";

        return "**{$p['name']}**\n"
            . "💰 Giá: " . number_format($p['price']) . "đ\n"
            . "📦 $stockText | 📏 Size: $sizeText\n"
            . "$ratingText\n"
            . "📝 {$p['description']}\n"
            . "🔗 $base/product.php?id={$p['id']}";
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
            return "📏 Để tư vấn size, bạn cho mình biết chiều cao (cm) và cân nặng (kg) nhé!\n"
                . 'VD: "cao 1m7 nặng 65kg"';
        }

        $catId = null;
        foreach (['áo' => 1, 'quần' => 2, 'váy' => 3, 'đầm' => 3] as $k => $v) {
            if (mb_strpos($msg, $k) !== false) { $catId = $v; break; }
        }

        $sql = "SELECT * FROM size_guides " . ($catId ? "WHERE category_id = ? " : "") . "ORDER BY FIELD(size_name, 'S','M','L','XL')";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($catId ? [$catId] : []);
        $sizes = $stmt->fetchAll();

        if (!$sizes) return "Chưa có bảng size cho danh mục này.";

        $response = "📏 **Tư vấn size** (Cao {$height}cm, Nặng {$weight}kg)\n\n";
        $recommended = null;
        foreach ($sizes as $s) {
            $hOk = (!$s['height_from'] || $height >= (int)$s['height_from']) && (!$s['height_to'] || $height <= (int)$s['height_to']);
            $wOk = (!$s['weight_from'] || $weight >= (int)$s['weight_from']) && (!$s['weight_to'] || $weight <= (int)$s['weight_to']);
            if ($hOk && $wOk && !$recommended) $recommended = $s;
        }

        if ($recommended) {
            $response .= "✅ **Size phù hợp: " . strtoupper($recommended['size_name']) . "**\n  {$recommended['description']}\n\n";
        } else {
            $response .= "⚠️ Bạn nằm ngoài bảng size. Chọn size lớn nếu thích rộng, nhỏ nếu thích ôm.\n\n";
        }

        $response .= "**Bảng size tham khảo:**\n";
        foreach ($sizes as $s) {
            $isRec = $recommended && $s['size_name'] === $recommended['size_name'];
            $response .= ($isRec ? "👉 **" : "  ") . strtoupper($s['size_name']) . ": ";
            $response .= "Cao " . ($s['height_from'] ?? '?') . "-" . ($s['height_to'] ?? '?') . "cm, ";
            $response .= "Nặng " . ($s['weight_from'] ?? '?') . "-" . ($s['weight_to'] ?? '?') . "kg";
            $response .= ($isRec ? "** ✅" : "") . "\n";
        }
        return $response;
    }

    private function handleOutfit($msg) {
        $stmt = $this->pdo->query("
            SELECT o.*, p1.name as product_name, p2.name as paired_name, p2.price as paired_price, p2.image as paired_image
            FROM outfit_suggestions o
            JOIN products p1 ON o.product_id = p1.id
            JOIN products p2 ON o.paired_product_id = p2.id
            ORDER BY o.id
        ");
        $outfits = $stmt->fetchAll();
        $matched = [];
        foreach ($outfits as $o) {
            if (mb_strpos($o['product_name'], $msg) !== false || mb_strpos($o['paired_name'], $msg) !== false
                || preg_match('/\b(' . $o['product_id'] . '|' . $o['paired_product_id'] . ')\b/', $msg)) {
                $matched[] = $o;
            }
        }
        if (!$matched) $matched = $outfits;
        $response = "👔 **Gợi ý phối đồ:**\n\n";
        foreach ($matched as $o) {
            $response .= "• **{$o['product_name']}** → **{$o['paired_name']}**\n  💡 {$o['note']}\n\n";
        }
        return $response;
    }

    private function handleOrderStatus($msg) {
        if (!$this->userId) return "🔒 Vui lòng đăng nhập để tra cứu đơn hàng.";
        $orderId = null;
        if (preg_match('/#?\s*(\d+)/', $msg, $m)) $orderId = (int)$m[1];

        if ($orderId) {
            $stmt = $this->pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
            $stmt->execute([$orderId, $this->userId]);
            $order = $stmt->fetch();
            if (!$order) return "Không tìm thấy đơn hàng #$orderId.";
            $e = ['Chờ xử lý' => '⏳', 'Đang giao' => '🚚', 'Đã hoàn thành' => '✅', 'Đã hủy' => '❌'];
            return ($e[$order['status']] ?? '📦') . " **Đơn hàng #{$order['id']}**\n"
                . "  💰 " . number_format($order['total_price']) . "đ\n  📌 **{$order['status']}**\n  📅 {$order['created_at']}";
        } else {
            $stmt = $this->pdo->prepare("SELECT id, total_price, status, created_at FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
            $stmt->execute([$this->userId]);
            $orders = $stmt->fetchAll();
            if (!$orders) return "Bạn chưa có đơn hàng nào.";
            $response = "📦 **Đơn hàng của bạn:**\n\n";
            foreach ($orders as $o) {
                $e = ['Chờ xử lý' => '⏳', 'Đang giao' => '🚚', 'Đã hoàn thành' => '✅', 'Đã hủy' => '❌'];
                $response .= ($e[$o['status']] ?? '📦') . " #{$o['id']} — " . number_format($o['total_price']) . "đ — **{$o['status']}**\n   {$o['created_at']}\n";
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
        $stmt = $this->pdo->prepare("SELECT question, answer FROM faqs WHERE category = ? ORDER BY priority LIMIT 3");
        $stmt->execute([$category]);
        $faqs = $stmt->fetchAll();
        if (!$faqs) return "❓ Bạn tham khảo FAQ trên website hoặc hỏi chi tiết hơn!";
        $response = "❓ **FAQ — " . ['shipping'=>'Vận chuyển','return'=>'Đổi trả','payment'=>'Thanh toán','warranty'=>'Bảo hành','wholesale'=>'Bán sỉ'][$category] . "**\n\n";
        foreach ($faqs as $f) $response .= "**Q:** {$f['question']}\n**A:** {$f['answer']}\n\n";
        return $response . "Còn thắc mắc gì bạn cứ hỏi nhé!";
    }

    private function handleCart($msg) {
        if (!$this->userId) return "🔒 Vui lòng đăng nhập để xem giỏ hàng.";
        $stmt = $this->pdo->prepare("SELECT c.id, p.name, p.price, c.quantity, c.size FROM cart c JOIN products p ON c.product_id = p.id WHERE c.user_id = ?");
        $stmt->execute([$this->userId]);
        $items = $stmt->fetchAll();
        if (!$items) return "🛒 Giỏ hàng trống. Bạn muốn mua gì hôm nay?";
        $total = 0; $response = "🛒 **Giỏ hàng của bạn:**\n\n";
        foreach ($items as $i) { $sub = (float)$i['price'] * (int)$i['quantity']; $total += $sub; $response .= "• {$i['name']} x{$i['quantity']}: " . number_format($sub) . "đ\n"; }
        $response .= "\n**Tổng: " . number_format($total) . "đ**\n🔗 " . getBaseUrl() . "/cart.php";
        return $response;
    }

    private function handleBye($msg) {
        return ['Cảm ơn bạn! Chúc bạn một ngày tốt lành 🌟', 'Hẹn gặp lại! 😊', 'Cảm ơn đã ghé shop! 💙'][array_rand([0,1,2])];
    }

    private function handleUnknown($msg) {
        return "🤔 Mình chưa hiểu ý bạn lắm.\n\n"
            . "Bạn thử gõ **\"giúp\"** để xem mình làm được gì nhé!\n\n"
            . "Hoặc hỏi trực tiếp:\n"
            . "• \"tìm áo khoác dưới 500k\"\n"
            . "• \"chọn size cho 1m7 65kg\"\n"
            . "• \"phối đồ với áo thun trắng\"";
    }

    private function saveContext($intent, $message, $response) {
        $stmt = $this->pdo->prepare("INSERT INTO chat_messages (session_id, role, message, metadata) VALUES (?, 'user', ?, ?)");
        $stmt->execute([$this->sessionId, $message, json_encode(['intent' => $intent])]);
        $meta = !empty($this->lastProducts) ? json_encode(['products' => $this->lastProducts], JSON_UNESCAPED_UNICODE) : null;
        $stmt = $this->pdo->prepare("INSERT INTO chat_messages (session_id, role, message, metadata) VALUES (?, 'bot', ?, ?)");
        $stmt->execute([$this->sessionId, $response, $meta]);
        $this->pdo->prepare("UPDATE chat_sessions SET updated_at = NOW() WHERE id = ?")->execute([$this->sessionId]);
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
