<?php

require_once __DIR__ . '/../ProductAttributeNormalizer.php';

class EvidenceNormalizer {
    public function normalize(array $intent, array $execution): array {
        $cards = [];
        $knowledgeSources = [];
        $evidence = [];
        $complementaryGroups = [];
        $toolResults = [];

        foreach (($execution['results'] ?? []) as $id => $entry) {
            $tool = (string)($entry['tool'] ?? '');
            $result = is_array($entry['result'] ?? null) ? $entry['result'] : [];
            $toolResults[$id] = [
                'tool' => $tool,
                'success' => (bool)($entry['success'] ?? false),
                'duration_ms' => (int)($entry['duration_ms'] ?? 0),
            ];

            if ($tool === 'search_products') {
                $this->normalizeProductList($result, $cards, $evidence);
            } elseif ($tool === 'get_product_detail') {
                $this->normalizeProductDetail($result, $cards, $evidence);
            } elseif ($tool === 'retrieve_knowledge') {
                $this->normalizeKnowledge($result, $knowledgeSources, $evidence);
            } elseif ($tool === 'suggest_size') {
                $this->normalizeSize($result, $evidence);
            } elseif ($tool === 'get_order_status') {
                $this->normalizeOrder($result, $evidence);
            } elseif ($tool === 'suggest_complementary_products') {
                $this->normalizeComplementary($result, $cards, $evidence, $complementaryGroups);
            }
        }

        return [
            'cards' => $this->dedupeCards($cards),
            'knowledge_sources' => $this->dedupeKnowledgeSources($knowledgeSources),
            'evidence' => $evidence,
            'complementary_groups' => $complementaryGroups,
            'tool_results' => $toolResults,
        ];
    }

    private function normalizeProductList(array $result, array &$cards, array &$evidence): void {
        $products = is_array($result['products'] ?? null) ? $result['products'] : [];
        foreach ($products as $product) {
            if (!is_array($product)) continue;
            $card = $this->productCard($product);
            if ((int)$card['id'] <= 0) continue;
            $cards[] = $card;
            $this->addProductFacts($card, $evidence, 'product_search');
        }
        $total = isset($result['pagination']['total']) ? (int)$result['pagination']['total'] : count($products);
        $evidence[] = [
            'source' => 'product_search',
            'fact_type' => 'result_count',
            'value' => $total,
            'freshness' => date('c'),
            'confidence' => 1.0,
        ];
    }

    private function normalizeProductDetail(array $result, array &$cards, array &$evidence): void {
        $product = is_array($result['product'] ?? null) ? $result['product'] : null;
        if ($product === null) {
            $evidence[] = [
                'source' => 'product_detail',
                'fact_type' => 'not_found',
                'value' => (string)($result['error'] ?? 'not_found'),
                'freshness' => date('c'),
                'confidence' => 1.0,
            ];
            return;
        }

        $card = $this->productCard($product);
        $card['description'] = (string)($product['description'] ?? '');
        $card['available_sizes'] = $this->extractSizes($product);
        $cards[] = $card;
        $this->addProductFacts($card, $evidence, 'product_detail');
        if ($card['available_sizes'] !== []) {
            $evidence[] = [
                'source' => 'product_detail',
                'fact_type' => 'available_sizes',
                'product_id' => (int)$card['id'],
                'value' => $card['available_sizes'],
                'freshness' => date('c'),
                'confidence' => 1.0,
            ];
        }
    }

    private function normalizeKnowledge(array $result, array &$knowledgeSources, array &$evidence): void {
        foreach (($result['results'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $source = [
                'source' => (string)($item['source'] ?? ''),
                'title' => (string)($item['title'] ?? ''),
                'category' => (string)($item['category'] ?? ''),
                'score' => isset($item['score']) ? (float)$item['score'] : null,
                'retrieval_mode' => (string)($item['retrieval_mode'] ?? ($result['retrieval_mode'] ?? '')),
            ];
            $knowledgeSources[] = $source;
            $evidence[] = [
                'source' => 'policy_rag',
                'fact_type' => (string)($item['category'] ?? 'policy'),
                'value' => trim((string)($item['content'] ?? '')),
                'title' => (string)($item['title'] ?? ''),
                'document_source' => (string)($item['source'] ?? ''),
                'freshness' => (string)($item['updated_at'] ?? date('c')),
                'confidence' => isset($item['score']) ? (float)$item['score'] : 0.7,
            ];
        }
    }

    private function normalizeSize(array $result, array &$evidence): void {
        $recommended = is_array($result['recommended'] ?? null) ? $result['recommended'] : null;
        if ($recommended !== null) {
            $evidence[] = [
                'source' => 'size_guide',
                'fact_type' => 'recommended_size',
                'value' => (string)($recommended['size_name'] ?? ''),
                'height_from' => $recommended['height_from'] ?? null,
                'height_to' => $recommended['height_to'] ?? null,
                'weight_from' => $recommended['weight_from'] ?? null,
                'weight_to' => $recommended['weight_to'] ?? null,
                'freshness' => date('c'),
                'confidence' => 1.0,
            ];
        }

        $sizes = is_array($result['sizes'] ?? null) ? $result['sizes'] : [];
        if ($sizes !== []) {
            $evidence[] = [
                'source' => 'size_guide',
                'fact_type' => 'size_chart',
                'value' => array_values(array_map(fn($s) => is_array($s) ? [
                    'size_name' => (string)($s['size_name'] ?? ''),
                    'height_from' => $s['height_from'] ?? null,
                    'height_to' => $s['height_to'] ?? null,
                    'weight_from' => $s['weight_from'] ?? null,
                    'weight_to' => $s['weight_to'] ?? null,
                ] : $s, $sizes)),
                'freshness' => date('c'),
                'confidence' => 1.0,
            ];
        }
    }

    private function normalizeOrder(array $result, array &$evidence): void {
        if (!empty($result['requires_login'])) {
            $evidence[] = [
                'source' => 'order_service',
                'fact_type' => 'requires_login',
                'value' => true,
                'freshness' => date('c'),
                'confidence' => 1.0,
            ];
            return;
        }

        foreach (($result['orders'] ?? []) as $order) {
            if (!is_array($order)) continue;
            $evidence[] = [
                'source' => 'order_service',
                'fact_type' => 'order_status',
                'order_id' => (int)($order['id'] ?? 0),
                'value' => (string)($order['status'] ?? ''),
                'total_price' => isset($order['total_price']) ? (float)$order['total_price'] : null,
                'created_at' => (string)($order['created_at'] ?? ''),
                'freshness' => date('c'),
                'confidence' => 1.0,
            ];
        }
    }

    private function normalizeComplementary(array $result, array &$cards, array &$evidence, array &$groups): void {
        $groups = is_array($result['groups'] ?? null) ? $result['groups'] : [];
        foreach ($groups as $group) {
            if (!is_array($group)) continue;
            $products = is_array($group['products'] ?? null) ? $group['products'] : [];
            foreach ($products as $product) {
                if (!is_array($product)) continue;
                $card = $this->productCard($product);
                if ((int) $card['id'] <= 0) continue;
                $cards[] = $card;
                $this->addProductFacts($card, $evidence, 'complementary_product_search');
            }
        }
        $evidence[] = [
            'source' => 'complementary_product_search',
            'fact_type' => 'complementary_groups',
            'value' => $groups,
            'freshness' => date('c'),
            'confidence' => 1.0,
        ];
    }

    private function productCard(array $product): array {
        $id = (int)($product['id'] ?? 0);
        $image = (string)($product['image'] ?? '');
        return [
            'id' => $id,
            'name' => (string)($product['name'] ?? ''),
            'description' => (string)($product['description'] ?? ''),
            'price' => (float)($product['price'] ?? 0),
            'stock' => (int)($product['stock'] ?? 0),
            'stock_status' => ((int)($product['stock'] ?? 0) > 0) ? 'in_stock' : 'out_of_stock',
            'category_id' => isset($product['category_id']) ? (int)$product['category_id'] : null,
            'category_name' => (string)($product['category_name'] ?? ''),
            'available_sizes' => $this->extractSizes($product),
            'available_colors' => $this->extractColors($product),
            'canonical_colors' => is_array($product['canonical_colors'] ?? null) ? $product['canonical_colors'] : [],
            'colors' => is_array($product['colors'] ?? null) ? $product['colors'] : [],
            'variants' => is_array($product['variants'] ?? null) ? $product['variants'] : [],
            'subcategory' => isset($product['subcategory']) ? (string)$product['subcategory'] : null,
            'subcategory_name' => isset($product['subcategory_name']) ? (string)$product['subcategory_name'] : null,
            'image' => $image,
            'image_url' => $this->productImageUrl($image, (string)($product['image_url'] ?? '')),
            'url' => $id > 0 ? '/product.php?id=' . $id : '',
        ];
    }

    private function productImageUrl(string $image, string $imageUrl = ''): string {
        $raw = trim($image !== '' ? $image : $imageUrl);
        if ($raw === '') {
            return '';
        }

        $path = parse_url($raw, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : $raw;
        $path = ltrim($path, '/');

        if (str_starts_with($path, 'images/')) {
            return '/' . $path;
        }

        return '/images/' . $path;
    }

    private function addProductFacts(array $card, array &$evidence, string $source): void {
        foreach (['name', 'price', 'stock', 'stock_status'] as $field) {
            $evidence[] = [
                'source' => $source,
                'fact_type' => $field,
                'product_id' => (int)$card['id'],
                'value' => $card[$field],
                'freshness' => date('c'),
                'confidence' => 1.0,
            ];
        }
        if (!empty($card['available_sizes'])) {
            $evidence[] = [
                'source' => $source,
                'fact_type' => 'available_sizes',
                'product_id' => (int)$card['id'],
                'value' => $card['available_sizes'],
                'freshness' => date('c'),
                'confidence' => 1.0,
            ];
        }
        if (!empty($card['available_colors'])) {
            $evidence[] = [
                'source' => $source,
                'fact_type' => 'available_colors',
                'product_id' => (int)$card['id'],
                'value' => $card['available_colors'],
                'freshness' => date('c'),
                'confidence' => 1.0,
            ];
        }
    }

    private function extractSizes(array $product): array {
        return ProductAttributeNormalizer::productSizes($product);
    }

    private function extractColors(array $product): array {
        if (!empty($product['available_colors']) && is_array($product['available_colors'])) {
            return array_values(array_unique(array_map('strval', $product['available_colors'])));
        }
        return ProductAttributeNormalizer::extractColorsFromProduct($product);
    }

    private function dedupeCards(array $cards): array {
        $seen = [];
        $out = [];
        foreach ($cards as $card) {
            $id = (int)($card['id'] ?? 0);
            if ($id <= 0 || isset($seen[$id])) continue;
            $seen[$id] = true;
            $out[] = $card;
        }
        return $out;
    }

    private function dedupeKnowledgeSources(array $sources): array {
        $seen = [];
        $out = [];
        foreach ($sources as $source) {
            $key = sha1(json_encode($source, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $source;
        }
        return $out;
    }
}
