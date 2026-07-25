<?php

class ReasoningLoop {
    public const MAX_REASONING_LOOPS = 3;
    public const MAX_TOOL_CALLS_TOTAL = 4;
    public const MAX_QUERY_REWRITES = 1;
    public const MAX_TOOL_RETRIES = 1;

    private ToolRegistry $toolRegistry;
    private array $capabilities;
    private PlanValidator $planValidator;
    private ToolPlanner $planner;
    private ParallelToolExecutor $executor;
    private EvidenceNormalizer $normalizer;
    private ProductConstraintVerifier $constraintVerifier;
    private ObservationEvaluator $observationEvaluator;
    private LightweightEvidenceScorer $scorer;
    private ReasoningDecisionRouter $router;
    private NoProgressDetector $noProgress;
    private ThoughtStateBuilder $thoughtBuilder;

    public function __construct(ToolRegistry $toolRegistry, array $capabilities) {
        $this->toolRegistry = $toolRegistry;
        $this->capabilities = $capabilities;
        $this->planValidator = new PlanValidator($capabilities);
        $this->planner = new ToolPlanner($capabilities);
        $this->executor = new ParallelToolExecutor($toolRegistry);
        $this->normalizer = new EvidenceNormalizer();
        $this->constraintVerifier = new ProductConstraintVerifier();
        $this->observationEvaluator = new ObservationEvaluator();
        $this->scorer = new LightweightEvidenceScorer();
        $this->router = new ReasoningDecisionRouter();
        $this->noProgress = new NoProgressDetector();
        $this->thoughtBuilder = new ThoughtStateBuilder();
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

        for ($step = 1; $step <= self::MAX_REASONING_LOOPS; $step++) {
            $thought = $this->thoughtBuilder->build($intent, $memoryContext, $step, $previousScore);
            $plan = $this->planner->plan($intent);
            $plan = $this->applyDecisionToPlan($plan, $intent, $finalDecision, $message, $rewrites);

            $validation = $this->planValidator->validate($plan, $intent);
            $plan = $validation['sanitized_plan'];
            $finalPlan = $plan;
            if (!$validation['passed']) {
                $trace[] = $this->traceStep($step, $thought, $plan, [], [], [], ['action' => 'fallback', 'reason' => 'plan_validation_failed'], false, $validation['errors']);
                $finalDecision = ['action' => 'fallback', 'reason' => 'plan_validation_failed'];
                break;
            }

            $plannedToolCount = $this->countTools($plan);
            if ($toolCalls + $plannedToolCount > self::MAX_TOOL_CALLS_TOTAL) {
                $finalDecision = ['action' => 'fallback', 'reason' => 'tool_budget_exhausted'];
                $trace[] = $this->traceStep($step, $thought, $plan, [], [], [], $finalDecision, false, []);
                break;
            }

            if ($plannedToolCount === 0) {
                $finalNormalized = ['cards' => [], 'knowledge_sources' => [], 'evidence' => [], 'tool_results' => []];
                $observation = ['tool_statuses' => [], 'temporary_errors' => [], 'hard_failures' => [], 'has_tool_error' => false, 'has_hard_failure' => false];
                $finalScore = $this->scorer->score($intent, $finalNormalized, $observation);
                $finalDecision = $this->router->decide($intent, $plan, $finalNormalized, $observation, $finalScore, [
                    'loop_count' => $step,
                    'tool_calls' => $toolCalls,
                    'query_rewrites' => $rewrites,
                    'tool_retries' => $retries,
                ], false);
                $trace[] = $this->traceStep($step, $thought, $plan, $observation, $finalScore, [], $finalDecision, false, []);
                break;
            }

            $execution = $this->executor->execute($plan);
            $executions[] = $execution;
            $toolCalls += $plannedToolCount;
            foreach (($execution['spans'] ?? []) as $key => $value) {
                $spans[$key] = is_numeric($value) ? (($spans[$key] ?? 0) + (int)$value) : $value;
            }

            $finalNormalized = $this->constraintVerifier->verify($intent, $this->normalizer->normalize($intent, $execution));
            $observation = $this->observationEvaluator->evaluate($intent, $plan, $execution, $finalNormalized);
            $progress = $this->noProgress->observe($execution);
            $finalScore = $this->scorer->score($intent, $finalNormalized, $observation);
            $finalDecision = $this->router->decide($intent, $plan, $finalNormalized, $observation, $finalScore, [
                'loop_count' => $step,
                'tool_calls' => $toolCalls,
                'query_rewrites' => $rewrites,
                'tool_retries' => $retries,
            ], (bool)$progress['no_progress']);

            $trace[] = $this->traceStep($step, $thought, $plan, $observation, $finalScore, $progress['fingerprints'], $finalDecision, (bool)$progress['no_progress'], []);

            if ($finalDecision['action'] === 'return' || in_array($finalDecision['action'], ['ask_user', 'deny', 'fallback'], true)) {
                break;
            }
            if ($finalDecision['action'] === 'rewrite_query') {
                $rewrites++;
            } elseif ($finalDecision['action'] === 'retry_tool') {
                $retries++;
            } elseif ($finalDecision['action'] === 'call_next_tool') {
                // Keep loop moving; applyDecisionToPlan will narrow the next plan.
            } else {
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

    private function traceStep(int $step, array $thought, array $plan, array $observation, array $score, array $fingerprints, array $decision, bool $noProgress, array $validationErrors): array {
        return [
            'step' => $step,
            'state' => (string)($thought['state'] ?? ''),
            'goal' => (string)($thought['goal'] ?? ''),
            'known_facts' => $thought['known_facts'] ?? [],
            'missing_evidence' => $score['missing_evidence'] ?? ($thought['missing_evidence'] ?? []),
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
