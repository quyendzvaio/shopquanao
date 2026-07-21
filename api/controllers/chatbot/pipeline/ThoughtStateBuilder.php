<?php

class ThoughtStateBuilder {
    public function build(array $intent, array $memoryContext, int $step, array $previousScore = []): array {
        $primary = (string)($intent['primary_intent'] ?? 'unknown');
        $entities = is_array($intent['entities'] ?? null) ? $intent['entities'] : [];
        $knownFacts = [];

        foreach (['product_id', 'product_type', 'height', 'weight', 'size', 'min_price', 'max_price', 'color', 'in_stock', 'order_id'] as $field) {
            if (array_key_exists($field, $entities) && $entities[$field] !== null && $entities[$field] !== '') {
                $knownFacts[] = $field . '=' . $this->stringValue($entities[$field]);
            }
        }

        $missingEvidence = $previousScore['missing_evidence'] ?? $this->initialMissingEvidence($primary, $intent);

        return [
            'step' => $step,
            'state' => $this->stateName($primary, $missingEvidence),
            'goal' => $this->goal($primary),
            'known_facts' => $knownFacts,
            'missing_evidence' => array_values(array_unique(array_map('strval', is_array($missingEvidence) ? $missingEvidence : []))),
            'memory_used' => [
                'has_session_summary' => trim((string)($memoryContext['summary'] ?? '')) !== '',
                'slot_keys' => array_keys(is_array($memoryContext['slots'] ?? null) ? $memoryContext['slots'] : []),
                'long_term_available' => !empty($memoryContext['long_term_memory']),
            ],
        ];
    }

    private function initialMissingEvidence(string $primary, array $intent): array {
        $missing = [];
        $requested = is_array($intent['requested_fields'] ?? null) ? $intent['requested_fields'] : [];
        $entities = is_array($intent['entities'] ?? null) ? $intent['entities'] : [];

        if ($primary === 'product_search') {
            $missing[] = 'product_results';
            if (in_array('stock', $requested, true)) $missing[] = 'stock';
            if (in_array('price', $requested, true)) $missing[] = 'price';
        } elseif ($primary === 'product_detail') {
            $missing = ['product_detail'];
        } elseif ($primary === 'size_advice') {
            if (empty($entities['height'])) $missing[] = 'height';
            if (empty($entities['weight'])) $missing[] = 'weight';
            $missing[] = 'recommended_size';
        } elseif (in_array($primary, ['return_exchange', 'shipping', 'policy'], true)) {
            $missing[] = 'policy_evidence';
        } elseif ($primary === 'mixed_product_policy') {
            $missing[] = !empty($entities['product_id']) ? 'product_detail' : 'product_results';
            $missing[] = 'policy_evidence';
        } elseif ($primary === 'order_status') {
            $missing[] = 'order_status';
        }

        return $missing;
    }

    private function stateName(string $primary, array $missingEvidence): string {
        if (in_array($primary, ['unsupported_outfit', 'unsupported_checkout'], true)) {
            return 'guardrail';
        }
        if ($missingEvidence === []) {
            return 'ready_to_answer';
        }
        return 'need_' . implode('_and_', array_slice(array_map(
            fn($item) => preg_replace('/[^a-z0-9_]+/i', '_', (string)$item) ?: 'evidence',
            $missingEvidence
        ), 0, 3));
    }

    private function goal(string $primary): string {
        return match ($primary) {
            'product_search' => 'find_matching_products',
            'product_detail' => 'answer_specific_product_detail',
            'size_advice' => 'recommend_size_from_measurements',
            'return_exchange', 'shipping', 'policy' => 'answer_policy_from_knowledge',
            'mixed_product_policy' => 'combine_product_and_policy_evidence',
            'order_status' => 'answer_order_status_safely',
            'unsupported_outfit', 'unsupported_checkout' => 'explain_unsupported_scope',
            default => 'clarify_user_request',
        };
    }

    private function stringValue($value): string {
        if (is_array($value)) {
            return implode(',', array_map('strval', $value));
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string)$value;
    }
}
