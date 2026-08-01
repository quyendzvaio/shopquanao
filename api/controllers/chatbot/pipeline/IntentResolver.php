<?php

require_once __DIR__ . '/PartialParseResult.php';
require_once __DIR__ . '/CapabilityRegistry.php';
require_once __DIR__ . '/DeterministicIntentParser.php';
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

    public function __construct(?LLMProvider $llm = null) {
        $this->enricher = new SemanticEntityEnricher($llm);
    }

    public function resolve(string $message, array $memoryContext = [], array $capabilities = []): array {
        $timings = [];

        $start = microtime(true);
        $partial = (new DeterministicIntentParser())->parse($message, $memoryContext)->toArray();
        $timings['deterministic_parse_ms'] = (int)((microtime(true) - $start) * 1000);

        $start = microtime(true);
        $partial['conflicts'] = (new ConflictDetector())->detect($partial);
        $conflictResolution = (new ConflictResolver())->resolve($partial);
        $timings['conflict_detection_ms'] = (int)((microtime(true) - $start) * 1000);

        $enrichment = $this->emptyEnrichment('unresolved_conflict');
        $intent = [];
        if (empty($conflictResolution['unresolved_conflicts'])) {
            $start = microtime(true);
            $enrichment = $this->enricher->enrich(
                $partial,
                CapabilityRegistry::relevantForPartial($partial, $capabilities)
            );
            $timings['entity_enrichment_ms'] = (int)((microtime(true) - $start) * 1000);

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
}
