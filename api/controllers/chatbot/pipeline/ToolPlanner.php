<?php

class ToolPlanner {
    private array $capabilities;

    public function __construct(array $capabilities = []) {
        $this->capabilities = $capabilities;
    }

    public function plan(array $intent): array {
        $primary = (string)($intent['primary_intent'] ?? 'unknown');
        $entities = is_array($intent['entities'] ?? null) ? $intent['entities'] : [];
        $calls = [];

        switch ($primary) {
            case 'unsupported_outfit':
            case 'unsupported_checkout':
            case 'unknown':
                return ['batches' => [], 'response_type' => $primary === 'unknown' ? 'fallback' : 'final_answer'];

            case 'size_advice':
                if (!empty($intent['missing_slots'])) {
                    return ['batches' => [], 'response_type' => 'clarification'];
                }
                $args = [
                    'height' => (int)$entities['height'],
                    'weight' => (int)$entities['weight'],
                ];
                if (!empty($entities['category_id'])) $args['category_id'] = (int)$entities['category_id'];
                $calls[] = ['tool' => 'suggest_size', 'args' => $args, 'id' => 'size'];
                break;

            case 'product_detail':
                if (empty($entities['product_id'])) return ['batches' => [], 'response_type' => 'fallback'];
                $calls[] = ['tool' => 'get_product_detail', 'args' => ['product_id' => (int)$entities['product_id']], 'id' => 'product_detail'];
                break;

            case 'product_search':
                if (empty($entities['product_type'])) return ['batches' => [], 'response_type' => 'fallback'];
                $args = ['search' => (string)$entities['product_type']];
                foreach (['min_price', 'max_price', 'category_id', 'color', 'size', 'in_stock', 'occasion', 'style', 'avoid', 'semantic_query'] as $key) {
                    if (isset($entities[$key])) $args[$key] = $entities[$key];
                }
                $calls[] = ['tool' => 'search_products', 'args' => $args, 'id' => 'product_search'];
                break;

            case 'return_exchange':
            case 'shipping':
            case 'policy':
                $calls[] = ['tool' => 'retrieve_knowledge', 'args' => $this->knowledgeArgs($intent), 'id' => 'knowledge'];
                break;

            case 'mixed_product_policy':
                if (!empty($entities['product_id'])) {
                    $calls[] = ['tool' => 'get_product_detail', 'args' => ['product_id' => (int)$entities['product_id']], 'id' => 'product_detail'];
                } elseif (!empty($entities['product_type'])) {
                    $args = ['search' => (string)$entities['product_type']];
                    foreach (['min_price', 'max_price', 'category_id', 'color', 'size', 'in_stock', 'occasion', 'style', 'avoid', 'semantic_query'] as $key) {
                        if (isset($entities[$key])) $args[$key] = $entities[$key];
                    }
                    $calls[] = ['tool' => 'search_products', 'args' => $args, 'id' => 'product_search'];
                }
                $calls[] = ['tool' => 'retrieve_knowledge', 'args' => $this->knowledgeArgs($intent), 'id' => 'knowledge'];
                break;

            case 'order_status':
                $args = [];
                if (!empty($entities['order_id'])) $args['order_id'] = (int)$entities['order_id'];
                $calls[] = ['tool' => 'get_order_status', 'args' => $args, 'id' => 'order'];
                break;
        }

        return [
            'batches' => empty($calls) ? [] : [$calls],
            'response_type' => 'final_answer',
            'selected_capabilities' => array_values(array_unique(array_map(fn($call) => (string)$call['tool'], $calls))),
            'capability_definitions_version' => $this->capabilities === [] ? 'legacy' : 'capability_registry_v1',
        ];
    }

    private function knowledgeArgs(array $intent): array {
        $query = (string)($intent['sub_queries']['knowledge'] ?? '');
        if ($query === '') $query = (string)($intent['original_query'] ?? '');
        $args = ['query' => $query, 'limit' => 5];
        $category = $this->knowledgeCategory($intent);
        if ($category !== null) $args['category'] = $category;
        return $args;
    }

    private function knowledgeCategory(array $intent): ?string {
        $primary = (string)($intent['primary_intent'] ?? '');
        $secondary = is_array($intent['secondary_intents'] ?? null) ? $intent['secondary_intents'] : [];
        if ($primary === 'return_exchange' || in_array('return_exchange', $secondary, true)) return 'return';
        if ($primary === 'shipping' || in_array('shipping', $secondary, true)) return 'shipping';
        if ($primary === 'order_status') return 'order';
        return null;
    }
}
