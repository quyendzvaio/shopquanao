<?php

/**
 * Application service for the deterministic-first chatbot pipeline.
 * PHP resolves intent and selects tools; an optional LLM only enriches entities.
 */
require_once __DIR__ . '/../../cache/Cache.php';
require_once __DIR__ . '/ToolRegistry.php';
require_once __DIR__ . '/llm/LLMFactory.php';
require_once __DIR__ . '/ChatbotMemory.php';
require_once __DIR__ . '/PdoChatbotConversationStore.php';
require_once __DIR__ . '/contracts/ChatbotToolGateway.php';
require_once __DIR__ . '/contracts/ChatbotMemoryStore.php';
require_once __DIR__ . '/contracts/ChatbotConversationStore.php';
require_once __DIR__ . '/ProductAttributeNormalizer.php';
require_once __DIR__ . '/pipeline/CapabilityRegistry.php';
require_once __DIR__ . '/pipeline/IntentResolver.php';
require_once __DIR__ . '/pipeline/PlanValidator.php';
require_once __DIR__ . '/pipeline/ToolPlanner.php';
require_once __DIR__ . '/pipeline/ParallelToolExecutor.php';
require_once __DIR__ . '/pipeline/EvidenceNormalizer.php';
require_once __DIR__ . '/pipeline/ProductConstraintVerifier.php';
require_once __DIR__ . '/pipeline/ObservationEvaluator.php';
require_once __DIR__ . '/pipeline/LightweightEvidenceScorer.php';
require_once __DIR__ . '/pipeline/EvidenceDecisionRouter.php';
require_once __DIR__ . '/pipeline/NoProgressDetector.php';
require_once __DIR__ . '/pipeline/EvidenceExecutionLoop.php';
require_once __DIR__ . '/pipeline/ResponseGenerator.php';
require_once __DIR__ . '/pipeline/OnlineValidator.php';

class ChatbotService {
    private ?LLMProvider $llm;
    private ChatbotToolGateway $toolGateway;
    private ChatbotMemoryStore $memory;
    private ChatbotConversationStore $conversationStore;
    private ResponseGenerator $responseGenerator;
    private OnlineValidator $onlineValidator;
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
        ?ResponseGenerator $responseGenerator = null,
        ?OnlineValidator $onlineValidator = null
    ) {
        $this->llm = func_num_args() >= 4 ? $llm : LLMFactory::fromEnv();
        $this->toolGateway = $toolGateway ?? new ToolRegistry($pdo, $userId);
        $this->memory = $memory ?? new ChatbotMemory($pdo, $sessionId, $userId);
        $this->conversationStore = $conversationStore ?? new PdoChatbotConversationStore($pdo, $sessionId);
        $this->responseGenerator = $responseGenerator ?? new ResponseGenerator();
        $this->onlineValidator = $onlineValidator ?? new OnlineValidator();
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
        $this->conversationStore->saveMessages(
            $message,
            $result['message'],
            $result['products'] ?? [],
            $this->knowledgeSources,
            $this->evaluationMetadata,
            $this->responseMetadata
        );
        $this->memory->refreshSummary();
        return $result;
    }

    private function runPipeline(string $message, array $memoryContext, int $memoryLoadMs): array {
        $totalStart = microtime(true);
        $traceId = bin2hex(random_bytes(8));
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
                'Mình chưa đủ thông tin để chọn đúng cách hỗ trợ. Bạn nói rõ hơn là muốn tìm sản phẩm, xem chi tiết, hỏi size, chính sách hay đơn hàng nhé.',
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

        $start = microtime(true);
        $validation = $this->onlineValidator->validate($intent, $normalized, $response);
        $spans['validation_ms'] = (int)((microtime(true) - $start) * 1000);
        if (!$validation['passed']) {
            $response['answer'] = $validation['safe_fallback'];
            $response['message'] = $validation['safe_fallback'];
            $response['response_type'] = 'fallback';
        }

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
            'mode' => 'generic_online_validator',
            'passed' => (bool)$validation['passed'],
            'issues' => $validation['issues'],
            'evidence_score' => $executionResult['evidence_score'] ?? [],
            'decision' => $decision,
            'async_evaluation' => 'queued_for_offline_langsmith_ragas',
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
            'validation_passed' => (bool)$validation['passed'],
            'validation_issues' => $validation['issues'],
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

    private function routingLog(array $partial, array $enrichment, array $intent, array $plan, array $validationErrors): array {
        return [
            'resolved_fields' => $partial['resolved_fields'] ?? [],
            'unresolved_spans' => $partial['unresolved_spans'] ?? [],
            'conflicts' => $partial['conflicts'] ?? [],
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
