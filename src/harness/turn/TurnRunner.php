<?php

/**
 * Runs one chat turn through the deterministic-first pipeline: PHP resolves
 * intent and selects tools; an optional LLM only enriches entities and, on a
 * streamed turn, rewrites the grounded draft. Streamed or not differs only in
 * whether the TurnContext carries an emit sink.
 */
final class TurnRunner
{
    private array $knowledgeSources = [];
    private array $evaluationMetadata = [];
    private array $responseMetadata = [];

    public function __construct(
        private ChatbotToolGateway $tools,
        private ChatbotMemoryStore $memory,
        private ChatbotConversationStore $conversations,
        private ResponseGenerator $responses,
        private MiddlewareStack $middleware,
        private ?LLMProvider $llm,
        private ?StreamingLLMProvider $streamingLlm,
        private bool $templateResponse,
    ) {
    }

    public function run(TurnContext $turn): TurnResult
    {
        $this->knowledgeSources = [];
        $this->evaluationMetadata = [];
        $this->responseMetadata = [];

        $memoryStart = microtime(true);
        $turn = $this->loadMemory($turn);
        $memoryLoadMs = (int)((microtime(true) - $memoryStart) * 1000);

        $result = $this->beforeTurn($turn) ?? $this->resolveAndExecute($turn, $memoryLoadMs);
        $result = $this->answer($turn, $result);
        $result = $this->afterTurn($turn, $result);
        $this->persist($turn, $result);
        return TurnResult::fromArray($result);
    }

    /** The session memory for this turn, with the last shown product filled in from history. */
    private function loadMemory(TurnContext $turn): TurnContext
    {
        $memoryContext = $this->memory->rememberUserMessage($turn->message);
        if (!isset($memoryContext['slots']) || !is_array($memoryContext['slots'])) {
            $memoryContext['slots'] = [];
        }
        if (empty($memoryContext['slots']['last_product_id'])) {
            $lastProductId = $this->conversations->findLastProductId();
            if ($lastProductId !== null) {
                $memoryContext['slots']['last_product_id'] = $lastProductId;
            }
        }
        return new TurnContext($turn->message, $turn->sessionId, $turn->userId, $memoryContext, $turn->emit);
    }

    /**
     * The first beforeTurn blocker's result, or null when nothing blocks. A
     * blocked turn skips intent, tools and evidence but still leaves its
     * routing record, in the log and in latency.routing.
     */
    private function beforeTurn(TurnContext $turn): ?array
    {
        $blocked = $this->middleware->beforeTurn($turn);
        if ($blocked === null) {
            return null;
        }
        $result = $blocked->toArray();
        $routing = ['blocked_by' => (string)($result['latency']['blocked_by'] ?? ''), 'selected_tools' => []];
        $this->responseMetadata = [
            'latency' => $result['latency'] ?? [],
            'primary_intent' => $result['primary_intent'] ?? 'unknown',
            'requested_fields' => $result['requested_fields'] ?? [],
            'routing' => $routing,
        ];
        $this->conversations->logToolExecution('routing_pipeline', [], $routing, 0, true);
        $result['latency']['routing'] = $routing;
        return $result;
    }

    private function resolveAndExecute(TurnContext $turn, int $memoryLoadMs): array
    {
        $message = $turn->message;
        $memoryContext = $turn->memoryContext;
        $totalStart = microtime(true);
        // OTLP trace IDs are exactly 16 bytes (32 lowercase hex chars).
        $traceId = bin2hex(random_bytes(16));
        $spans = [
            'trace_id' => $traceId,
            'pipeline' => 'deterministic_hybrid_pipeline',
            'memory_load_ms' => $memoryLoadMs,
        ];

        $start = microtime(true);
        $capabilities = CapabilityRegistry::fromToolDefinitions($this->tools->getDefinitions());
        $spans['capability_definition_ms'] = (int)((microtime(true) - $start) * 1000);

        $resolution = (new IntentResolver($this->llm))->resolve($message, $memoryContext, $capabilities);
        foreach (($resolution['timings'] ?? []) as $key => $value) {
            $spans[$key] = (int)$value;
        }
        $partial = $resolution['partial'];
        $conflictResolution = $resolution['conflict_resolution'];
        $enrichment = $resolution['enrichment'];
        $intent = $resolution['intent'];
        $spans['turn_tier'] = TurnTierGate::tier($partial);
        $spans['llm_enrichment_used'] = (bool)($enrichment['used'] ?? false);

        if (!empty($conflictResolution['unresolved_conflicts'])) {
            return $this->clarificationResponse(
                (string)$conflictResolution['clarification_message'],
                $traceId,
                $partial,
                $conflictResolution,
                $spans,
                'clarification',
                $totalStart
            );
        }

        if (($intent['primary_intent'] ?? 'unknown') === 'unknown' || (float)($intent['confidence'] ?? 0) < 0.6) {
            return $this->clarificationResponse(
                'Với câu hỏi ' . $this->echoQuote($message) . 'mình chưa đủ thông tin để chọn đúng cách hỗ trợ. Bạn nói rõ hơn là muốn tìm sản phẩm, xem chi tiết, hỏi size, chính sách hay đơn hàng nhé.',
                $traceId,
                $partial,
                $conflictResolution,
                $spans,
                'fallback',
                $totalStart,
                $intent
            );
        }

        $start = microtime(true);
        $executionLoop = new EvidenceExecutionLoop($this->tools, $capabilities);
        $executionResult = $executionLoop->run($message, $intent, $memoryContext);
        $spans['evidence_execution_ms'] = (int)((microtime(true) - $start) * 1000);
        foreach (($executionResult['spans'] ?? []) as $key => $value) {
            $spans[$key] = is_numeric($value) ? (($spans[$key] ?? 0) + (int)$value) : $value;
        }
        foreach (($executionResult['executions'] ?? []) as $execution) {
            if (is_array($execution)) $this->recordToolExecutions($execution);
        }

        $plan = is_array($executionResult['plan'] ?? null)
            ? $executionResult['plan']
            : ['batches' => [], 'response_type' => 'final_answer'];
        $normalized = is_array($executionResult['normalized'] ?? null)
            ? $executionResult['normalized']
            : ['cards' => [], 'knowledge_sources' => [], 'evidence' => []];
        $this->knowledgeSources = $normalized['knowledge_sources'] ?? [];

        $start = microtime(true);
        $response = $this->responses->generate($message, $intent, $normalized, $plan);
        $decision = is_array($executionResult['decision'] ?? null)
            ? $executionResult['decision']
            : ['action' => 'fallback', 'reason' => 'missing_decision'];
        if (($decision['action'] ?? '') === 'fallback') {
            $response['response_type'] = 'fallback';
        }
        $spans['generation_ms'] = (int)((microtime(true) - $start) * 1000);

        $spans['total_ms'] = (int)((microtime(true) - $totalStart) * 1000);
        $spans['loop_count'] = (int)($executionResult['loop_count'] ?? 0);
        $response['trace_id'] = $traceId;
        $response['latency'] = $spans;
        $response['knowledge_sources'] = $this->knowledgeSources;
        $response['products'] = $response['products'] ?? ($response['cards'] ?? []);
        $response['cards'] = $response['cards'] ?? $response['products'];

        $routing = $this->routingLog($partial, $enrichment, $intent, $plan, []);
        $routing['execution_trace'] = $executionResult['trace'] ?? [];
        $routing['evidence_score'] = $executionResult['evidence_score'] ?? [];
        $routing['decision'] = $decision;
        $routing['loop_count'] = (int)($executionResult['loop_count'] ?? 0);
        $response['latency']['routing'] = $routing;

        $this->evaluationMetadata[] = [
            'trace_id' => $traceId,
            'mode' => 'grounded_pipeline',
            'passed' => true,
            'issues' => [],
            'evidence_score' => $executionResult['evidence_score'] ?? [],
            'decision' => $decision,
            'async_evaluation' => 'queued_for_offline_ragas_langfuse',
        ];
        $this->responseMetadata = [
            'latency' => $spans,
            'primary_intent' => $intent['primary_intent'],
            'requested_fields' => $intent['requested_fields'] ?? [],
            'routing' => $routing,
        ];
        $this->conversations->logToolExecution('async_evaluation_outbox', [], [
            'trace_id' => $traceId,
            'primary_intent' => $intent['primary_intent'],
            'grounding_passed' => true,
            'grounding_issues' => [],
            'evidence_score' => $executionResult['evidence_score'] ?? [],
            'decision' => $decision,
        ], 0, true);
        $this->conversations->logToolExecution('routing_pipeline', [], $routing, 0, true);

        return $response;
    }

    private function clarificationResponse(
        string $message,
        string $traceId,
        array $partial,
        array $conflictResolution,
        array $spans,
        string $responseType,
        float $totalStart,
        array $intent = []
    ): array {
        $routing = $this->routingLog(
            $partial,
            ['used' => false, 'inferred_fields' => [], 'unresolved_remaining' => [], 'error' => null],
            $intent,
            ['batches' => [], 'selected_capabilities' => []],
            []
        );
        if (!empty($conflictResolution['unresolved_conflicts'])) {
            $routing['conflicts'] = $conflictResolution['unresolved_conflicts'];
        }
        $spans['generation_ms'] = 0;
        $spans['tool_execution_ms'] = 0;
        $spans['total_ms'] = (int)((microtime(true) - $totalStart) * 1000);
        $spans['routing'] = $routing;

        $response = [
            'message' => $message,
            'answer' => $message,
            'products' => [],
            'cards' => [],
            'knowledge_sources' => [],
            'response_type' => $responseType,
            'primary_intent' => (string)($intent['primary_intent'] ?? ($partial['resolved_fields']['intent']['value'] ?? 'unknown')),
            'secondary_intents' => $intent['secondary_intents'] ?? [],
            'requested_fields' => $intent['requested_fields'] ?? [],
            'missing_slots' => $intent['missing_slots'] ?? ($partial['missing_fields'] ?? []),
            'trace_id' => $traceId,
            'latency' => $spans,
        ];
        $this->responseMetadata = [
            'latency' => $spans,
            'primary_intent' => $response['primary_intent'],
            'requested_fields' => $response['requested_fields'],
            'routing' => $routing,
        ];
        $this->conversations->logToolExecution('routing_pipeline', [], $routing, 0, true);
        return $response;
    }

    /**
     * Non-streamed turns keep the grounded draft. Streamed turns emit it as a
     * single delta in template mode (CHATBOT_TEMPLATE_RESPONSE=1, takes
     * precedence), else stream an LLM rewrite of it.
     */
    private function answer(TurnContext $turn, array $result): array
    {
        if ($turn->emit === null) {
            return $result;
        }
        if ($this->templateResponse) {
            $turn->emit((string)$result['message']);
            $result['latency']['llm_stream_ms'] = 0;
            $result['latency']['streaming'] = 'template';
            return $result;
        }
        if ($this->streamingLlm === null) {
            throw new RuntimeException('Configured LLM provider does not support native token streaming');
        }
        $streamStart = microtime(true);
        $generated = (new StreamingResponseGenerator())->stream($this->streamingLlm, $turn->message, $result, $turn->emit);
        $result['message'] = $generated['text'];
        $result['answer'] = $generated['text'];
        $result['latency']['llm_stream_ms'] = (int)((microtime(true) - $streamStart) * 1000);
        $result['latency']['streaming'] = $generated['fallback'] === null ? true : 'template_fallback';
        if ($generated['fallback'] !== null) {
            error_log(json_encode(['operation' => 'chatbot_stream_llm', 'success' => false, 'error_category' => $generated['fallback']]));
        }
        return $result;
    }

    /** afterTurn (e.g. the free-shipping nudge) runs after the whole answer, so its deltas follow it. */
    private function afterTurn(TurnContext $turn, array $result): array
    {
        return $this->middleware->afterTurn($turn, TurnResult::fromArray($result))->toArray();
    }

    private function persist(TurnContext $turn, array $result): void
    {
        $this->conversations->saveMessages(
            $turn->message,
            (string)$result['message'],
            $result['products'] ?? [],
            $this->knowledgeSources,
            $this->evaluationMetadata,
            $this->responseMetadata
        );
        $this->memory->refreshSummary();
    }

    private function echoQuote(string $message): string
    {
        $text = (string)preg_replace('/\s+/u', ' ', trim($message));
        if (mb_strlen($text) > 120) {
            $text = mb_substr($text, 0, 117) . '…';
        }
        return $text === '' ? '' : '"' . $text . '", ';
    }

    private function routingLog(array $partial, array $enrichment, array $intent, array $plan, array $validationErrors): array
    {
        return [
            'resolved_fields' => $partial['resolved_fields'] ?? [],
            'unresolved_spans' => $partial['unresolved_spans'] ?? [],
            'conflicts' => $partial['conflicts'] ?? [],
            'llm_input_parser_used' => (bool)($enrichment['input_parser_used'] ?? false),
            'llm_input_parser_error' => $enrichment['input_parser_error'] ?? null,
            'llm_entity_enrichment_used' => (bool)($enrichment['used'] ?? false),
            'llm_inferred_fields' => $enrichment['inferred_fields'] ?? [],
            'llm_unresolved_remaining' => $enrichment['unresolved_remaining'] ?? [],
            'llm_entity_enrichment_error' => $enrichment['error'] ?? null,
            'locked_field_overwrite_attempts' => $intent['locked_field_overwrite_attempts'] ?? [],
            'merged_entities' => $intent['entities'] ?? [],
            'selected_capabilities' => $plan['selected_capabilities'] ?? [],
            'selected_tools' => $this->selectedTools($plan),
            'validation_errors' => $validationErrors,
            'tool_selection_mode' => 'deterministic_php',
            'execution_mode' => 'deterministic_php',
        ];
    }

    private function selectedTools(array $plan): array
    {
        $tools = [];
        foreach (($plan['batches'] ?? []) as $batch) {
            foreach ($batch as $call) {
                if (!empty($call['tool'])) $tools[] = (string)$call['tool'];
            }
        }
        return array_values(array_unique($tools));
    }

    private function recordToolExecutions(array $execution): void
    {
        foreach (($execution['results'] ?? []) as $entry) {
            $tool = (string)($entry['tool'] ?? '');
            if ($tool === '') continue;
            $this->conversations->logToolExecution(
                $tool,
                is_array($entry['args'] ?? null) ? $entry['args'] : [],
                is_array($entry['result'] ?? null) ? $entry['result'] : [],
                (int)($entry['duration_ms'] ?? 0),
                (bool)($entry['success'] ?? false)
            );
        }
    }
}
