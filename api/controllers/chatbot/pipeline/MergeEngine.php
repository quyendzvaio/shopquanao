<?php

class MergeEngine {
    public function merge(array $partial, array $semanticCompletion, array $memoryContext = [], array $conflictResolution = []): array {
        $fields = is_array($partial['resolved_fields'] ?? null) ? $partial['resolved_fields'] : [];
        $overwriteAttempts = [];

        foreach (($conflictResolution['resolved_fields'] ?? []) as $field => $metadata) {
            if (is_array($metadata)) {
                $fields[$field] = [
                    'value' => $metadata['value'] ?? null,
                    'source' => $metadata['source'] ?? 'conflict_resolver',
                    'confidence' => isset($metadata['confidence']) ? (float)$metadata['confidence'] : 0.8,
                    'locked' => (bool)($metadata['locked'] ?? true),
                ];
            }
        }

        foreach (($semanticCompletion['inferred_fields'] ?? []) as $field => $metadata) {
            if (!is_array($metadata)) {
                $metadata = ['value' => $metadata, 'confidence' => 0.7];
            }
            if (isset($fields[$field]) && ($fields[$field]['locked'] ?? false)) {
                $overwriteAttempts[] = [
                    'field' => (string)$field,
                    'locked_value' => $fields[$field]['value'] ?? null,
                    'llm_value' => $metadata['value'] ?? null,
                ];
                continue;
            }
            if (!isset($fields[$field])) {
                $fields[$field] = [
                    'value' => $metadata['value'] ?? null,
                    'source' => 'llm_extractor',
                    'confidence' => isset($metadata['confidence']) ? (float)$metadata['confidence'] : 0.7,
                    'locked' => false,
                ];
            }
        }

        $entities = $this->entities($fields);
        $primary = (string)($fields['intent']['value'] ?? 'unknown');
        $requested = $this->requestedFields($primary, $entities, (string)($partial['original_query'] ?? ''));
        $secondary = $this->secondaryIntents($primary, $partial);
        $missing = $this->missingFields($primary, $entities, $partial);

        return [
            'original_query' => (string)($partial['original_query'] ?? ''),
            'primary_intent' => $primary,
            'secondary_intents' => $secondary,
            'entities' => $entities,
            'requested_fields' => $requested,
            'missing_slots' => $missing,
            'sub_queries' => $this->subQueries($primary, $entities, (string)($partial['original_query'] ?? '')),
            'confidence' => $primary === 'unknown' ? 0.2 : 0.92,
            'partial_parse' => $partial,
            'semantic_completion' => $semanticCompletion,
            'merged_fields' => $fields,
            'locked_field_overwrite_attempts' => $overwriteAttempts,
            'execution_mode' => ($semanticCompletion['used'] ?? false) ? 'partial_llm_completion' : 'deterministic',
        ];
    }

    private function entities(array $fields): array {
        $entities = [];
        foreach ($fields as $field => $metadata) {
            if ($field === 'intent' || !is_array($metadata)) {
                continue;
            }
            $entities[$field] = $metadata['value'] ?? null;
        }

        if (isset($entities['height_cm'])) {
            $entities['height'] = (int)$entities['height_cm'];
        }
        if (isset($entities['weight_kg'])) {
            $entities['weight'] = (int)$entities['weight_kg'];
        }
        if (!empty($entities['style']) || !empty($entities['occasion']) || !empty($entities['avoid'])) {
            $parts = [];
            foreach (['occasion', 'style', 'avoid'] as $field) {
                if (!isset($entities[$field])) continue;
                $value = $entities[$field];
                $parts[] = is_array($value) ? implode(' ', array_map('strval', $value)) : (string)$value;
            }
            $entities['semantic_query'] = trim(implode(' ', $parts));
        }

        return $entities;
    }

    private function requestedFields(string $primary, array $entities, string $query = ''): array {
        $lower = mb_strtolower($query);
        $requested = [];
        if (isset($entities['min_price']) || isset($entities['max_price'])) $requested[] = 'price';
        if (array_key_exists('in_stock', $entities)) $requested[] = 'stock';
        if (isset($entities['size'])) $requested[] = 'size';
        if ($primary === 'return_exchange') $requested[] = 'exchange_eligibility';
        if ($primary === 'shipping') $requested[] = 'shipping_fee';
        if ($primary === 'return_exchange' && preg_match('/phí ship|phi ship|ship|giao hàng|giao hang|vận chuyển|van chuyen/ui', $lower)) {
            $requested[] = 'exchange_shipping_fee';
        }
        if ($primary === 'mixed_product_policy') {
            $requested[] = 'stock';
            $requested[] = 'exchange_eligibility';
            if (preg_match('/phí ship|phi ship|ship|giao hàng|giao hang|vận chuyển|van chuyen/ui', $lower)) {
                $requested[] = 'exchange_shipping_fee';
            }
        }
        if ($primary === 'order_status') $requested[] = 'order_status';
        return array_values(array_unique($requested));
    }

    private function secondaryIntents(string $primary, array $partial): array {
        $query = mb_strtolower((string)($partial['original_query'] ?? ''));
        $secondary = [];
        if ($primary !== 'return_exchange' && preg_match('/đổi trả|\bđổi\b|doi|trả hàng|tra hang|hoàn tiền|hoan tien|sale/ui', $query)) {
            $secondary[] = 'return_exchange';
        }
        if ($primary !== 'shipping' && preg_match('/phí ship|phi ship|ship|giao hàng|giao hang|vận chuyển|van chuyen/ui', $query)) {
            $secondary[] = 'shipping';
        }
        return array_values(array_unique($secondary));
    }

    private function missingFields(string $primary, array $entities, array $partial): array {
        $missing = is_array($partial['missing_fields'] ?? null) ? $partial['missing_fields'] : [];
        if ($primary === 'size_advice') {
            if (empty($entities['height'])) $missing[] = 'height';
            if (empty($entities['weight'])) $missing[] = 'weight';
        }
        return array_values(array_unique($missing));
    }

    private function subQueries(string $primary, array $entities, string $query): array {
        $sub = [];
        if (in_array($primary, ['return_exchange', 'shipping', 'policy', 'mixed_product_policy'], true)) {
            $sub['knowledge'] = $query;
        }
        if (!empty($entities['product_id'])) {
            $sub['product_detail'] = (string)$entities['product_id'];
        } elseif (!empty($entities['product_type'])) {
            $sub['product_search'] = (string)$entities['product_type'];
        }
        return $sub;
    }
}
