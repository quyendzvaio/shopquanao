<?php

class IntentAndConstraintExtractor {
    public function extract(string $message, array $memoryContext = []): array {
        $text = trim($message);
        $lower = mb_strtolower($text);
        $entities = [];
        $requestedFields = [];
        $secondary = [];
        $missingSlots = [];

        $productId = $this->extractProductId($text);
        if ($productId !== null) {
            $entities['product_id'] = $productId;
            $requestedFields = array_merge($requestedFields, ['product_id', 'price', 'stock']);
        }

        $orderId = $this->extractOrderId($text);
        if ($orderId !== null) {
            $entities['order_id'] = $orderId;
            $requestedFields[] = 'order_status';
        }

        $productType = $this->extractProductType($lower);
        if ($productType !== null) {
            $entities['product_type'] = $productType;
        } elseif (!empty($memoryContext['slots']['product_type'])) {
            $entities['product_type'] = $memoryContext['slots']['product_type'];
        }

        $price = $this->extractPriceConstraints($lower);
        foreach ($price as $key => $value) {
            $entities[$key] = $value;
        }
        if (isset($entities['min_price']) || isset($entities['max_price']) || preg_match('/giá|gia|rẻ|re|đắt|dat|tầm|tam|khoảng|khoang/ui', $lower)) {
            $requestedFields[] = 'price';
        }
        if (preg_match('/còn hàng|con hang|tồn kho|ton kho|còn size|con size|hết hàng|het hang/ui', $lower)) {
            $requestedFields[] = 'stock';
        }

        $height = $this->extractInt('/(\d+)\s*cm/ui', $lower);
        if ($height === null && preg_match('/(\d+)\s*m\s*(\d+)/ui', $lower, $m)) {
            $height = ((int)$m[1] * 100) + (int)$m[2];
        }
        $weight = $this->extractInt('/(\d+)\s*kg/ui', $lower);
        if ($height !== null) $entities['height'] = $height;
        if ($weight !== null) $entities['weight'] = $weight;
        if (preg_match('/\b(xs|s|m|l|xl|xxl)\b/ui', $text, $m)) {
            $entities['size'] = strtoupper($m[1]);
            $requestedFields[] = 'size';
        }

        $primary = $this->primaryIntent($lower, $entities);
        if ($this->isReturnExchange($lower)) {
            $requestedFields[] = 'exchange_eligibility';
        }
        if ($this->isShipping($lower)) {
            $requestedFields[] = $this->isReturnExchange($lower) ? 'exchange_shipping_fee' : 'shipping_fee';
        }
        if ($primary === 'size_advice') {
            if (!isset($entities['height'])) $missingSlots[] = 'height';
            if (!isset($entities['weight'])) $missingSlots[] = 'weight';
        }

        if ($this->isReturnExchange($lower) && $primary !== 'return_exchange') $secondary[] = 'return_exchange';
        if ($this->isShipping($lower) && $primary !== 'shipping') $secondary[] = 'shipping';
        if ($this->isProductIntent($lower, $entities) && !in_array($primary, ['product_search', 'product_detail'], true)) $secondary[] = 'product_search';

        $subQueries = [];
        if ($primary === 'mixed_product_policy' || in_array('return_exchange', $secondary, true) || in_array('shipping', $secondary, true)) {
            $subQueries['knowledge'] = $text;
        }
        if (isset($entities['product_id'])) {
            $subQueries['product_detail'] = (string)$entities['product_id'];
        } elseif (!empty($entities['product_type'])) {
            $subQueries['product_search'] = (string)$entities['product_type'];
        }

        return [
            'original_query' => $text,
            'primary_intent' => $primary,
            'secondary_intents' => array_values(array_unique($secondary)),
            'entities' => $entities,
            'requested_fields' => array_values(array_unique($requestedFields)),
            'missing_slots' => array_values(array_unique($missingSlots)),
            'sub_queries' => $subQueries,
            'confidence' => $primary === 'unknown' ? 0.2 : 0.9,
        ];
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
