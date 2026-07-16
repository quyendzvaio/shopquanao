<?php

require_once __DIR__ . '/PartialParseResult.php';
require_once __DIR__ . '/FastParser.php';
require_once __DIR__ . '/ConflictDetector.php';
require_once __DIR__ . '/ConflictResolver.php';
require_once __DIR__ . '/MergeEngine.php';

class IntentAndConstraintExtractor {
    public function extract(string $message, array $memoryContext = []): array {
        $parser = new FastParser();
        $partial = $parser->parse($message, $memoryContext)->toArray();
        $partial['conflicts'] = (new ConflictDetector())->detect($partial);
        $conflictResolution = (new ConflictResolver())->resolve($partial);

        return (new MergeEngine())->merge(
            $partial,
            ['used' => false, 'inferred_fields' => [], 'unresolved_remaining' => [], 'error' => null],
            $memoryContext,
            $conflictResolution
        );
    }

    private function primaryIntent(string $text, array $entities): string {
        if ($this->isOutfit($text)) return 'unsupported_outfit';
        if ($this->isCheckout($text)) return 'unsupported_checkout';
        if ($this->isOrder($text)) return 'order_status';
        if (isset($entities['product_id']) && ($this->isReturnExchange($text) || $this->isShipping($text))) return 'mixed_product_policy';
        if (isset($entities['product_id'])) return 'product_detail';
        if (!empty($entities['product_type']) && ($this->isReturnExchange($text) || $this->isShipping($text))) return 'mixed_product_policy';
        if ($this->isReturnExchange($text)) return 'return_exchange';
        if ($this->isShipping($text)) return 'shipping';
        if ($this->isPolicy($text)) return 'policy';
        if ($this->isSizeAdvice($text)) return 'size_advice';
        if ($this->isProductIntent($text, $entities)) return 'product_search';
        return 'unknown';
    }

    private function isProductIntent(string $text, array $entities): bool {
        return !empty($entities['product_type']) || preg_match('/sản phẩm|san pham|áo|ao|quần|quan|váy|vay|đầm|dam|phụ kiện|phu kien/ui', $text);
    }

    private function isReturnExchange(string $text): bool {
        return (bool)preg_match('/đổi trả|\bđổi\b|doi|trả hàng|tra hang|hoàn hàng|hoan hang|hoàn tiền|hoan tien|refund|return|không vừa|khong vua|sale|tem mác|tem mac/ui', $text);
    }

    private function isShipping(string $text): bool {
        return (bool)preg_match('/phí ship|phi ship|phí vận chuyển|phi van chuyen|ship|giao hàng|giao hang|vận chuyển|van chuyen/ui', $text);
    }

    private function isPolicy(string $text): bool {
        return (bool)preg_match('/bảo hành|bao hanh|thanh toán|thanh toan|cod|momo|vnpay|bán sỉ|ban si|hotline|địa chỉ|dia chi|giờ mở cửa|gio mo cua|chính sách|chinh sach/ui', $text);
    }

    private function isOrder(string $text): bool {
        return (bool)preg_match('/đơn của tôi|don cua toi|đơn hàng|don hang|mã đơn|ma don|trạng thái đơn|trang thai don|theo dõi đơn|theo doi don/ui', $text);
    }

    private function isSizeAdvice(string $text): bool {
        return (bool)preg_match('/\bsize\b|kích cỡ|kich co|mặc cỡ|mac co|mặc size|cao\s*\d+|nặng\s*\d+|\d+\s*kg|\d+\s*cm/ui', $text);
    }

    private function isOutfit(string $text): bool {
        return (bool)preg_match('/phối đồ|phoi do|phối với|phoi voi|mặc với|mac voi|outfit|set đồ|set do/ui', $text);
    }

    private function isCheckout(string $text): bool {
        return (bool)preg_match('/thêm vào giỏ|them vao gio|checkout|thanh toán giúp|thanh toan giup|mua .*giúp|mua .*giup|đặt hàng giúp|dat hang giup|chốt đơn|chot don/ui', $text);
    }

    private function extractProductId(string $message): ?int {
        if (preg_match('/(?:mã|ma|id|#|sản phẩm mã|san pham ma|product)\s*#?\s*(\d+)/ui', $message, $m)) {
            return max(1, (int)$m[1]);
        }
        if (preg_match('/(?:chi tiết|thông tin|xem)\s+(?:sản phẩm\s+)?#?\s*(\d+)/ui', $message, $m)) {
            return max(1, (int)$m[1]);
        }
        return null;
    }

    private function extractOrderId(string $message): ?int {
        if (preg_match('/(?:mã đơn|ma don|đơn hàng|don hang|order)\s*#?\s*(\d+)/ui', $message, $m)) {
            return max(1, (int)$m[1]);
        }
        return null;
    }

    private function extractProductType(string $text): ?string {
        $map = [
            '/bomber/ui' => 'áo khoác bomber',
            '/áo khoác|ao khoac/ui' => 'áo khoác',
            '/áo thun|ao thun/ui' => 'áo thun',
            '/áo phông|ao phong/ui' => 'áo phông',
            '/áo sơ mi|ao so mi/ui' => 'áo sơ mi',
            '/áo hoodie|ao hoodie/ui' => 'áo hoodie',
            '/áo polo|ao polo/ui' => 'áo polo',
            '/áo len|ao len/ui' => 'áo len',
            '/áo gile|ao gile/ui' => 'áo gile',
            '/áo vest|áo blazer|ao vest|ao blazer/ui' => 'áo vest',
            '/quần jeans|quần jean|quan jeans|quan jean/ui' => 'quần jeans',
            '/quần tây|quan tay/ui' => 'quần tây',
            '/quần kaki|quan kaki/ui' => 'quần kaki',
            '/quần short|quan short/ui' => 'quần short',
            '/quần jogger|quan jogger/ui' => 'quần jogger',
            '/váy maxi|vay maxi/ui' => 'váy maxi',
            '/chân váy|chan vay/ui' => 'chân váy',
            '/váy đầm|vay dam|\bđầm\b|\bdam\b/ui' => 'váy đầm',
            '/túi xách|tui xach/ui' => 'túi xách',
            '/đồng hồ|dong ho/ui' => 'đồng hồ',
            '/thắt lưng|that lung/ui' => 'thắt lưng',
            '/kính mát|kinh mat/ui' => 'kính mát',
            '/áo|ao/ui' => 'áo',
            '/quần|quan/ui' => 'quần',
            '/váy|vay/ui' => 'váy',
            '/phụ kiện|phu kien/ui' => 'phụ kiện',
        ];
        foreach ($map as $pattern => $value) {
            if ($value !== null && preg_match($pattern, $text)) return $value;
        }
        return null;
    }

    private function extractPriceConstraints(string $text): array {
        if (preg_match('/(?:từ|tu)?\s*(\d+(?:[.,]\d+)?)\s*(k|nghìn|ngan|triệu|trieu|m)?\s*(?:đến|den|tới|toi|-|->)\s*(\d+(?:[.,]\d+)?)\s*(k|nghìn|ngan|triệu|trieu|m)?/ui', $text, $m)) {
            return [
                'min_price' => $this->parsePrice($m[1], $m[2] ?? ''),
                'max_price' => $this->parsePrice($m[3], $m[4] ?? ($m[2] ?? '')),
            ];
        }
        if (preg_match('/(?:dưới|duoi|nhỏ hơn|nho hon|không quá|khong qua|tối đa|toi da|max)\s*(\d+(?:[.,]\d+)?)\s*(k|nghìn|ngan|triệu|trieu|m)?/ui', $text, $m)) {
            return ['max_price' => $this->parsePrice($m[1], $m[2] ?? '')];
        }
        if (preg_match('/(?:trên|tren|từ|tu|thấp nhất|min)\s*(\d+(?:[.,]\d+)?)\s*(k|nghìn|ngan|triệu|trieu|m)?/ui', $text, $m)) {
            return ['min_price' => $this->parsePrice($m[1], $m[2] ?? '')];
        }
        if (preg_match('/giá rẻ|gia re|\brẻ\b|\bre\b|bình dân|binh dan|tiết kiệm|tiet kiem/ui', $text)) {
            return ['max_price' => 300000];
        }
        return [];
    }

    private function parsePrice(string $raw, string $unit): int {
        $number = (float)str_replace(',', '.', $raw);
        $unit = mb_strtolower(trim($unit));
        if (in_array($unit, ['triệu', 'trieu', 'm'], true)) return (int)round($number * 1000000);
        if (in_array($unit, ['k', 'nghìn', 'ngan'], true)) return (int)round($number * 1000);
        if ($number > 0 && $number < 1000) return (int)round($number * 1000);
        return (int)round($number);
    }

    private function extractInt(string $pattern, string $text): ?int {
        return preg_match($pattern, $text, $m) ? (int)$m[1] : null;
    }
}
