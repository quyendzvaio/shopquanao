<?php

/**
 * Converts a whole user utterance into the constrained chatbot input contract.
 *
 * This class intentionally has no access to tool definitions, PDO, or execution
 * code: the model may classify and extract fields, but PHP still validates and
 * plans every tool call.
 */
final class LlmInputParser
{
    private const INTENTS = [
        'product_search',
        'product_detail',
        'size_advice',
        'return_exchange',
        'shipping',
        'policy',
        'mixed_product_policy',
        'order_status',
        'unsupported_outfit',
        'unsupported_checkout',
        'suggest_complementary_products',
        'unknown',
    ];

    private const CATEGORY_BY_ID = [
        1 => 'top',
        2 => 'bottom',
        3 => 'dress',
        4 => 'accessory',
        5 => 'footwear',
    ];

    public function __construct(private ?LLMProvider $llm)
    {
    }

    /**
     * @return array{partial:?array,used:bool,error:?string}
     */
    public function parse(string $message, array $memoryContext = []): array
    {
        if ($this->llm === null) {
            return ['partial' => null, 'used' => false, 'error' => 'llm_unavailable'];
        }

        try {
            $response = $this->llm->chat(
                $this->messages($message, $memoryContext),
                [$this->tool()],
                'required',
                ['temperature' => 0, 'max_tokens' => 400, 'reasoning' => false, 'stream' => false]
            );
            $call = $response->getFirstToolCall();
            if ($call === null || $call->name !== 'parse_chatbot_input') {
                return ['partial' => null, 'used' => true, 'error' => 'input_parser_tool_call_missing'];
            }

            $arguments = $call->arguments;
            if ($arguments === []) {
                $arguments = $this->argumentsFromGatewayMarkup($response->content, $call->name);
            }
            $arguments = $this->sanitize($arguments);
            if ($arguments === null) {
                return ['partial' => null, 'used' => true, 'error' => 'input_parser_schema_invalid'];
            }

            return ['partial' => $this->toPartial($message, $arguments), 'used' => true, 'error' => null];
        } catch (Throwable $error) {
            return ['partial' => null, 'used' => true, 'error' => 'input_parser_failed'];
        }
    }

    private function messages(string $message, array $memoryContext): array
    {
        $slots = is_array($memoryContext['slots'] ?? null) ? $memoryContext['slots'] : [];
        $memory = array_intersect_key($slots, array_flip(['last_product_id', 'product_type', 'category_id']));

        return [
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'Bạn là input parser cho chatbot bán quần áo Việt Nam.',
                    'Chỉ trả về function call parse_chatbot_input theo schema; không trả lời người dùng, không chọn tool, không tạo SQL.',
                    'Phân loại đúng một intent trong schema và chỉ trích xuất dữ kiện hiện diện trong câu hỏi hoặc memory_hints.',
                    'product_query là cụm tên sản phẩm đầy đủ để tìm catalog: giữ độ đặc hiệu, không rút gọn về "áo" hoặc "quần" khi câu có loại cụ thể.',
                    'Chuẩn hóa lỗi khoảng trắng/viết liền cho mọi loại sản phẩm (ví dụ: "áo sơmi" thành "áo sơ mi", "quanjean" thành "quần jeans", "aothun" thành "áo thun", "aokhoac" thành "áo khoác", "quantay" thành "quần tây", "chanvay" thành "chân váy", "tuixach" thành "túi xách", "dongho" thành "đồng hồ", "giayluoi" thành "giày lười"); không thêm đặc tính không có trong câu.',
                    'Giá dùng VND nguyên: 500k = 500000. Dưới/tối đa là max_price, từ/trên là min_price.',
                    'product_id và order_id chỉ lấy từ mã được user nói rõ, trừ tham chiếu như "món này" có thể dùng đúng memory_hints.last_product_id.',
                    'Các intent cần hỗ trợ: tìm/xem sản phẩm, tư vấn size, chính sách đổi trả/vận chuyển/thanh toán/bảo hành, đơn hàng, guardrail checkout/phối đồ, và phối đồ cho product_id cụ thể.',
                    'Câu phối đồ không có product_id là unsupported_outfit. Câu yêu cầu thêm giỏ/checkout/thanh toán hộ là unsupported_checkout.',
                    'Dùng null cho dữ kiện không xác định.',
                ]),
            ],
            [
                'role' => 'user',
                'content' => json_encode([
                    'message' => $message,
                    'memory_hints' => $memory,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ],
        ];
    }

    private function tool(): array
    {
        $nullableString = ['type' => ['string', 'null']];
        $nullableInteger = ['type' => ['integer', 'null']];
        $nullableNumber = ['type' => ['number', 'null']];

        return [
            'type' => 'function',
            'function' => [
                'name' => 'parse_chatbot_input',
                'description' => 'Return the validated intent and user-provided filters only.',
                'strict' => true,
                'parameters' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => [
                        'intent', 'product_query', 'category_id', 'product_id', 'order_id',
                        'min_price', 'max_price', 'color', 'size', 'in_stock', 'height_cm',
                        'weight_kg', 'occasion', 'style', 'avoid',
                    ],
                    'properties' => [
                        'intent' => ['type' => 'string', 'enum' => self::INTENTS],
                        'product_query' => $nullableString,
                        'category_id' => $nullableInteger,
                        'product_id' => $nullableInteger,
                        'order_id' => $nullableInteger,
                        'min_price' => $nullableNumber,
                        'max_price' => $nullableNumber,
                        'color' => $nullableString,
                        'size' => ['type' => ['string', 'null'], 'enum' => ['XS', 'S', 'M', 'L', 'XL', 'XXL', null]],
                        'in_stock' => ['type' => ['boolean', 'null']],
                        'height_cm' => $nullableInteger,
                        'weight_kg' => $nullableInteger,
                        'occasion' => $nullableString,
                        'style' => ['type' => ['array', 'null'], 'items' => ['type' => 'string']],
                        'avoid' => ['type' => ['array', 'null'], 'items' => ['type' => 'string']],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    private function sanitize(array $arguments): ?array
    {
        $required = [
            'intent', 'product_query', 'category_id', 'product_id', 'order_id', 'min_price',
            'max_price', 'color', 'size', 'in_stock', 'height_cm', 'weight_kg', 'occasion', 'style', 'avoid',
        ];
        if (array_diff($required, array_keys($arguments)) !== []) {
            return null;
        }

        $intent = (string) $arguments['intent'];
        if (!in_array($intent, self::INTENTS, true)) {
            return null;
        }

        $result = ['intent' => $intent];
        $productQuery = $this->nullableText($arguments['product_query'], 160);
        if ($productQuery !== null) {
            $productQuery = preg_replace('/(?<![\p{L}])áo\s*sơ\s*mi|ao\s*so\s*mi|áo\s*somi|ao\s*somi|aoso\s*mi|aosomi(?![\p{L}])/ui', 'áo sơ mi', $productQuery) ?? $productQuery;
            $productQuery = preg_replace('/(?<![\p{L}])áo\s*khoác\s*bomber|ao\s*khoac\s*bomber|aobomber(?![\p{L}])/ui', 'áo khoác bomber', $productQuery) ?? $productQuery;
            $productQuery = preg_replace('/(?<![\p{L}])áo\s*khoác|ao\s*khoac|aokhoac(?![\p{L}])/ui', 'áo khoác', $productQuery) ?? $productQuery;
            $productQuery = preg_replace('/(?<![\p{L}])áo\s*thun|ao\s*thun|aothun(?![\p{L}])/ui', 'áo thun', $productQuery) ?? $productQuery;
            $productQuery = preg_replace('/(?<![\p{L}])áo\s*phông|ao\s*phong|aophong(?![\p{L}])/ui', 'áo phông', $productQuery) ?? $productQuery;
            $productQuery = preg_replace('/(?<![\p{L}])áo\s*hoodie|ao\s*hoodie|aohoodie(?![\p{L}])/ui', 'áo hoodie', $productQuery) ?? $productQuery;
            $productQuery = preg_replace('/(?<![\p{L}])áo\s*polo|ao\s*polo|aopolo(?![\p{L}])/ui', 'áo polo', $productQuery) ?? $productQuery;
            $productQuery = preg_replace('/(?<![\p{L}])áo\s*len|ao\s*len|aolen(?![\p{L}])/ui', 'áo len', $productQuery) ?? $productQuery;
            $productQuery = preg_replace('/(?<![\p{L}])áo\s*gile|ao\s*gile|aogile(?![\p{L}])/ui', 'áo gile', $productQuery) ?? $productQuery;
            $productQuery = preg_replace('/(?<![\p{L}])áo\s*vest|ao\s*vest|aovest|aoblazer(?![\p{L}])/ui', 'áo vest', $productQuery) ?? $productQuery;
            $productQuery = preg_replace('/(?<![\p{L}])quần\s*jeans?|quan\s*jeans?|quanjeans?(?![\p{L}])/ui', 'quần jeans', $productQuery) ?? $productQuery;
            $productQuery = preg_replace('/(?<![\p{L}])quần\s*tây|quan\s*tay|quantay(?![\p{L}])/ui', 'quần tây', $productQuery) ?? $productQuery;
            $productQuery = preg_replace('/(?<![\p{L}])quần\s*kaki|quan\s*kaki|quankaki(?![\p{L}])/ui', 'quần kaki', $productQuery) ?? $productQuery;
            $productQuery = preg_replace('/(?<![\p{L}])quần\s*shorts?|quan\s*shorts?|quanshorts?(?![\p{L}])/ui', 'quần short', $productQuery) ?? $productQuery;
            $productQuery = preg_replace('/(?<![\p{L}])quần\s*jogger|quan\s*jogger|quanjogger(?![\p{L}])/ui', 'quần jogger', $productQuery) ?? $productQuery;
            $productQuery = preg_replace('/(?<![\p{L}])váy\s*maxi|vay\s*maxi|vaymaxi(?![\p{L}])/ui', 'váy maxi', $productQuery) ?? $productQuery;
            $productQuery = preg_replace('/(?<![\p{L}])chân\s*váy|chan\s*vay|chanvay(?![\p{L}])/ui', 'chân váy', $productQuery) ?? $productQuery;
            $productQuery = preg_replace('/(?<![\p{L}])váy\s*đầm|vay\s*dam|vaydam(?![\p{L}])/ui', 'váy đầm', $productQuery) ?? $productQuery;
            $productQuery = preg_replace('/(?<![\p{L}])túi\s*xách|tui\s*xach|tuixach(?![\p{L}])/ui', 'túi xách', $productQuery) ?? $productQuery;
            $productQuery = preg_replace('/(?<![\p{L}])đồng\s*hồ|dong\s*ho|dongho(?![\p{L}])/ui', 'đồng hồ', $productQuery) ?? $productQuery;
            $productQuery = preg_replace('/(?<![\p{L}])thắt\s*lưng|that\s*lung|thatlung(?![\p{L}])/ui', 'thắt lưng', $productQuery) ?? $productQuery;
            $productQuery = preg_replace('/(?<![\p{L}])kính\s*mát|kinh\s*mat|kinhmat(?![\p{L}])/ui', 'kính mát', $productQuery) ?? $productQuery;
            $productQuery = preg_replace('/(?<![\p{L}])giày\s*tây|giay\s*tay|giaytay(?![\p{L}])/ui', 'giày tây', $productQuery) ?? $productQuery;
            $productQuery = preg_replace('/(?<![\p{L}])giày\s*thể\s*thao|giay\s*the\s*thao|giaythethao(?![\p{L}])/ui', 'giày thể thao', $productQuery) ?? $productQuery;
            $productQuery = preg_replace('/(?<![\p{L}])giày\s*lười|giay\s*luoi|giayluoi(?![\p{L}])/ui', 'giày lười', $productQuery) ?? $productQuery;
            $productQuery = preg_replace('/(?<![\p{L}])dép\s*sandal|dep\s*sandal|depsandal(?![\p{L}])/ui', 'dép sandal', $productQuery) ?? $productQuery;
            $productQuery = preg_replace('/(?<![\p{L}])phụ\s*kiện|phu\s*kien|phukien(?![\p{L}])/ui', 'phụ kiện', $productQuery) ?? $productQuery;
        }
        $result['product_query'] = $productQuery;
        foreach (['category_id', 'product_id', 'order_id', 'height_cm', 'weight_kg'] as $field) {
            $result[$field] = $this->nullablePositiveInteger($arguments[$field]);
        }
        if ($result['category_id'] !== null && !isset(self::CATEGORY_BY_ID[$result['category_id']])) {
            $result['category_id'] = null;
        }
        foreach (['min_price', 'max_price'] as $field) {
            $result[$field] = $this->nullableMoney($arguments[$field]);
        }
        if ($result['min_price'] !== null && $result['max_price'] !== null && $result['min_price'] > $result['max_price']) {
            return null;
        }

        $result['color'] = ProductAttributeNormalizer::normalizeColor($this->nullableText($arguments['color'], 50) ?? '');
        $size = $this->nullableText($arguments['size'], 8);
        $result['size'] = $size === null ? null : ProductAttributeNormalizer::normalizeSize($size);
        if ($size !== null && $result['size'] === null) {
            return null;
        }
        $result['in_stock'] = is_bool($arguments['in_stock']) ? $arguments['in_stock'] : null;
        $result['occasion'] = $this->nullableText($arguments['occasion'], 80);
        foreach (['style', 'avoid'] as $field) {
            $result[$field] = $this->nullableTerms($arguments[$field]);
        }

        if (in_array($intent, ['product_search', 'mixed_product_policy'], true) && $result['product_query'] === null) {
            return null;
        }
        if (in_array($intent, ['product_detail', 'suggest_complementary_products'], true) && $result['product_id'] === null) {
            return null;
        }
        return $result;
    }

    private function toPartial(string $message, array $arguments): array
    {
        $result = new PartialParseResult($message);
        foreach ($arguments as $field => $value) {
            if ($value === null || $field === 'product_query') {
                continue;
            }
            $result->addResolvedField($field, $value, 'llm_input_parser', 0.9, true);
        }
        if ($arguments['product_query'] !== null) {
            $result->addResolvedField('product_type', $arguments['product_query'], 'llm_input_parser', 0.9, true);
        }
        if ($arguments['category_id'] !== null) {
            $result->addResolvedField('category', self::CATEGORY_BY_ID[$arguments['category_id']], 'llm_input_parser', 0.9, true);
        }
        $result->addMatchedRule('llm_input_parser');
        $result->setCoverage(1.0);
        return $result->toArray();
    }

    private function nullableText(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        return $value === '' || mb_strlen($value) > $maxLength ? null : $value;
    }

    private function nullablePositiveInteger(mixed $value): ?int
    {
        if ($value === null || !is_int($value) || $value <= 0) {
            return null;
        }
        return $value;
    }

    private function nullableMoney(mixed $value): ?int
    {
        if ($value === null || !is_int($value) && !is_float($value)) {
            return null;
        }
        $value = (int) round($value);
        return $value >= 0 && $value <= 100000000 ? $value : null;
    }

    private function nullableTerms(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            return null;
        }
        $terms = [];
        foreach ($value as $term) {
            $term = $this->nullableText($term, 80);
            if ($term !== null) {
                $terms[] = $term;
            }
        }
        return $terms === [] ? null : array_values(array_unique($terms));
    }

    /**
     * Some OpenAI-compatible gateways expose a tool name in tool_calls but
     * serialize its parameters in model content as <parameter=name>value.
     * Decode that documented wire variant before applying the normal schema
     * validator; no prose is ever interpreted as an intent.
     */
    private function argumentsFromGatewayMarkup(string $content, string $toolName): array
    {
        if (!preg_match('/<function=' . preg_quote($toolName, '/') . '>(.*?)<\\/function>/su', $content, $function)) {
            return [];
        }
        if (!preg_match_all('/<parameter=([a-z_][a-z0-9_]*)>(.*?)<\\/parameter>/su', $function[1], $matches, PREG_SET_ORDER)) {
            return [];
        }

        $arguments = [];
        foreach ($matches as $match) {
            $arguments[(string) $match[1]] = $this->markupValue((string) $match[2]);
        }
        return $arguments;
    }

    private function markupValue(string $value): mixed
    {
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        if (in_array(strtolower($value), ['none', 'null'], true)) {
            return null;
        }
        if (strtolower($value) === 'true') {
            return true;
        }
        if (strtolower($value) === 'false') {
            return false;
        }
        if (preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }
        if (preg_match('/^-?\d+\.\d+$/', $value)) {
            return (float) $value;
        }
        if ((str_starts_with($value, '[') && str_ends_with($value, ']')) || (str_starts_with($value, '{') && str_ends_with($value, '}'))) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
        return $value;
    }
}
