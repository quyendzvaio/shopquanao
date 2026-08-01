<?php

class EvidenceDecisionRouter {
    public function decide(array $intent, array $plan, array $normalized, array $observation, array $score, array $budget, bool $noProgress): array {
        $primary = (string)($intent['primary_intent'] ?? 'unknown');

        if (in_array($primary, ['unsupported_outfit', 'unsupported_checkout'], true)) {
            return $this->decision('return', 'guardrail_fixed_response');
        }
        if ($primary === 'order_status' && $this->hasFact($normalized, 'requires_login')) {
            return $this->decision('deny', 'order_status_requires_login');
        }
        if ($this->missingRequiredSlots($intent) !== []) {
            return $this->decision('ask_user', 'missing_required_slots');
        }
        if (!empty($score['passed'])) {
            return $this->decision('return', 'evidence_score_passed');
        }
        if ($noProgress) {
            return $this->decision('fallback', 'no_progress_detected');
        }
        if ((int)($budget['loop_count'] ?? 1) >= EvidenceExecutionLoop::MAX_EXECUTION_LOOPS) {
            return $this->decision('fallback', 'loop_budget_exhausted');
        }
        if ((int)($budget['tool_calls'] ?? 0) >= EvidenceExecutionLoop::MAX_TOOL_CALLS_TOTAL) {
            return $this->decision('fallback', 'tool_budget_exhausted');
        }

        $missing = is_array($score['missing_evidence'] ?? null) ? $score['missing_evidence'] : [];
        if ($primary === 'mixed_product_policy') {
            if ((in_array('policy_source', $missing, true) || in_array('policy_content', $missing, true)) && !$this->planHasTool($plan, 'retrieve_knowledge')) {
                return $this->decision('call_next_tool', 'mixed_missing_policy_tool', 'retrieve_knowledge');
            }
            if (in_array('product_evidence', $missing, true) && !$this->hasProductTool($plan)) {
                return $this->decision('call_next_tool', 'mixed_missing_product_tool', !empty($intent['entities']['product_id']) ? 'get_product_detail' : 'search_products');
            }
        }
        if (($observation['has_tool_error'] ?? false) && (int)($budget['tool_retries'] ?? 0) < EvidenceExecutionLoop::MAX_TOOL_RETRIES) {
            return $this->decision('retry_tool', 'temporary_tool_error');
        }
        if ($this->canRewrite($primary, $missing) && (int)($budget['query_rewrites'] ?? 0) < EvidenceExecutionLoop::MAX_QUERY_REWRITES) {
            return $this->decision('rewrite_query', 'low_evidence_or_empty_result');
        }
        return $this->decision('fallback', 'evidence_score_failed');
    }

    private function decision(string $action, string $reason, ?string $nextTool = null): array {
        return ['action' => $action, 'reason' => $reason, 'next_tool' => $nextTool];
    }

    private function missingRequiredSlots(array $intent): array {
        $missing = is_array($intent['missing_slots'] ?? null) ? $intent['missing_slots'] : [];
        return ($intent['primary_intent'] ?? '') === 'size_advice'
            ? array_values(array_intersect($missing, ['height', 'weight']))
            : [];
    }

    private function hasFact(array $normalized, string $factType): bool {
        foreach (($normalized['evidence'] ?? []) as $item) {
            if (($item['fact_type'] ?? '') === $factType) return true;
        }
        return false;
    }

    private function planHasTool(array $plan, string $tool): bool {
        foreach (($plan['batches'] ?? []) as $batch) {
            foreach ($batch as $call) {
                if (($call['tool'] ?? '') === $tool) return true;
            }
        }
        return false;
    }

    private function hasProductTool(array $plan): bool {
        return $this->planHasTool($plan, 'search_products') || $this->planHasTool($plan, 'get_product_detail');
    }

    private function canRewrite(string $primary, array $missing): bool {
        if (in_array($primary, ['return_exchange', 'shipping', 'policy', 'mixed_product_policy'], true)) {
            return in_array('policy_source', $missing, true) || in_array('policy_content', $missing, true);
        }
        return $primary === 'product_search' && in_array('product_cards', $missing, true);
    }
}
