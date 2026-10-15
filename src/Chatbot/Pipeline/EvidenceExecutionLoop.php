<?php

/**
 * Executes a deterministic tool plan and retries only when rule-based evidence
 * checks request it. There is no model reasoning or model-driven tool choice.
 */
class EvidenceExecutionLoop {
    public const MAX_EXECUTION_LOOPS = 3;
    public const MAX_TOOL_CALLS_TOTAL = 4;
    public const MAX_QUERY_REWRITES = 1;
    public const MAX_TOOL_RETRIES = 1;

    private PlanValidator $planValidator;
    private ToolPlanner $planner;
    private ParallelToolExecutor $executor;
    private EvidenceNormalizer $normalizer;
    private ProductConstraintVerifier $constraintVerifier;
    private ObservationEvaluator $observationEvaluator;
    private LightweightEvidenceScorer $scorer;
    private EvidenceDecisionRouter $router;
    private NoProgressDetector $noProgress;

    public function __construct(ChatbotToolGateway $toolGateway, array $capabilities) {
        $this->planValidator = new PlanValidator($capabilities);
        $this->planner = new ToolPlanner($capabilities);
        $this->executor = new ParallelToolExecutor($toolGateway);
        $this->normalizer = new EvidenceNormalizer();
        $this->constraintVerifier = new ProductConstraintVerifier();
        $this->observationEvaluator = new ObservationEvaluator();
        $this->scorer = new LightweightEvidenceScorer();
        $this->router = new EvidenceDecisionRouter();
        $this->noProgress = new NoProgressDetector();
    }

    public function run(string $message, array $intent, array $memoryContext): array {
        $trace = [];
        $spans = [];
        $executions = [];
        $finalPlan = ['batches' => [], 'response_type' => 'final_answer'];
        $finalNormalized = ['cards' => [], 'knowledge_sources' => [], 'evidence' => [], 'tool_results' => []];
        $finalScore = [];
        $finalDecision = ['action' => 'fallback', 'reason' => 'not_started'];
        $previousScore = [];
        $toolCalls = 0;
        $rewrites = 0;
        $retries = 0;

        for ($step = 1; $step <= self::MAX_EXECUTION_LOOPS; $step++) {
            $state = $this->executionState($intent, $memoryContext, $step, $previousScore);
            $plan = $this->planner->plan($intent);
            $plan = $this->applyDecisionToPlan($plan, $intent, $finalDecision, $message, $rewrites);

            $validation = $this->planValidator->validate($plan, $intent);
            $plan = $validation['sanitized_plan'];
            $finalPlan = $plan;
            if (!$validation['passed']) {
                $finalDecision = ['action' => 'fallback', 'reason' => 'plan_validation_failed'];
                $trace[] = $this->traceStep($step, $state, $plan, [], [], [], $finalDecision, false, $validation['errors']);
                break;
            }

            $plannedToolCount = $this->countTools($plan);
            if ($toolCalls + $plannedToolCount > self::MAX_TOOL_CALLS_TOTAL) {
                $finalDecision = ['action' => 'fallback', 'reason' => 'tool_budget_exhausted'];
                $trace[] = $this->traceStep($step, $state, $plan, [], [], [], $finalDecision, false, []);
                break;
            }

            if ($plannedToolCount === 0) {
                $observation = [
                    'tool_statuses' => [],
                    'temporary_errors' => [],
                    'hard_failures' => [],
                    'has_tool_error' => false,
                    'has_hard_failure' => false,
                ];
                $finalScore = $this->scorer->score($intent, $finalNormalized, $observation);
                $finalDecision = $this->router->decide(
                    $intent,
                    $plan,
                    $finalNormalized,
                    $observation,
                    $finalScore,
                    $this->budget($step, $toolCalls, $rewrites, $retries),
                    false
                );
                $trace[] = $this->traceStep($step, $state, $plan, $observation, $finalScore, [], $finalDecision, false, []);
                break;
            }

            $execution = $this->executor->execute($plan);
            $executions[] = $execution;
            $toolCalls += $plannedToolCount;
            foreach (($execution['spans'] ?? []) as $key => $value) {
                $spans[$key] = is_numeric($value) ? (($spans[$key] ?? 0) + (int)$value) : $value;
            }

            $finalNormalized = $this->constraintVerifier->verify(
                $intent,
                $this->normalizer->normalize($intent, $execution)
            );
            $observation = $this->observationEvaluator->evaluate($intent, $plan, $execution, $finalNormalized);
            $progress = $this->noProgress->observe($execution);
            $finalScore = $this->scorer->score($intent, $finalNormalized, $observation);
            $finalDecision = $this->router->decide(
                $intent,
                $plan,
                $finalNormalized,
                $observation,
                $finalScore,
                $this->budget($step, $toolCalls, $rewrites, $retries),
                (bool)$progress['no_progress']
            );

            $trace[] = $this->traceStep(
                $step,
                $state,
                $plan,
                $observation,
                $finalScore,
                $progress['fingerprints'],
                $finalDecision,
                (bool)$progress['no_progress'],
                []
            );

            if ($finalDecision['action'] === 'return' || in_array($finalDecision['action'], ['ask_user', 'deny', 'fallback'], true)) {
                break;
            }
            if ($finalDecision['action'] === 'rewrite_query') {
                $rewrites++;
            } elseif ($finalDecision['action'] === 'retry_tool') {
                $retries++;
            } elseif ($finalDecision['action'] !== 'call_next_tool') {
                break;
            }
            $previousScore = $finalScore;
        }

        return [
            'normalized' => $finalNormalized,
            'plan' => $finalPlan,
            'executions' => $executions,
            'trace' => $trace,
            'evidence_score' => $finalScore,
            'decision' => $finalDecision,
            'loop_count' => count($trace),
            'spans' => $spans,
        ];
    }

    private function budget(int $step, int $toolCalls, int $rewrites, int $retries): array {
        return [
            'loop_count' => $step,
            'tool_calls' => $toolCalls,
            'query_rewrites' => $rewrites,
            'tool_retries' => $retries,
        ];
    }

    private function applyDecisionToPlan(array $plan, array $intent, array $previousDecision, string $message, int $rewrites): array {
        $action = (string)($previousDecision['action'] ?? '');
        if ($action === 'rewrite_query' && $rewrites < self::MAX_QUERY_REWRITES) {
            $plan = $this->rewritePlanQueries($plan, $intent, $message);
        }
        if ($action === 'call_next_tool' && !empty($previousDecision['next_tool'])) {
            $plan = $this->onlyTool($plan, (string)$previousDecision['next_tool']);
        }
        return $plan;
    }

    private function rewritePlanQueries(array $plan, array $intent, string $message): array {
        $rewritten = $this->rewriteQuery($intent, $message);
        foreach (($plan['batches'] ?? []) as &$batch) {
            foreach ($batch as &$call) {
                $tool = (string)($call['tool'] ?? '');
                if ($tool === 'retrieve_knowledge') {
                    $call['args']['query'] = $rewritten;
                    $call['args']['limit'] = 5;
                } elseif ($tool === 'search_products' && !empty($intent['entities']['product_type'])) {
                    $call['args']['search'] = (string)$intent['entities']['product_type'];
                }
            }
        }
        unset($batch, $call);
        return $plan;
    }

    private function rewriteQuery(array $intent, string $message): string {
        $primary = (string)($intent['primary_intent'] ?? '');
        $query = trim($message);
        if (in_array($primary, ['return_exchange', 'mixed_product_policy'], true)) {
            return trim($query . ' chính sách đổi trả đổi size hoàn tiền phí vận chuyển điều kiện áp dụng');
        }
        if ($primary === 'shipping') {
            return trim($query . ' phí ship giao hàng vận chuyển nội thành ngoại tỉnh');
        }
        if ($primary === 'policy') {
            return trim($query . ' chính sách quy định shop');
        }
        return $query;
    }

    private function onlyTool(array $plan, string $tool): array {
        $filtered = [];
        foreach (($plan['batches'] ?? []) as $batch) {
            $calls = array_values(array_filter($batch, fn($call) => (string)($call['tool'] ?? '') === $tool));
            if ($calls !== []) $filtered[] = $calls;
        }
        $plan['batches'] = $filtered;
        return $plan;
    }

    private function countTools(array $plan): int {
        $count = 0;
        foreach (($plan['batches'] ?? []) as $batch) {
            $count += count($batch);
        }
        return $count;
    }

    private function executionState(array $intent, array $memoryContext, int $step, array $previousScore): array {
        $primary = (string)($intent['primary_intent'] ?? 'unknown');
        $entities = is_array($intent['entities'] ?? null) ? $intent['entities'] : [];
        $knownFacts = [];
        foreach (['product_id', 'product_type', 'height', 'weight', 'size', 'min_price', 'max_price', 'color', 'in_stock', 'order_id'] as $field) {
            if (array_key_exists($field, $entities) && $entities[$field] !== null && $entities[$field] !== '') {
                $knownFacts[] = $field . '=' . $this->stringValue($entities[$field]);
            }
        }
        $missing = $previousScore['missing_evidence'] ?? $this->initialMissingEvidence($primary, $intent);
        return [
            'step' => $step,
            'state' => $this->stateName($primary, $missing),
            'goal' => $this->goal($primary),
            'known_facts' => $knownFacts,
            'missing_evidence' => array_values(array_unique(array_map('strval', is_array($missing) ? $missing : []))),
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

    private function stateName(string $primary, array $missing): string {
        if (in_array($primary, ['unsupported_outfit', 'unsupported_checkout'], true)) return 'guardrail';
        if ($missing === []) return 'ready_to_answer';
        return 'need_' . implode('_and_', array_slice(array_map(
            fn($item) => preg_replace('/[^a-z0-9_]+/i', '_', (string)$item) ?: 'evidence',
            $missing
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
        if (is_array($value)) return implode(',', array_map('strval', $value));
        if (is_bool($value)) return $value ? 'true' : 'false';
        return (string)$value;
    }

    private function traceStep(int $step, array $state, array $plan, array $observation, array $score, array $fingerprints, array $decision, bool $noProgress, array $validationErrors): array {
        return [
            'step' => $step,
            'execution_state' => (string)($state['state'] ?? ''),
            'evidence_goal' => (string)($state['goal'] ?? ''),
            'known_facts' => $state['known_facts'] ?? [],
            'missing_evidence' => $score['missing_evidence'] ?? ($state['missing_evidence'] ?? []),
            'selected_tools' => $this->selectedTools($plan),
            'observation_status' => $this->observationStatus($observation),
            'evidence_score' => $score,
            'decision' => $decision['action'] ?? 'fallback',
            'decision_reason' => $decision['reason'] ?? '',
            'no_progress' => $noProgress,
            'tool_fingerprints' => $fingerprints,
            'validation_errors' => $validationErrors,
        ];
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

    private function observationStatus(array $observation): string {
        if (!empty($observation['hard_failures'])) return 'hard_failure';
        if (!empty($observation['temporary_errors'])) return 'tool_error';
        if (!empty($observation['tool_statuses'])) return 'observed';
        return 'no_tool_observation';
    }
}
