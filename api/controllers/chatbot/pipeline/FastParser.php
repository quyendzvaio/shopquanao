<?php

class FastParser {
    private const PRODUCT_TYPES = [
        '/áo khoác bomber|ao khoac bomber|áo bomber|ao bomber|bomber/ui' => ['áo khoác bomber', 1, 'jacket'],
        '/áo sơ mi|ao so mi/ui' => ['áo sơ mi', 1, 'shirt'],
        '/áo khoác|ao khoac/ui' => ['áo khoác', 1, 'jacket'],
        '/áo thun|ao thun/ui' => ['áo thun', 1, 't_shirt'],
        '/áo phông|ao phong/ui' => ['áo phông', 1, 't_shirt'],
        '/áo hoodie|ao hoodie/ui' => ['áo hoodie', 1, 'hoodie'],
        '/áo polo|ao polo/ui' => ['áo polo', 1, 'polo'],
        '/áo len|ao len/ui' => ['áo len', 1, 'sweater'],
        '/áo gile|ao gile/ui' => ['áo gile', 1, 'vest'],
        '/áo vest|áo blazer|ao vest|ao blazer/ui' => ['áo vest', 1, 'blazer'],
        '/quần jeans|quần jean|quan jeans|quan jean/ui' => ['quần jeans', 2, 'jeans'],
        '/quần tây|quan tay/ui' => ['quần tây', 2, 'trousers'],
        '/quần kaki|quan kaki/ui' => ['quần kaki', 2, 'trousers'],
        '/quần short|quan short/ui' => ['quần short', 2, 'shorts'],
        '/quần jogger|quan jogger/ui' => ['quần jogger', 2, 'joggers'],
        '/váy maxi|vay maxi/ui' => ['váy maxi', 3, 'maxi_dress'],
        '/chân váy|chan vay/ui' => ['chân váy', 3, 'skirt'],
        '/váy đầm|vay dam|\bđầm\b|\bdam\b/ui' => ['váy đầm', 3, 'dress'],
        '/túi xách|tui xach/ui' => ['túi xách', 4, 'bag'],
        '/đồng hồ|dong ho/ui' => ['đồng hồ', 4, 'watch'],
        '/thắt lưng|that lung/ui' => ['thắt lưng', 4, 'belt'],
        '/kính mát|kinh mat/ui' => ['kính mát', 4, 'sunglasses'],
        '/áo|ao/ui' => ['áo', 1, 'top'],
        '/quần|quan/ui' => ['quần', 2, 'bottom'],
        '/váy|vay/ui' => ['váy', 3, 'dress'],
        '/phụ kiện|phu kien/ui' => ['phụ kiện', 4, 'accessory'],
    ];

    private const COLORS = [
        '/\btrắng\b|\btrang\b|\bwhite\b/ui' => 'white',
        '/\bđen\b|\bden\b|\bblack\b/ui' => 'black',
        '/\bxanh\b|\bblue\b|\bgreen\b/ui' => 'blue',
        '/\bđỏ\b|\bdo\b|\bred\b/ui' => 'red',
        '/\bhồng\b|\bhong\b|\bpink\b/ui' => 'pink',
        '/\bxám\b|\bxam\b|\bghi\b|\bgray\b|\bgrey\b/ui' => 'gray',
        '/\bnâu\b|\bnau\b|\bbe\b|\bbeige\b|\bbrown\b/ui' => 'brown',
    ];

    public function parse(string $message, array $memoryContext = []): PartialParseResult {
        $text = trim($message);
        $lower = mb_strtolower($text);
        $result = new PartialParseResult($text);
        $matchedPatterns = [];

        $this->parseSocialFillers($lower, $result, $matchedPatterns);
        $this->parseProductId($text, $result, $matchedPatterns);
        $this->parseOrderId($text, $result, $matchedPatterns);
        $this->parseProductType($lower, $result, $matchedPatterns);
        $this->parseColor($lower, $result, $matchedPatterns);
        $this->parsePrices($lower, $result, $matchedPatterns);
        $this->parseSizeAndMeasurements($lower, $text, $result, $matchedPatterns);
        $this->parseStock($lower, $result, $matchedPatterns);
        $this->applySlotMemory($lower, $memoryContext, $result);

        $intent = $this->inferIntent($lower, $result->toArray()['resolved_fields']);
        $result->addResolvedField('intent', $intent, 'rule_parser', $intent === 'unknown' ? 0.2 : 0.95, true);
        $result->addMatchedRule('intent:' . $intent);

        $this->addMissingFields($intent, $result);
        $this->parseUnresolvedSpans($lower, $result, $matchedPatterns);
        $result->setCoverage($this->coverage($lower, $matchedPatterns));

        return $result;
    }

    private function parseSocialFillers(string $lower, PartialParseResult $result, array &$matchedPatterns): void {
        $fillers = [
            '/\bgiúp mình với nhé\b|\bgiup minh voi nhe\b/ui',
            '/\bgiúp mình với\b|\bgiup minh voi\b/ui',
            '/\bcho mình hỏi\b|\bcho minh hoi\b/ui',
            '/\bnhé\b|\bnhe\b|\bạ\b/ui',
        ];
        foreach ($fillers as $pattern) {
            if (preg_match($pattern, $lower, $m)) {
                $result->addUnresolvedSpan($m[0], [], false, 'social_filler');
                $result->addMatchedRule('social_filler');
                $matchedPatterns[] = $pattern;
            }
        }
    }

    private function parseProductId(string $text, PartialParseResult $result, array &$matchedPatterns): void {
        $patterns = [
            '/(?:mã|ma|id|#|sản phẩm mã|san pham ma|product)\s*#?\s*(\d+)/ui',
            '/(?:chi tiết|thông tin|xem)\s+(?:sản phẩm\s+)?#?\s*(\d+)/ui',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $result->addResolvedField('product_id', max(1, (int)$m[1]));
                $result->addMatchedRule('product_id');
                $matchedPatterns[] = $pattern;
                return;
            }
        }
    }

    private function parseOrderId(string $text, PartialParseResult $result, array &$matchedPatterns): void {
        if (preg_match('/(?:mã đơn|ma don|đơn hàng|don hang|order)\s*#?\s*(\d+)/ui', $text, $m)) {
            $result->addResolvedField('order_id', max(1, (int)$m[1]));
            $result->addMatchedRule('order_id');
            $matchedPatterns[] = '/(?:mã đơn|ma don|đơn hàng|don hang|order)\s*#?\s*(\d+)/ui';
        }
    }

    private function parseProductType(string $lower, PartialParseResult $result, array &$matchedPatterns): void {
        foreach (self::PRODUCT_TYPES as $pattern => $metadata) {
            if (preg_match($pattern, $lower)) {
                [$type, $categoryId, $category] = $metadata;
                $result->addResolvedField('product_type', $type);
                $result->addResolvedField('category_id', $categoryId);
                $result->addResolvedField('category', $category);
                $result->addMatchedRule('product_type');
                $matchedPatterns[] = $pattern;
                return;
            }
        }
    }

    private function parseColor(string $lower, PartialParseResult $result, array &$matchedPatterns): void {
        foreach (self::COLORS as $pattern => $color) {
            if (preg_match($pattern, $lower)) {
                $result->addResolvedField('color', $color);
                $result->addMatchedRule('color');
                $matchedPatterns[] = $pattern;
                return;
            }
        }
    }

    private function parsePrices(string $lower, PartialParseResult $result, array &$matchedPatterns): void {
        $candidates = $this->priceCandidates($lower);
        $firstByField = [];
        foreach ($candidates as $candidate) {
            $field = $candidate['field'];
            $result->addFieldCandidate($field, $candidate['value'], $candidate['position'], $candidate['text']);
            if (!isset($firstByField[$field])) {
                $firstByField[$field] = $candidate;
            }
        }

        foreach ($firstByField as $field => $candidate) {
            $result->addResolvedField($field, $candidate['value']);
            $result->addMatchedRule($field);
            $matchedPatterns[] = '/' . preg_quote($candidate['text'], '/') . '/ui';
        }

        if (preg_match('/giá rẻ|gia re|\brẻ\b|\bre\b|bình dân|binh dan|tiết kiệm|tiet kiem/ui', $lower) && !isset($firstByField['max_price'])) {
            $result->addResolvedField('max_price', 300000);
            $result->addMatchedRule('budget_cheap');
            $matchedPatterns[] = '/giá rẻ|gia re|\brẻ\b|\bre\b|bình dân|binh dan|tiết kiệm|tiet kiem/ui';
        }
    }

    private function priceCandidates(string $lower): array {
        $candidates = [];

        if (preg_match_all('/(?:từ|tu)?\s*(\d+(?:[.,]\d+)?)\s*(k|nghìn|ngan|ngàn|triệu|trieu|m)?\s*(?:đến|den|tới|toi|-|->)\s*(\d+(?:[.,]\d+)?)\s*(k|nghìn|ngan|ngàn|triệu|trieu|m)?/ui', $lower, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $m) {
                $unitA = $m[2][0] ?? '';
                $unitB = $m[4][0] ?? $unitA;
                $candidates[] = ['field' => 'min_price', 'value' => $this->parsePrice($m[1][0], $unitA), 'position' => $m[0][1], 'text' => $m[0][0]];
                $candidates[] = ['field' => 'max_price', 'value' => $this->parsePrice($m[3][0], $unitB), 'position' => $m[0][1], 'text' => $m[0][0]];
            }
        }

        $maxPattern = '/(?:dưới|duoi|nhỏ hơn|nho hon|không quá|khong qua|tối đa|toi da|max|khoảng|khoang|tầm|tam)\s*(\d+(?:[.,]\d+)?)\s*(k|nghìn|ngan|ngàn|triệu|trieu|m)?/ui';
        if (preg_match_all($maxPattern, $lower, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $m) {
                $candidates[] = [
                    'field' => 'max_price',
                    'value' => $this->parsePrice($m[1][0], $m[2][0] ?? ''),
                    'position' => $m[0][1],
                    'text' => $m[0][0],
                ];
            }
        }

        $minPattern = '/(?:trên|tren|từ|tu|thấp nhất|min)\s*(\d+(?:[.,]\d+)?)\s*(k|nghìn|ngan|ngàn|triệu|trieu|m)?/ui';
        if (preg_match_all($minPattern, $lower, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $m) {
                $candidates[] = [
                    'field' => 'min_price',
                    'value' => $this->parsePrice($m[1][0], $m[2][0] ?? ''),
                    'position' => $m[0][1],
                    'text' => $m[0][0],
                ];
            }
        }

        return $candidates;
    }

    private function parseSizeAndMeasurements(string $lower, string $text, PartialParseResult $result, array &$matchedPatterns): void {
        if (preg_match('/(\d+)\s*cm/ui', $lower, $m)) {
            $result->addResolvedField('height_cm', (int)$m[1]);
            $result->addMatchedRule('height_cm');
            $matchedPatterns[] = '/(\d+)\s*cm/ui';
        } elseif (preg_match('/(\d+)\s*m\s*(\d+)/ui', $lower, $m)) {
            $result->addResolvedField('height_cm', ((int)$m[1] * 100) + (int)$m[2]);
            $result->addMatchedRule('height_cm');
            $matchedPatterns[] = '/(\d+)\s*m\s*(\d+)/ui';
        }

        if (preg_match('/(\d+)\s*kg/ui', $lower, $m)) {
            $result->addResolvedField('weight_kg', (int)$m[1]);
            $result->addMatchedRule('weight_kg');
            $matchedPatterns[] = '/(\d+)\s*kg/ui';
        }

        if (preg_match('/\b(xs|s|m|l|xl|xxl)\b/ui', $text, $m)) {
            $result->addResolvedField('size', strtoupper($m[1]));
            $result->addMatchedRule('size');
            $matchedPatterns[] = '/\b(xs|s|m|l|xl|xxl)\b/ui';
        }
    }

    private function parseStock(string $lower, PartialParseResult $result, array &$matchedPatterns): void {
        if (preg_match('/còn hàng|con hang|tồn kho|ton kho|còn size|con size/ui', $lower)) {
            $result->addResolvedField('in_stock', true);
            $result->addMatchedRule('in_stock');
            $matchedPatterns[] = '/còn hàng|con hang|tồn kho|ton kho|còn size|con size/ui';
        } elseif (preg_match('/hết hàng|het hang/ui', $lower)) {
            $result->addResolvedField('in_stock', false);
            $result->addMatchedRule('in_stock');
            $matchedPatterns[] = '/hết hàng|het hang/ui';
        }
    }

    private function applySlotMemory(string $lower, array $memoryContext, PartialParseResult $result): void {
        $data = $result->toArray();
        $slots = is_array($memoryContext['slots'] ?? null) ? $memoryContext['slots'] : [];

        if (empty($data['resolved_fields']['product_type']) && !empty($slots['product_type'])) {
            $result->addResolvedField('product_type', (string)$slots['product_type'], 'slot_memory', 0.85, true);
            if (!empty($slots['category_id'])) {
                $result->addResolvedField('category_id', (int)$slots['category_id'], 'slot_memory', 0.85, true);
            }
            $result->addMatchedRule('slot_memory:product_type');
        }

        $hasPronoun = (bool)preg_match('/\bcái này\b|\bcai nay\b|\bsản phẩm này\b|\bsan pham nay\b|\báo này\b|\bao nay\b/ui', $lower);
        if ($hasPronoun && empty($data['resolved_fields']['product_id']) && !empty($slots['last_product_id'])) {
            $result->addResolvedField('product_id', (int)$slots['last_product_id'], 'slot_memory', 0.9, true);
            $result->addMatchedRule('slot_memory:last_product_id');
        }
    }

    private function inferIntent(string $lower, array $fields): string {
        if ($this->isOutfit($lower)) return 'unsupported_outfit';
        if ($this->isCheckout($lower)) return 'unsupported_checkout';
        if ($this->isOrder($lower)) return 'order_status';
        if (isset($fields['product_id']) && ($this->isReturnExchange($lower) || $this->isShipping($lower))) return 'mixed_product_policy';
        if (isset($fields['product_id'])) return 'product_detail';
        if (isset($fields['product_type']) && ($this->isReturnExchange($lower) || $this->isShipping($lower))) return 'mixed_product_policy';
        if ($this->isReturnExchange($lower)) return 'return_exchange';
        if ($this->isShipping($lower)) return 'shipping';
        if ($this->isPolicy($lower)) return 'policy';
        if ($this->isSizeAdvice($lower)) return 'size_advice';
        if (isset($fields['product_type']) || $this->isProductIntent($lower)) return 'product_search';
        return 'unknown';
    }

    private function addMissingFields(string $intent, PartialParseResult $result): void {
        $fields = $result->toArray()['resolved_fields'];
        if ($intent === 'size_advice') {
            if (!isset($fields['height_cm'])) $result->addMissingField('height');
            if (!isset($fields['weight_kg'])) $result->addMissingField('weight');
        }
        if ($intent === 'product_search' && !isset($fields['product_type'])) {
            $result->addMissingField('product_type');
        }
    }

    private function parseUnresolvedSpans(string $lower, PartialParseResult $result, array $matchedPatterns): void {
        $residual = $lower;
        foreach ($matchedPatterns as $pattern) {
            $residual = @preg_replace($pattern, ' ', $residual) ?? $residual;
        }
        $residual = preg_replace('/\b(tìm|tim|cho tôi|cho toi|mình muốn|minh muon|có|co|xem|chi tiết|thông tin|dưới|duoi|và|va|nhưng|nhung|với|voi)\b/ui', ' ', $residual) ?? $residual;
        $residual = trim(preg_replace('/[,.!?;:]+|\s+/u', ' ', $residual) ?? '');
        if ($residual === '') {
            return;
        }

        $expected = $this->expectedSemanticFields($residual);
        if ($expected !== []) {
            $result->addUnresolvedSpan($residual, $expected, true, 'semantic_constraint');
        }
    }

    private function expectedSemanticFields(string $span): array {
        $expected = [];
        if (preg_match('/phỏng vấn|phong van|đi làm|di lam|công sở|cong so|tiệc|tiec|biển|bien|hẹn hò|hen ho/ui', $span)) {
            $expected[] = 'occasion';
        }
        if (preg_match('/trẻ|tre|già|gia|lịch sự|lich su|formal|thoải mái|thoai mai|năng động|nang dong/ui', $span)) {
            $expected[] = 'style';
            $expected[] = 'avoid';
        }
        return array_values(array_unique($expected));
    }

    private function coverage(string $lower, array $matchedPatterns): float {
        if (trim($lower) === '') {
            return 1.0;
        }
        $covered = 0;
        foreach ($matchedPatterns as $pattern) {
            if (@preg_match_all($pattern, $lower, $matches)) {
                foreach ($matches[0] as $match) {
                    $covered += mb_strlen((string)$match);
                }
            }
        }
        return min(1.0, $covered / max(1, mb_strlen($lower)));
    }

    private function parsePrice(string $raw, string $unit): int {
        $number = (float)str_replace(',', '.', $raw);
        $unit = mb_strtolower(trim($unit));
        if (in_array($unit, ['triệu', 'trieu', 'm'], true)) return (int)round($number * 1000000);
        if (in_array($unit, ['k', 'nghìn', 'ngan', 'ngàn'], true)) return (int)round($number * 1000);
        if ($number > 0 && $number < 1000) return (int)round($number * 1000);
        return (int)round($number);
    }

    private function isProductIntent(string $text): bool {
        return (bool)preg_match('/sản phẩm|san pham|áo|ao|quần|quan|váy|vay|đầm|dam|phụ kiện|phu kien/ui', $text);
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
}
