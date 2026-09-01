<?php

require_once __DIR__ . '/../ProductAttributeNormalizer.php';

class ProductConstraintVerifier {
    /**
     * Verify a normalized chatbot response. For backwards compatibility the
     * historical (product, entities) call shape returns a boolean match.
     *
     * @return array|bool
     */
    public function verify(array $intent, array $normalized): array|bool {
        $primary = (string)($intent['primary_intent'] ?? '');
        if (!in_array($primary, ['product_search', 'product_detail', 'mixed_product_policy'], true)) {
            if (isset($intent['id']) && (isset($intent['name']) || isset($intent['price']))) {
                return $this->matchesProductType($intent, $normalized)
                    && ProductAttributeNormalizer::productMatchesConstraints($intent, $normalized);
            }
            return $normalized;
        }

        $entities = is_array($intent['entities'] ?? null) ? $intent['entities'] : [];
        $cards = is_array($normalized['cards'] ?? null) ? $normalized['cards'] : [];
        if ($cards === []) {
            return $this->withConstraintEvidence($normalized, $entities, [], 0);
        }

        $passed = [];
        foreach ($cards as $card) {
            if (!is_array($card)) {
                continue;
            }
            if ($this->matchesProductType($card, $entities) && ProductAttributeNormalizer::productMatchesConstraints($card, $entities)) {
                $passed[] = $card;
            }
        }

        $normalized['cards'] = $passed;
        $normalized['evidence'] = $this->filterProductEvidence(
            is_array($normalized['evidence'] ?? null) ? $normalized['evidence'] : [],
            array_map(fn($card) => (int)($card['id'] ?? 0), $passed)
        );
        $normalized = $this->withConstraintEvidence($normalized, $entities, $passed, count($passed));
        return $normalized;
    }

    private function matchesProductType(array $card, array $entities): bool {
        if (!empty($entities['category_id']) && (int)($card['category_id'] ?? 0) !== (int)$entities['category_id']) {
            return false;
        }

        $productType = trim((string)($entities['product_type'] ?? ''));
        if ($productType === '') {
            return true;
        }

        $name = ProductAttributeNormalizer::normalizeText((string)($card['name'] ?? ''));
        $type = ProductAttributeNormalizer::normalizeText($productType);
        if ($type === '') {
            return true;
        }

        if (in_array($productType, ['áo', 'quần', 'váy', 'phụ kiện'], true)) {
            return true;
        }

        $words = array_values(array_filter(preg_split('/\s+/u', $type) ?: [], fn($word) => mb_strlen($word) >= 2));
        foreach ($words as $word) {
            if (mb_strpos($name, $word) === false) {
                return false;
            }
        }
        return true;
    }

    private function filterProductEvidence(array $evidence, array $allowedIds): array {
        $allowed = array_flip(array_values(array_filter($allowedIds, fn($id) => $id > 0)));
        $filtered = [];
        foreach ($evidence as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (($item['fact_type'] ?? '') === 'result_count') {
                continue;
            }
            if (isset($item['product_id']) && !isset($allowed[(int)$item['product_id']])) {
                continue;
            }
            $filtered[] = $item;
        }
        return $filtered;
    }

    private function withConstraintEvidence(array $normalized, array $entities, array $cards, int $count): array {
        $evidence = is_array($normalized['evidence'] ?? null) ? $normalized['evidence'] : [];
        $evidence[] = [
            'source' => 'constraint_verifier',
            'fact_type' => 'result_count',
            'value' => $count,
            'freshness' => date('c'),
            'confidence' => 1.0,
        ];
        $evidence[] = [
            'source' => 'constraint_verifier',
            'fact_type' => 'constraints',
            'value' => $this->compactConstraints($entities),
            'matched_product_ids' => array_values(array_map(fn($card) => (int)($card['id'] ?? 0), $cards)),
            'freshness' => date('c'),
            'confidence' => 1.0,
        ];
        $normalized['evidence'] = $evidence;
        return $normalized;
    }

    private function compactConstraints(array $entities): array {
        $keys = ['product_type', 'category_id', 'color', 'size', 'min_price', 'max_price', 'in_stock', 'material', 'style', 'occasion', 'avoid', 'semantic_query'];
        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $entities) && $entities[$key] !== null && $entities[$key] !== '') {
                $out[$key] = $entities[$key];
            }
        }
        return $out;
    }
}
