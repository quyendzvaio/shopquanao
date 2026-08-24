<?php

class PlanValidator {
    private array $capabilities;

    public function __construct(array $capabilities) {
        $this->capabilities = $capabilities;
    }

    public function validate(array $plan, array $intent): array {
        $errors = [];
        $sanitized = $plan;
        $lockedFields = $this->lockedFields($intent);
        $primary = (string)($intent['primary_intent'] ?? 'unknown');
        $entities = is_array($intent['entities'] ?? null) ? $intent['entities'] : [];
        $requested = is_array($intent['requested_fields'] ?? null) ? $intent['requested_fields'] : [];
        $query = (string)($intent['original_query'] ?? '');

        if (!isset($sanitized['batches']) || !is_array($sanitized['batches'])) {
            $sanitized['batches'] = [];
        }

        foreach ($sanitized['batches'] as $batchIndex => &$batch) {
            if (!is_array($batch)) {
                $batch = [];
                continue;
            }
            foreach ($batch as $callIndex => &$call) {
                if (!is_array($call)) {
                    $errors[] = "invalid_call:$batchIndex.$callIndex";
                    $call = [];
                    continue;
                }
                $tool = (string)($call['tool'] ?? '');
                if ($tool === '' || !isset($this->capabilities[$tool])) {
                    $errors[] = "unknown_tool:$tool";
                    continue;
                }

                $capability = $this->capabilities[$tool];
                $args = is_array($call['args'] ?? null) ? $call['args'] : [];
                $allowed = array_keys($capability['input_schema']['properties'] ?? []);
                foreach (array_keys($args) as $arg) {
                    if (!in_array($arg, $allowed, true)) {
                        $errors[] = "unknown_argument:$tool.$arg";
                        unset($args[$arg]);
                    }
                }

                foreach (($capability['required_arguments'] ?? []) as $required) {
                    if (!array_key_exists($required, $args) || $args[$required] === '' || $args[$required] === null) {
                        $errors[] = "missing_required:$tool.$required";
                    }
                }

                foreach ($this->toolFieldMap($tool) as $arg => $field) {
                    if (isset($args[$arg], $lockedFields[$field]) && (string)$args[$arg] !== (string)$lockedFields[$field]) {
                        $errors[] = "locked_field_changed:$field";
                    }
                }

                $args = $this->sanitizeDeterministicArgs($tool, $args, $intent);

                $call['args'] = $args;
                $call['capability'] = $tool;
                $call['validation'] = ['checked' => true];
            }
        }
        unset($batch, $call);

        $tools = $this->selectedTools($sanitized);
        $errors = array_merge($errors, $this->validateUseCase($primary, $entities, $requested, $query, $tools, $sanitized));

        return [
            'passed' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'sanitized_plan' => $sanitized,
        ];
    }

    private function lockedFields(array $intent): array {
        $locked = [];
        foreach (($intent['merged_fields'] ?? []) as $field => $metadata) {
            if (is_array($metadata) && ($metadata['locked'] ?? false)) {
                $locked[(string)$field] = $metadata['value'] ?? null;
            }
        }
        return $locked;
    }

    private function toolFieldMap(string $tool): array {
        return match ($tool) {
            'get_product_detail' => ['product_id' => 'product_id'],
            'search_products' => [
                'min_price' => 'min_price',
                'max_price' => 'max_price',
                'category_id' => 'category_id',
                'color' => 'color',
                'size' => 'size',
                'in_stock' => 'in_stock',
            ],
            'suggest_size' => ['height' => 'height_cm', 'weight' => 'weight_kg', 'category_id' => 'category_id'],
            'get_order_status' => ['order_id' => 'order_id'],
            default => [],
        };
    }

    private function sanitizeDeterministicArgs(string $tool, array $args, array $intent): array {
        if ($tool === 'retrieve_knowledge') {
            $args['limit'] = 5;
            $category = $this->knowledgeCategory((string)($intent['original_query'] ?? ''), (string)($intent['primary_intent'] ?? ''));
            if ($category !== null) {
                $args['category'] = $category;
            }
        }

        if ($tool === 'search_products') {
            $entities = is_array($intent['entities'] ?? null) ? $intent['entities'] : [];
            if (!empty($entities['in_stock'])) {
                $args['in_stock'] = true;
            }
        }

        return $args;
    }

    private function validateUseCase(string $primary, array $entities, array $requested, string $query, array $tools, array $plan): array {
        $errors = [];
        $has = fn(string $tool): bool => in_array($tool, $tools, true);

        if ($primary === 'product_search') {
            if (!$has('search_products')) $errors[] = 'product_search_requires_search_products';
            if ($has('get_product_detail') && empty($entities['product_id'])) $errors[] = 'product_search_must_not_call_detail_without_product_id';
            if (empty($entities['product_type'])) $errors[] = 'product_search_missing_product_type';
            $call = $this->firstToolCall($plan, 'search_products');
            $args = is_array($call['args'] ?? null) ? $call['args'] : [];
            foreach (['min_price', 'max_price', 'size', 'color', 'in_stock'] as $field) {
                if (array_key_exists($field, $entities) && (!array_key_exists($field, $args) || (string)$args[$field] !== (string)$entities[$field])) {
                    $errors[] = "product_search_missing_arg:$field";
                }
            }
            if (in_array('stock', $requested, true) && (($args['in_stock'] ?? null) !== true)) {
                $errors[] = 'stock_request_requires_in_stock_true';
            }
        }

        if ($primary === 'suggest_complementary_products') {
            if (empty($entities['product_id']) || (int) $entities['product_id'] <= 0) {
                $errors[] = 'complementary_requires_anchor_product';
            }
            if (!$has('suggest_complementary_products')) {
                $errors[] = 'complementary_requires_provider_tool';
            }
            if ($has('search_products')) {
                $errors[] = 'complementary_must_use_shared_provider_tool';
            }
        }

        if ($primary === 'product_detail') {
            if (empty($entities['product_id']) || (int)$entities['product_id'] <= 0) $errors[] = 'product_detail_invalid_product_id';
            if (!$has('get_product_detail')) $errors[] = 'product_detail_requires_get_product_detail';
            if ($has('search_products')) $errors[] = 'product_detail_must_not_search';
        }

        if ($primary === 'size_advice') {
            if (empty($entities['height']) || empty($entities['weight'])) {
                if ($has('suggest_size')) $errors[] = 'size_missing_measurements_must_not_call_tool';
            } else {
                if (!$has('suggest_size')) $errors[] = 'size_advice_requires_suggest_size';
                $height = (int)$entities['height'];
                $weight = (int)$entities['weight'];
                if ($height < 80 || $height > 230) $errors[] = 'size_height_out_of_range';
                if ($weight < 20 || $weight > 220) $errors[] = 'size_weight_out_of_range';
            }
        }

        if (in_array($primary, ['return_exchange', 'shipping', 'policy'], true)) {
            if (!$has('retrieve_knowledge')) $errors[] = 'policy_requires_retrieve_knowledge';
            $call = $this->firstToolCall($plan, 'retrieve_knowledge');
            $args = is_array($call['args'] ?? null) ? $call['args'] : [];
            if (($args['limit'] ?? null) !== 5) $errors[] = 'policy_limit_must_be_5';
            $expectedCategory = $this->knowledgeCategory($query, $primary);
            if ($expectedCategory !== null && (($args['category'] ?? null) !== $expectedCategory)) {
                $errors[] = 'policy_category_mismatch';
            }
        }

        if ($primary === 'mixed_product_policy') {
            if (!empty($entities['product_id'])) {
                if (!$has('get_product_detail')) $errors[] = 'mixed_product_id_requires_detail';
                if ($has('search_products')) $errors[] = 'mixed_product_id_must_not_search';
            } elseif (!empty($entities['product_type'])) {
                if (!$has('search_products')) $errors[] = 'mixed_product_type_requires_search';
            } else {
                $errors[] = 'mixed_missing_product_context';
            }
            if (!$has('retrieve_knowledge')) $errors[] = 'mixed_requires_retrieve_knowledge';
        }

        if ($primary === 'order_status') {
            if (!$has('get_order_status')) $errors[] = 'order_status_requires_get_order_status';
        }

        if (in_array($primary, ['unsupported_outfit', 'unsupported_checkout', 'unknown'], true) && $tools !== []) {
            $errors[] = $primary . '_must_not_call_tools';
        }

        return $errors;
    }

    private function selectedTools(array $plan): array {
        $tools = [];
        foreach (($plan['batches'] ?? []) as $batch) {
            foreach ($batch as $call) {
                if (!empty($call['tool'])) $tools[] = (string)$call['tool'];
            }
        }
        return array_values(array_unique($tools));
    }

    private function firstToolCall(array $plan, string $tool): ?array {
        foreach (($plan['batches'] ?? []) as $batch) {
            foreach ($batch as $call) {
                if (($call['tool'] ?? '') === $tool) return $call;
            }
        }
        return null;
    }

    private function knowledgeCategory(string $query, string $primary): ?string {
        $lower = mb_strtolower($query);
        if (preg_match('/đổi|doi|trả|tra|hoàn tiền|hoan tien|sale/u', $lower) || $primary === 'return_exchange') {
            return 'return';
        }
        if (preg_match('/phí ship|phi ship|ship|giao hàng|giao hang|vận chuyển|van chuyen/u', $lower) || $primary === 'shipping') {
            return 'shipping';
        }
        if (preg_match('/bảo hành|bao hanh|lỗi|loi/u', $lower)) {
            return 'warranty';
        }
        if (preg_match('/thanh toán|thanh toan|chuyển khoản|chuyen khoan|trả tiền|tra tien/u', $lower)) {
            return 'payment';
        }
        return null;
    }
}
