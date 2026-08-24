<?php

require_once __DIR__ . '/../ProductAttributeNormalizer.php';

class LightweightEvidenceScorer {
    public const MIN_EVIDENCE_SCORE = 0.75;
    public const MIN_REQUIRED_FACT_COVERAGE = 0.75;
    public const MIN_RAG_RESULT_COUNT = 1;
    public const MIN_RAG_CONFIDENCE = 0.50;

    public function score(array $intent, array $normalized, array $observation): array {
        $primary = (string)($intent['primary_intent'] ?? 'unknown');
        $required = $this->requiredFacts($primary, $intent);
        $present = [];
        $missing = [];

        foreach ($required as $fact) {
            if ($this->hasFact($fact, $intent, $normalized)) {
                $present[] = $fact;
            } else {
                $missing[] = $fact;
            }
        }

        $coverage = $required === [] ? 1.0 : count($present) / max(1, count($required));
        $sourceReliability = $this->sourceReliability($normalized);
        $retrievalQuality = $this->retrievalQuality($intent, $normalized);
        $contradictionScore = $this->contradictionScore($intent, $normalized, $observation);
        $score = 0.40 * $coverage + 0.25 * $sourceReliability + 0.20 * $retrievalQuality + 0.15 * $contradictionScore;

        if (!empty($observation['hard_failures'])) {
            $score = min($score, 0.30);
        }

        $passed = $score >= self::MIN_EVIDENCE_SCORE
            && $coverage >= self::MIN_REQUIRED_FACT_COVERAGE
            && empty($observation['hard_failures']);

        return [
            'passed' => $passed,
            'score' => round($score, 4),
            'coverage' => round($coverage, 4),
            'source_reliability' => round($sourceReliability, 4),
            'retrieval_quality' => round($retrievalQuality, 4),
            'contradiction_score' => round($contradictionScore, 4),
            'required_facts' => $required,
            'present_evidence' => $present,
            'missing_evidence' => $missing,
            'recommended_next_action' => $this->recommendedAction($primary, $missing, $normalized, $observation),
        ];
    }

    private function requiredFacts(string $primary, array $intent): array {
        $requested = is_array($intent['requested_fields'] ?? null) ? $intent['requested_fields'] : [];
        $facts = match ($primary) {
            'product_search' => ['result_count', 'product_cards'],
            'product_detail' => ['product_id', 'product_name', 'price', 'stock', 'product_card_url', 'product_card_image'],
            'size_advice' => ['height', 'weight', 'recommended_size', 'size_chart'],
            'return_exchange', 'shipping', 'policy' => ['policy_source', 'policy_content'],
            'mixed_product_policy' => ['product_evidence', 'policy_source', 'policy_content'],
            'order_status' => ['order_status_or_login'],
            'suggest_complementary_products' => ['complementary_products'],
            'unsupported_outfit', 'unsupported_checkout', 'unknown' => [],
            default => [],
        };

        if (in_array('price', $requested, true) && !in_array('price_constraint', $facts, true)) {
            $facts[] = 'price_constraint';
        }
        if (in_array('stock', $requested, true) && !in_array('stock_constraint', $facts, true)) {
            $facts[] = 'stock_constraint';
        }
        if (in_array('size', $requested, true) && !in_array('requested_size_evidence', $facts, true)) {
            $facts[] = 'requested_size_evidence';
        }
        foreach (['product_type', 'color', 'material', 'style', 'occasion', 'avoid', 'semantic_query'] as $field) {
            if (!empty($intent['entities'][$field] ?? null)) {
                $facts[] = $field . '_constraint';
            }
        }

        return array_values(array_unique($facts));
    }

    private function hasFact(string $fact, array $intent, array $normalized): bool {
        $cards = is_array($normalized['cards'] ?? null) ? $normalized['cards'] : [];
        $evidence = is_array($normalized['evidence'] ?? null) ? $normalized['evidence'] : [];
        $entities = is_array($intent['entities'] ?? null) ? $intent['entities'] : [];

        return match ($fact) {
            'result_count' => $this->hasEvidenceType($evidence, 'result_count'),
            'product_cards' => $cards !== [],
            'product_id' => $this->productIdMatches($cards, (int)($entities['product_id'] ?? 0)),
            'product_name' => isset($cards[0]['name']) && trim((string)$cards[0]['name']) !== '',
            'price' => isset($cards[0]['price']) && (float)$cards[0]['price'] >= 0,
            'stock' => array_key_exists('stock', $cards[0] ?? []),
            'product_card_url' => isset($cards[0]['url']) && preg_match('/^\/product\.php\?id=\d+$/', (string)$cards[0]['url']) === 1,
            'product_card_image' => trim((string)($cards[0]['image_url'] ?? '')) !== ''
                && !str_contains((string)$cards[0]['image_url'], 'localhost'),
            'height' => !empty($entities['height']),
            'weight' => !empty($entities['weight']),
            'recommended_size' => $this->hasEvidenceType($evidence, 'recommended_size'),
            'size_chart' => $this->hasEvidenceType($evidence, 'size_chart'),
            'policy_source' => $this->policyEvidence($evidence) !== [],
            'policy_content' => $this->policyContentMatches($intent, $evidence),
            'product_evidence' => $cards !== [] || $this->hasAnySource($evidence, ['product_search', 'product_detail']),
            'order_status_or_login' => $this->hasEvidenceType($evidence, 'order_status') || $this->hasEvidenceType($evidence, 'requires_login'),
            'complementary_products' => $cards !== [] || $this->hasEvidenceType($evidence, 'complementary_groups'),
            'price_constraint' => $this->priceConstraintsPass($cards, $entities),
            'stock_constraint' => $this->stockConstraintPass($cards, $entities),
            'requested_size_evidence' => $this->requestedSizeEvidence($cards, $evidence, (string)($entities['size'] ?? '')),
            'product_type_constraint' => $this->productTypeConstraintPass($cards, $entities),
            'color_constraint' => $this->textConstraintPass($cards, $entities, 'color'),
            'material_constraint' => $this->textConstraintPass($cards, $entities, 'material'),
            'style_constraint' => $this->textConstraintPass($cards, $entities, 'style'),
            'occasion_constraint' => $this->textConstraintPass($cards, $entities, 'occasion'),
            'semantic_query_constraint' => $this->textConstraintPass($cards, $entities, 'semantic_query'),
            'avoid_constraint' => $this->avoidConstraintPass($cards, $entities),
            default => false,
        };
    }

    private function productIdMatches(array $cards, int $requested): bool {
        if ($requested <= 0 || empty($cards[0]['id'])) return false;
        return (int)$cards[0]['id'] === $requested;
    }

    private function hasEvidenceType(array $evidence, string $type): bool {
        foreach ($evidence as $item) {
            if (($item['fact_type'] ?? '') === $type) return true;
        }
        return false;
    }

    private function hasAnySource(array $evidence, array $sources): bool {
        foreach ($evidence as $item) {
            if (in_array((string)($item['source'] ?? ''), $sources, true)) return true;
        }
        return false;
    }

    private function policyEvidence(array $evidence): array {
        return array_values(array_filter($evidence, fn($item) => ($item['source'] ?? '') === 'policy_rag' && trim((string)($item['value'] ?? '')) !== ''));
    }

    private function policyContentMatches(array $intent, array $evidence): bool {
        $policy = $this->policyEvidence($evidence);
        if ($policy === []) return false;
        $query = mb_strtolower((string)($intent['original_query'] ?? ''));
        $joined = mb_strtolower(implode(' ', array_map(fn($item) => (string)($item['value'] ?? '') . ' ' . (string)($item['fact_type'] ?? ''), $policy)));

        $groups = [
            'return' => '/đổi|trả|hoàn tiền|sale|size/u',
            'shipping' => '/ship|vận chuyển|giao hàng/u',
            'warranty' => '/bảo hành|lỗi/u',
            'payment' => '/thanh toán|trả tiền|chuyển khoản/u',
        ];
        $matchedDomain = false;
        foreach ($groups as $pattern) {
            if (preg_match($pattern, $query)) {
                $matchedDomain = true;
                if (preg_match($pattern, $joined)) {
                    return true;
                }
            }
        }
        return !$matchedDomain;
    }

    private function priceConstraintsPass(array $cards, array $entities): bool {
        if ($cards === []) return false;
        foreach ($cards as $card) {
            $price = (float)($card['price'] ?? -1);
            if (isset($entities['min_price']) && $price < (float)$entities['min_price']) return false;
            if (isset($entities['max_price']) && $price > (float)$entities['max_price']) return false;
        }
        return true;
    }

    private function stockConstraintPass(array $cards, array $entities): bool {
        if ($cards === []) return false;
        if (!array_key_exists('in_stock', $entities)) {
            return true;
        }
        if ((bool)$entities['in_stock'] !== true) {
            return true;
        }
        foreach ($cards as $card) {
            if ((int)($card['stock'] ?? 0) <= 0) return false;
        }
        return true;
    }

    private function requestedSizeEvidence(array $cards, array $evidence, string $requestedSize): bool {
        if ($requestedSize === '') return true;
        $requestedSize = strtoupper($requestedSize);
        foreach ($cards as $card) {
            $sizes = array_map('strtoupper', array_map('strval', $card['available_sizes'] ?? []));
            if (in_array($requestedSize, $sizes, true)) return true;
        }
        foreach ($evidence as $item) {
            if (($item['fact_type'] ?? '') === 'available_sizes') {
                $sizes = array_map('strtoupper', array_map('strval', is_array($item['value'] ?? null) ? $item['value'] : []));
                if (in_array($requestedSize, $sizes, true)) return true;
            }
        }
        return false;
    }

    private function productTypeConstraintPass(array $cards, array $entities): bool {
        if ($cards === []) return false;
        if (empty($entities['product_type'])) return true;
        if (!empty($entities['category_id'])) {
            foreach ($cards as $card) {
                if ((int)($card['category_id'] ?? 0) !== (int)$entities['category_id']) return false;
            }
        }
        $type = (string)$entities['product_type'];
        if (in_array($type, ['áo', 'quần', 'váy', 'phụ kiện'], true)) return true;
        $words = array_values(array_filter(preg_split('/\s+/u', ProductAttributeNormalizer::normalizeText($type)) ?: [], fn($word) => mb_strlen($word) >= 2));
        foreach ($cards as $card) {
            $name = ProductAttributeNormalizer::normalizeText((string)($card['name'] ?? ''));
            foreach ($words as $word) {
                if (mb_strpos($name, $word) === false) return false;
            }
        }
        return true;
    }

    private function textConstraintPass(array $cards, array $entities, string $field): bool {
        if ($cards === []) return false;
        if (empty($entities[$field])) return true;
        foreach ($cards as $card) {
            if ($field === 'color') {
                $colors = array_map('strval', $card['available_colors'] ?? []);
                if (in_array((string)$entities[$field], $colors, true)) continue;
                if (ProductAttributeNormalizer::textMatchesColor(ProductAttributeNormalizer::productText($card), (string)$entities[$field])) continue;
                return false;
            }
            if (!ProductAttributeNormalizer::textMatchesAny(ProductAttributeNormalizer::productText($card), $entities[$field])) return false;
        }
        return true;
    }

    private function avoidConstraintPass(array $cards, array $entities): bool {
        if ($cards === []) return false;
        if (empty($entities['avoid'])) return true;
        foreach ($cards as $card) {
            if (ProductAttributeNormalizer::textMatchesAny(ProductAttributeNormalizer::productText($card), $entities['avoid'])) return false;
        }
        return true;
    }

    private function sourceReliability(array $normalized): float {
        $evidence = is_array($normalized['evidence'] ?? null) ? $normalized['evidence'] : [];
        if ($evidence === []) return 0.0;
        $scores = [];
        foreach ($evidence as $item) {
            $source = (string)($item['source'] ?? '');
            $scores[] = match ($source) {
                'product_search', 'product_detail', 'size_guide', 'order_service' => 1.0,
                'policy_rag' => 0.9,
                default => 0.6,
            };
        }
        return array_sum($scores) / max(1, count($scores));
    }

    private function retrievalQuality(array $intent, array $normalized): float {
        $primary = (string)($intent['primary_intent'] ?? '');
        if (!in_array($primary, ['return_exchange', 'shipping', 'policy', 'mixed_product_policy'], true)) {
            return 1.0;
        }
        $policy = $this->policyEvidence(is_array($normalized['evidence'] ?? null) ? $normalized['evidence'] : []);
        if ($policy === []) return 0.0;
        $confidences = [];
        foreach ($policy as $item) {
            if (isset($item['confidence']) && is_numeric($item['confidence'])) {
                $confidences[] = max(0.0, min(1.0, (float)$item['confidence']));
            }
        }
        if ($confidences === []) {
            return count($policy) >= self::MIN_RAG_RESULT_COUNT ? 0.7 : 0.4;
        }
        return array_sum($confidences) / max(1, count($confidences));
    }

    private function contradictionScore(array $intent, array $normalized, array $observation): float {
        if (!empty($observation['hard_failures'])) {
            return 0.0;
        }
        $primary = (string)($intent['primary_intent'] ?? '');
        if ($primary === 'product_detail') {
            $requested = (int)($intent['entities']['product_id'] ?? 0);
            if ($requested > 0 && !$this->productIdMatches($normalized['cards'] ?? [], $requested)) {
                return 0.0;
            }
        }
        return 1.0;
    }

    private function recommendedAction(string $primary, array $missing, array $normalized, array $observation): string {
        if (!empty($observation['hard_failures'])) {
            return in_array('requires_login', $observation['hard_failures'], true) ? 'deny' : 'fallback';
        }
        if ($missing === []) return 'return';
        if ($primary === 'mixed_product_policy' && (in_array('policy_source', $missing, true) || in_array('policy_content', $missing, true))) {
            return 'call_next_tool';
        }
        if (in_array('policy_source', $missing, true) || in_array('policy_content', $missing, true)) {
            return 'rewrite_query';
        }
        if (in_array('product_cards', $missing, true)) {
            return 'rewrite_query';
        }
        return 'fallback';
    }
}
