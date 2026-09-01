<?php

require_once __DIR__ . '/PartialParseResult.php';
require_once __DIR__ . '/CapabilityRegistry.php';
require_once __DIR__ . '/DeterministicIntentParser.php';
require_once __DIR__ . '/LlmInputParser.php';
require_once __DIR__ . '/ConflictDetector.php';
require_once __DIR__ . '/ConflictResolver.php';
require_once __DIR__ . '/SemanticEntityEnricher.php';
require_once __DIR__ . '/MergeEngine.php';

/**
 * Resolves a query into a structured intent. All decisions remain deterministic;
 * the optional LLM may only enrich unresolved descriptive entity fields.
 */
class IntentResolver {
    private SemanticEntityEnricher $enricher;
    private LlmInputParser $inputParser;

    public function __construct(?LLMProvider $llm = null) {
        $this->enricher = new SemanticEntityEnricher($llm);
        $this->inputParser = new LlmInputParser($llm);
    }

    public function resolve(string $message, array $memoryContext = [], array $capabilities = []): array {
        $timings = [];

        $start = microtime(true);
        $deterministic = (new DeterministicIntentParser())->parse($message, $memoryContext)->toArray();
        $timings['deterministic_parse_ms'] = (int)((microtime(true) - $start) * 1000);

        $inputParse = ['partial' => null, 'used' => false, 'error' => 'deterministic_resolution_sufficient'];
        $safetyIntent = (string)($deterministic['resolved_fields']['intent']['value'] ?? '');
        if ($this->needsInputParser($deterministic) && !in_array($safetyIntent, ['unsupported_checkout'], true)) {
            $start = microtime(true);
            $inputParse = $this->inputParser->parse($message, $memoryContext);
            $timings['llm_input_parse_ms'] = (int)((microtime(true) - $start) * 1000);
        }
        $partial = is_array($inputParse['partial'] ?? null)
            ? $this->applyDeterministicSafety($inputParse['partial'], $deterministic)
            : $deterministic;

        $start = microtime(true);
        $partial['conflicts'] = (new ConflictDetector())->detect($partial);
        $conflictResolution = (new ConflictResolver())->resolve($partial);
        $timings['conflict_detection_ms'] = (int)((microtime(true) - $start) * 1000);

        $enrichment = $this->emptyEnrichment('unresolved_conflict');
        $intent = [];
        if (empty($conflictResolution['unresolved_conflicts'])) {
            if (is_array($inputParse['partial'] ?? null)) {
                $enrichment = $this->emptyEnrichment('llm_input_parser_used');
                $enrichment['input_parser_used'] = true;
                $enrichment['input_parser_error'] = null;
            } else {
                $start = microtime(true);
                $enrichment = $this->enricher->enrich(
                    $partial,
                    CapabilityRegistry::relevantForPartial($partial, $capabilities)
                );
                $timings['entity_enrichment_ms'] = (int)((microtime(true) - $start) * 1000);
                $enrichment['input_parser_used'] = (bool)($inputParse['used'] ?? false);
                $enrichment['input_parser_error'] = $inputParse['error'] ?? null;
            }

            $start = microtime(true);
            $intent = (new MergeEngine())->merge($partial, $enrichment, $memoryContext, $conflictResolution);
            $timings['merge_ms'] = (int)((microtime(true) - $start) * 1000);
        }

        return [
            'partial' => $partial,
            'conflict_resolution' => $conflictResolution,
            'enrichment' => $enrichment,
            'intent' => $intent,
            'timings' => $timings,
        ];
    }

    /**
     * The full LLM parser is a fallback for genuinely unresolved input. A
     * confident, specific deterministic parse is already sufficient for tool
     * selection and should not pay a second network round trip before the
     * final answer stream can start.
     */
    private function needsInputParser(array $deterministic): bool
    {
        $intent = $deterministic['resolved_fields']['intent'] ?? [];
        $name = (string)($intent['value'] ?? 'unknown');
        $confidence = (float)($intent['confidence'] ?? 0.0);
        if ($name === 'unknown' || $confidence < 0.6) return true;
        if ($name !== 'product_search') return false;

        $productType = trim((string)($deterministic['resolved_fields']['product_type']['value'] ?? ''));
        $coverage = (float)($deterministic['parser_metadata']['coverage'] ?? 0.0);
        $genericTypes = ['áo', 'quần', 'váy', 'phụ kiện', 'giày'];
        return $productType === '' || (in_array($productType, $genericTypes, true) && $coverage < 0.45);
    }

    public function extract(string $message, array $memoryContext = []): array {
        return $this->resolve($message, $memoryContext)['intent'];
    }

    private function emptyEnrichment(string $reason): array {
        return [
            'used' => false,
            'inferred_fields' => [],
            'unresolved_remaining' => [],
            'raw_response' => '',
            'prompt' => '',
            'error' => $reason,
        ];
    }

    /**
     * Keep deterministic values only where the raw text is an objective source
     * of truth. This protects IDs, price limits, measurements and conflicts
     * while allowing the LLM to retain a more specific product phrase.
     */
    private function applyDeterministicSafety(array $llmPartial, array $deterministic): array
    {
        $fields = is_array($llmPartial['resolved_fields'] ?? null) ? $llmPartial['resolved_fields'] : [];
        $ruleFields = is_array($deterministic['resolved_fields'] ?? null) ? $deterministic['resolved_fields'] : [];
        foreach (['product_id', 'order_id', 'min_price', 'max_price', 'height_cm', 'weight_kg', 'size', 'color', 'in_stock'] as $field) {
            if (isset($ruleFields[$field])) {
                $fields[$field] = $ruleFields[$field];
            }
        }
        $llmPartial['resolved_fields'] = $fields;

        $candidates = $deterministic['parser_metadata']['field_candidates'] ?? [];
        if (is_array($candidates) && $candidates !== []) {
            $llmPartial['parser_metadata']['field_candidates'] = $candidates;
        }
        return $llmPartial;
    }
}
