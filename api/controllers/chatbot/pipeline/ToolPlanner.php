<?php

require_once __DIR__ . '/ChatbotToolManifest.php';

class ToolPlanner {
    private array $capabilities;

    public function __construct(array $capabilities = []) {
        $this->capabilities = $capabilities;
    }

    /**
     * Tool selection is driven by the manifest (config/chatbot_tools.php):
     * the allowed tool set and required slots come from one source of truth,
     * while per-tool argument builders stay here next to the tool contracts.
     */
    public function plan(array $intent): array {
        $primary = (string)($intent['primary_intent'] ?? 'unknown');
        $entities = is_array($intent['entities'] ?? null) ? $intent['entities'] : [];
        $allowed = ChatbotToolManifest::toolsFor($primary);

        if ($allowed === []) {
            return ['batches' => [], 'response_type' => ChatbotToolManifest::emptyResponse($primary)];
        }

        foreach (ChatbotToolManifest::requiredSlots($primary) as $slot) {
            if (empty($entities[$slot])) {
                return ['batches' => [], 'response_type' => ChatbotToolManifest::emptyResponse($primary)];
            }
        }

        $calls = [];
        foreach ($allowed as $tool) {
            $built = $this->buildCall($tool, $primary, $intent, $entities);
            if ($built !== null) $calls[] = $built;
        }

        // size_advice resolves measurements into missing_slots instead of
        // manifest required slots; never call the tool while slots are open.
        if ($primary === 'size_advice' && !empty($intent['missing_slots'])) {
            return ['batches' => [], 'response_type' => 'clarification'];
        }

        if ($calls === []) {
            return ['batches' => [], 'response_type' => ChatbotToolManifest::emptyResponse($primary)];
        }

        return [
            'batches' => [$calls],
            'response_type' => 'final_answer',
            'selected_capabilities' => array_values(array_unique(array_map(fn($call) => (string)$call['tool'], $calls))),
            'capability_definitions_version' => $this->capabilities === [] ? 'legacy' : 'capability_registry_v1',
        ];
    }

    /** @return array{tool:string,args:array,id:string}|null */
    private function buildCall(string $tool, string $primary, array $intent, array $entities): ?array {
        switch ($tool) {
            case 'suggest_complementary_products':
                if ($primary !== 'suggest_complementary_products') return null;
                $args = ['product_id' => (int)$entities['product_id']];
                if (!empty($entities['variant_id'])) $args['variant_id'] = (int)$entities['variant_id'];
                return ['tool' => $tool, 'args' => $args, 'id' => 'complementary_products'];

            case 'get_product_detail':
                if (empty($entities['product_id'])) return null;
                return ['tool' => $tool, 'args' => ['product_id' => (int)$entities['product_id']], 'id' => 'product_detail'];

            case 'search_products':
                if ($primary === 'suggest_complementary_products') return null;
                if (empty($entities['product_type']) && $primary !== 'mixed_product_policy') return null;
                if ($primary === 'mixed_product_policy' && (!empty($entities['product_id']) || empty($entities['product_type']))) return null;
                return ['tool' => $tool, 'args' => $this->searchArgs($entities), 'id' => 'product_search'];

            case 'retrieve_knowledge':
                if (!in_array($primary, ['return_exchange', 'shipping', 'policy', 'mixed_product_policy'], true)) return null;
                return ['tool' => $tool, 'args' => $this->knowledgeArgs($intent), 'id' => 'knowledge'];

            case 'suggest_size':
                if ($primary !== 'size_advice') return null;
                $args = [
                    'height' => (int)($entities['height'] ?? 0),
                    'weight' => (int)($entities['weight'] ?? 0),
                ];
                if (!empty($entities['category_id'])) $args['category_id'] = (int)$entities['category_id'];
                return ['tool' => $tool, 'args' => $args, 'id' => 'size'];

            case 'get_order_status':
                if ($primary !== 'order_status') return null;
                $args = [];
                if (!empty($entities['order_id'])) $args['order_id'] = (int)$entities['order_id'];
                return ['tool' => $tool, 'args' => $args, 'id' => 'order'];

            default:
                return null;
        }
    }

    private function knowledgeArgs(array $intent): array {
        $query = (string)($intent['sub_queries']['knowledge'] ?? '');
        if ($query === '') $query = (string)($intent['original_query'] ?? '');
        $args = ['query' => $query, 'limit' => 5];
        $category = $this->knowledgeCategory($intent);
        if ($category !== null) $args['category'] = $category;
        return $args;
    }

    /** Build MCP-valid search arguments from the parser's finer-grained taxonomy. */
    private function searchArgs(array $entities): array {
        $args = ['search' => (string)($entities['product_type'] ?? '')];
        foreach (['min_price', 'max_price', 'category_id', 'subcategory', 'color', 'size', 'in_stock', 'occasion', 'style', 'avoid', 'semantic_query'] as $key) {
            if (isset($entities[$key])) $args[$key] = $entities[$key];
        }
        $category = strtolower(trim((string)($entities['category'] ?? '')));
        $categoryMap = [
            'tops' => 'tops', 'top' => 'tops', 'shirt' => 'tops', 't_shirt' => 'tops', 'jacket' => 'tops',
            'hoodie' => 'tops', 'polo' => 'tops', 'sweater' => 'tops', 'vest' => 'tops', 'blazer' => 'tops',
            'bottoms' => 'bottoms', 'bottom' => 'bottoms', 'jeans' => 'bottoms', 'trousers' => 'bottoms',
            'shorts' => 'bottoms', 'joggers' => 'bottoms',
            'dresses_skirts' => 'dresses_skirts', 'dress' => 'dresses_skirts', 'skirt' => 'dresses_skirts', 'maxi_dress' => 'dresses_skirts',
            'accessories' => 'accessories', 'accessory' => 'accessories', 'bag' => 'accessories', 'watch' => 'accessories', 'belt' => 'accessories', 'sunglasses' => 'accessories',
            'footwear' => 'footwear',
        ];
        if (isset($categoryMap[$category])) {
            $args['category'] = $categoryMap[$category];
        } elseif (isset($args['category_id'])) {
            $args['category'] = [1 => 'tops', 2 => 'bottoms', 3 => 'dresses_skirts', 4 => 'accessories', 5 => 'footwear'][(int)$args['category_id']] ?? null;
            if ($args['category'] === null) unset($args['category']);
        }
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
