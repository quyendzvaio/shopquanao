<?php

if (PHP_SAPI !== 'cli') exit(2);
$root = dirname(__DIR__);
require_once $root . '/api/cache/Cache.php';
require_once $root . '/api/controllers/chatbot/llm/LLMFactory.php';
require_once $root . '/api/controllers/chatbot/ProductAttributeNormalizer.php';
foreach (['RawFashionSuggestion', 'ExtractedFashionItem', 'FashionAttributeExtractor', 'FashionExtractionCache', 'ApplicationFashionExtractionCache', 'FashionPipelineMetrics', 'StructuredLogFashionMetrics', 'FashionExtractionException', 'DeterministicFashionAttributeParser', 'FashionExtractionSemanticValidator', 'LlmFashionAttributeExtractor'] as $class) {
    require_once $root . '/api/services/Fashion/' . $class . '.php';
}

final class EvaluationFashionCache implements FashionExtractionCache
{
    private array $values = [];
    public function get(string $key): ?array { return $this->values[$key] ?? null; }
    public function set(string $key, array $items): void { $this->values[$key] = $items; }
}

final class EvaluationFashionMetrics implements FashionPipelineMetrics
{
    public array $counts = [];
    public function increment(string $metric): void { $this->counts[$metric] = ($this->counts[$metric] ?? 0) + 1; }
}

final class EvaluationLlmProvider implements LLMProvider
{
    public int $attempted = 0;
    public int $successful = 0;
    public int $rateLimited = 0;
    public int $timeouts = 0;
    public int $unavailable = 0;
    public ?int $lastRetryAfter = null;
    public array $failures = [];

    public function __construct(private LLMProvider $delegate) {}

    public function chat(array $messages, array $tools = [], string $toolChoice = 'auto', array $options = []): LLMResponse
    {
        $this->attempted++;
        try {
            $response = $this->delegate->chat($messages, $tools, $toolChoice, $options);
            $this->successful++;
            return $response;
        } catch (Throwable $error) {
            $category = $error instanceof LLMTransportException ? $error->category : $this->classify($error->getMessage());
            if ($category === 'rate_limit') {
                $this->rateLimited++;
                $this->lastRetryAfter = $error instanceof LLMTransportException ? $error->retryAfterSeconds : null;
            } elseif ($category === 'timeout') {
                $this->timeouts++;
            } else {
                $this->unavailable++;
            }
            $this->failures[] = ['request' => $this->attempted, 'category' => $category, 'message' => $error->getMessage()];
            throw $error;
        }
    }

    private function classify(string $message): string
    {
        $message = strtolower($message);
        if (str_contains($message, '429') || str_contains($message, 'rate limit')) return 'rate_limit';
        if (str_contains($message, 'timeout') || str_contains($message, 'timed out')) return 'timeout';
        return 'provider_unavailable';
    }
}

$baseLlm = LLMFactory::fashionExtractionFromEnv();
if ($baseLlm === null) {
    fwrite(STDERR, "FASHION_EXTRACTION_EVAL=BLOCKED missing LLM configuration\n");
    exit(2);
}
$llm = new EvaluationLlmProvider($baseLlm);
$metrics = new EvaluationFashionMetrics();
$cases = require $root . '/tests/fixtures/findmine/fashion-extraction-cases.php';
$started = microtime(true);
$extractor = new LlmFashionAttributeExtractor($llm, new EvaluationFashionCache(), $metrics);

$fields = ['category', 'subcategory', 'color', 'material', 'style', 'pattern', 'fit'];
$correct = array_fill_keys($fields, 0);
$hallucinated = 0;
$failures = [];
$schemaValid = 0;
$schemaInvalid = 0;
$infrastructureFailedCases = 0;
$semanticExact = 0;
$actualByIndex = [];
$batchSize = max(1, min(4, (int) (getenv('FASHION_EXTRACTION_EVAL_BATCH_SIZE') ?: 4)));
foreach (array_chunk($cases, $batchSize, true) as $chunkNumber => $chunk) {
    try {
        $items = $extractor->extract(array_map(static fn (array $case): RawFashionSuggestion => new RawFashionSuggestion($case['text']), $chunk));
        if (count($items) !== count($chunk)) throw new RuntimeException('item_count_mismatch');
        foreach (array_values(array_keys($chunk)) as $offset => $caseIndex) {
            $actualByIndex[$caseIndex] = $items[$offset]->toArray();
            $schemaValid++;
        }
    } catch (Throwable $error) {
        $category = $error instanceof FashionExtractionException ? $error->category : 'runtime_failure';
        $infrastructure = in_array($category, ['rate_limit', 'timeout', 'provider_unavailable'], true);
        foreach ($chunk as $caseIndex => $case) {
            if ($infrastructure) $infrastructureFailedCases++; else $schemaInvalid++;
            $failures[] = ['case_id' => $caseIndex + 1, 'raw_suggestion' => $case['text'], 'expected' => $case['expected'], 'error_category' => $category, 'failure_class' => $infrastructure ? 'INFRASTRUCTURE_FAILURE' : 'EXTRACTION_MODEL_FAILURE', 'validation_error' => $error->getMessage()];
        }
        if ($category === 'rate_limit') {
            $seconds = max(1, min(30, $llm->lastRetryAfter ?? (2 ** min(3, $chunkNumber))));
            usleep(($seconds * 1000000) + random_int(0, 250000));
        }
    }
}
foreach ($cases as $index => $case) {
    if (!isset($actualByIndex[$index])) continue;
    $actual = $actualByIndex[$index];
    foreach ($fields as $field) {
        $expectedValue = $case['expected'][$field];
        $actualValue = $actual[$field];
        if ($expectedValue === $actualValue) {
            $correct[$field]++;
        } elseif ($expectedValue === null && $actualValue !== null) {
            $hallucinated++;
        }
    }
    if ($actual !== $case['expected']) {
        $failures[] = ['case_id' => $index + 1, 'raw_suggestion' => $case['text'], 'expected' => $case['expected'], 'actual' => $actual, 'failure_class' => 'EXTRACTION_MODEL_FAILURE', 'error_category' => 'SEMANTIC_MISMATCH'];
    } else $semanticExact++;
}
$accuracy = [];
foreach ($fields as $field) $accuracy[$field] = round(100 * $correct[$field] / max(1, $schemaValid), 2);
$completedCases = $schemaValid + $schemaInvalid;
$schemaRate = round(100 * $schemaValid / max(1, $completedCases), 2);
$overallRate = round(100 * $schemaValid / max(1, count($cases)), 2);
$requestRate = round(100 * $llm->successful / max(1, $llm->attempted), 2);
$semanticRate = round(100 * $semanticExact / max(1, $schemaValid), 2);
$report = [
    'status' => $schemaValid === count($cases) && $schemaRate === 100.0 && $semanticExact === count($cases) && $hallucinated === 0 ? 'PASS' : 'FAIL',
    'cases' => count($cases),
    'batch_size' => $batchSize,
    'attempted_requests' => $llm->attempted,
    'successful_requests' => $llm->successful,
    'rate_limited_requests' => $llm->rateLimited,
    'timeouts' => $llm->timeouts,
    'provider_unavailable_requests' => $llm->unavailable,
    'request_success_rate' => $requestRate,
    'schema_valid_outputs' => $schemaValid,
    'schema_invalid_outputs' => $schemaInvalid,
    'infrastructure_failed_cases' => $infrastructureFailedCases,
    'schema_valid_rate_on_completed_requests' => $schemaRate,
    'overall_extraction_success_rate' => $overallRate,
    'semantic_accuracy_on_valid_outputs' => $semanticRate,
    'repair_attempts' => $metrics->counts['fashion_extraction_repair_attempts_total'] ?? 0,
    'repair_successes' => $metrics->counts['fashion_extraction_repair_success_total'] ?? 0,
    'fast_path_cases' => $metrics->counts['fashion_extraction_fast_path_total'] ?? 0,
    'llm_cases' => $metrics->counts['fashion_extraction_llm_path_total'] ?? 0,
    'accuracy_percent' => $accuracy,
    'hallucinated_attribute_count' => $hallucinated,
    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
    'request_failures' => $llm->failures,
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($report['status'] === 'PASS' ? 0 : 1);
