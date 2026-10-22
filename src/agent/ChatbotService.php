<?php

/**
 * Application service for the deterministic-first chatbot pipeline.
 * PHP resolves intent and selects tools; an optional LLM only enriches entities.
 */

class ChatbotService {
    private HarnessConfig $config;
    private PDO $pdo;
    private int $sessionId;
    private ?int $userId;
    private ?LLMProvider $llm;
    private ?StreamingLLMProvider $streamingLlm;
    private ChatbotToolGateway $toolGateway;
    private ChatbotMemoryStore $memory;
    private ChatbotConversationStore $conversationStore;
    private ResponseGenerator $responseGenerator;
    private array $knowledgeSources = [];
    private array $evaluationMetadata = [];
    private array $responseMetadata = [];

    public function __construct(
        PDO $pdo,
        int $sessionId,
        ?int $userId,
        ?LLMProvider $llm = null,
        ?ChatbotToolGateway $toolGateway = null,
        ?ChatbotMemoryStore $memory = null,
        ?ChatbotConversationStore $conversationStore = null,
        ?ResponseGenerator $responseGenerator = null
    ) {
        $this->pdo = $pdo;
        $this->sessionId = $sessionId;
        $this->userId = $userId;
        $this->config = HarnessConfig::fromEnvironment();
        $factory = new AgentFactory($this->config);
        // An explicitly passed $llm (even null) disables the environment LLMs.
        $llmWasInjected = func_num_args() >= 4;
        $this->llm = $llmWasInjected ? $llm : $factory->llm();
        $this->streamingLlm = $llmWasInjected
            ? ($llm instanceof StreamingLLMProvider ? $llm : null)
            : $factory->streamingLlm();
        $this->toolGateway = $toolGateway ?? $factory->toolGateway($pdo, $userId);
        $this->memory = $memory ?? new ChatbotMemory($pdo, $sessionId, $userId);
        $this->conversationStore = $conversationStore ?? new PdoChatbotConversationStore($pdo, $sessionId);
        $this->responseGenerator = $responseGenerator ?? $factory->responseGenerator();
        $this->memory->ensureSchema();
    }

    public function respond(string $message): array {
        $this->knowledgeSources = [];
        $this->evaluationMetadata = [];
        $this->responseMetadata = [];

        $memoryStart = microtime(true);
        $memoryContext = $this->memory->rememberUserMessage($message);
        $memoryContext = $this->enrichMemoryContextWithLastProduct($memoryContext);
        $memoryLoadMs = (int)((microtime(true) - $memoryStart) * 1000);

        $result = $this->runPipeline($message, $memoryContext, $memoryLoadMs);
        $nudge = $this->freeShippingNudge($result);
        $result = $this->withNudgeCard($result, $nudge);
        if ($nudge !== null) {
            $result['message'] = rtrim((string)$result['message']) . ' ' . $nudge['text'];
            $result['answer'] = $result['message'];
        }
        $this->conversationStore->saveMessages(
            $message,
            $result['message'],
            $result['products'] ?? [],
            $this->knowledgeSources,
            $this->evaluationMetadata,
            $this->responseMetadata
        );
        $this->enqueueLangfuseTrace($message, $result);
        $this->memory->refreshSummary();
        return $result;
    }

    /**
     * Run the grounded pipeline and stream the final answer directly from the
     * configured LLM. No response validator gate is used on this path; product
     * grounding is enforced before generation and cards are persisted only
     * from the private Product Search result.
     *
     * @param callable(string):void $onDelta
     */
    public function respondStreaming(string $message, callable $onDelta): array
    {
        $this->knowledgeSources = [];
        $this->evaluationMetadata = [];
        $this->responseMetadata = [];

        $memoryStart = microtime(true);
        $memoryContext = $this->memory->rememberUserMessage($message);
        $memoryContext = $this->enrichMemoryContextWithLastProduct($memoryContext);
        $memoryLoadMs = (int)((microtime(true) - $memoryStart) * 1000);

        $result = $this->runPipeline($message, $memoryContext, $memoryLoadMs);
        $nudge = $this->freeShippingNudge($result);
        $result = $this->withNudgeCard($result, $nudge);

        // Template-only mode (CHATBOT_TEMPLATE_RESPONSE=1) takes precedence:
        // emit the grounded draft as a single delta instead of paying a
        // native token-stream round trip (L0 fast path).
        if ($this->config->templateResponse) {
            $onDelta((string)$result['message']);
            $streamed = (string)$result['message'];
            $result['latency']['llm_stream_ms'] = 0;
            $result['latency']['streaming'] = 'template';
        } elseif ($this->streamingLlm === null) {
            throw new RuntimeException('Configured LLM provider does not support native token streaming');
        } else {
            $streamStart = microtime(true);
            $generated = (new StreamingResponseGenerator())->stream($this->streamingLlm, $message, $result, $onDelta);
            $streamed = $generated['text'];
            $result['message'] = $streamed;
            $result['answer'] = $streamed;
            $result['latency']['llm_stream_ms'] = (int)((microtime(true) - $streamStart) * 1000);
            $result['latency']['streaming'] = $generated['fallback'] === null ? true : 'template_fallback';
            if ($generated['fallback'] !== null) {
                error_log(json_encode(['operation' => 'chatbot_stream_llm', 'success' => false, 'error_category' => $generated['fallback']]));
            }
        }

        // The nudge is its own delta after the answer so an LLM rewrite cannot drop it.
        if ($nudge !== null) {
            $onDelta(' ' . $nudge['text']);
            $streamed .= ' ' . $nudge['text'];
            $result['message'] = $streamed;
            $result['answer'] = $streamed;
        }

        $this->conversationStore->saveMessages(
            $message,
            $streamed,
            $result['products'] ?? [],
            $this->knowledgeSources,
            $this->evaluationMetadata,
            $this->responseMetadata
        );
        $this->enqueueLangfuseTrace($message, $result);
        $this->memory->refreshSummary();
        return $result;
    }

    /**
     * UC-C proactive nudge: computed after the grounded answer, at most once
     * per cart state. Failures never break the chat turn.
     *
     * @return array{text:string,card:array}|null
     */
    private function freeShippingNudge(array $result): ?array
    {
        if ($this->userId === null || !FreeShippingNudge::turnQualifies($result)) return null;
        try {
            $policy = $this->config->freeShipping;
            $ledger = new FreeShippingNudge($this->pdo, $this->sessionId);
            $advice = (new FreeShippingService($this->pdo, $policy))->suggest($this->userId, ['limit' => 1]);
            if (!FreeShippingNudge::eligible($result, $advice, $ledger->lastFingerprint(), $policy)) return null;
            $cards = (new EvidenceNormalizer())->productCards($advice['products']);
            if ($cards === []) return null;
            $ledger->remember((string)$advice['cart_fingerprint']);
            return ['text' => FreeShippingNudge::message($advice), 'card' => $cards[0]];
        } catch (Throwable) {
            error_log(json_encode(['operation' => 'freeship_nudge', 'success' => false, 'error_category' => 'suppressed_runtime_failure']));
            return null;
        }
    }

    private function withNudgeCard(array $result, ?array $nudge): array
    {
        $result['freeship_nudge'] = $nudge !== null;
        if ($nudge === null) return $result;
        $byId = [];
        foreach (($result['products'] ?? []) as $card) {
            if (is_array($card)) $byId[(int)($card['id'] ?? 0)] = $card;
        }
        $byId[(int)$nudge['card']['id']] ??= $nudge['card'];
        $result['products'] = array_values($byId);
        $result['cards'] = $result['products'];
        return $result;
    }

    private function runPipeline(string $message, array $memoryContext, int $memoryLoadMs): array {
        $totalStart = microtime(true);
        // OTLP trace IDs are exactly 16 bytes (32 lowercase hex chars).
        $traceId = bin2hex(random_bytes(16));
        $spans = [
            'trace_id' => $traceId,
            'pipeline' => 'deterministic_hybrid_pipeline',
            'memory_load_ms' => $memoryLoadMs,
        ];

        $start = microtime(true);
        $capabilities = CapabilityRegistry::fromToolDefinitions($this->toolGateway->getDefinitions());
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
        $executionLoop = new EvidenceExecutionLoop($this->toolGateway, $capabilities);
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
        $response = $this->responseGenerator->generate($message, $intent, $normalized, $plan);
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
        $this->conversationStore->logToolExecution('async_evaluation_outbox', [], [
            'trace_id' => $traceId,
            'primary_intent' => $intent['primary_intent'],
            'grounding_passed' => true,
            'grounding_issues' => [],
            'evidence_score' => $executionResult['evidence_score'] ?? [],
            'decision' => $decision,
        ], 0, true);
        $this->conversationStore->logToolExecution('routing_pipeline', [], $routing, 0, true);

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
        $this->conversationStore->logToolExecution('routing_pipeline', [], $routing, 0, true);
        return $response;
    }

    private function echoQuote(string $message): string {
        $text = (string)preg_replace('/\s+/u', ' ', trim($message));
        if (mb_strlen($text) > 120) {
            $text = mb_substr($text, 0, 117) . '…';
        }
        return $text === '' ? '' : '"' . $text . '", ';
    }

    private function routingLog(array $partial, array $enrichment, array $intent, array $plan, array $validationErrors): array {
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

    private function selectedTools(array $plan): array {
        $tools = [];
        foreach (($plan['batches'] ?? []) as $batch) {
            foreach ($batch as $call) {
                if (!empty($call['tool'])) $tools[] = (string)$call['tool'];
            }
        }
        return array_values(array_unique($tools));
    }

    private function recordToolExecutions(array $execution): void {
        foreach (($execution['results'] ?? []) as $entry) {
            $tool = (string)($entry['tool'] ?? '');
            if ($tool === '') continue;
            $this->conversationStore->logToolExecution(
                $tool,
                is_array($entry['args'] ?? null) ? $entry['args'] : [],
                is_array($entry['result'] ?? null) ? $entry['result'] : [],
                (int)($entry['duration_ms'] ?? 0),
                (bool)($entry['success'] ?? false)
            );
        }
    }

    /**
     * Trace export is intentionally decoupled from request processing. This
     * only writes a sanitized outbox row; the separate worker owns all HTTP
     * communication, retries, and failure handling with Langfuse.
     */
    private function enqueueLangfuseTrace(string $message, array $result): void
    {
        $traceId = (string) ($result['trace_id'] ?? '');
        (new LangfuseTraceOutbox($this->pdo))->enqueueSafely(
            $traceId,
            $message,
            $result,
            $this->userId,
            $this->sessionId
        );
    }

    private function enrichMemoryContextWithLastProduct(array $memoryContext): array {
        if (!isset($memoryContext['slots']) || !is_array($memoryContext['slots'])) {
            $memoryContext['slots'] = [];
        }
        if (!empty($memoryContext['slots']['last_product_id'])) return $memoryContext;

        $lastProductId = $this->conversationStore->findLastProductId();
        if ($lastProductId !== null) {
            $memoryContext['slots']['last_product_id'] = $lastProductId;
        }
        return $memoryContext;
    }
}
