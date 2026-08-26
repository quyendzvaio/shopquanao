<?php

use PHPUnit\Framework\TestCase;

final class LlmFashionAttributeExtractorTest extends TestCase
{
    public function testRequiredStructuredToolOutputPreservesUnknownsAsNull(): void
    {
        $llm = new RecordingFashionExtractionLlm([
            new LLMResponse(toolCalls: [new ToolCall('extract-1', 'emit_fashion_attributes', [
                'items' => [[
                    'category' => 'trousers', 'subcategory' => null, 'color' => 'white',
                    'material' => null, 'style' => null, 'pattern' => null, 'fit' => null,
                ]],
            ])]),
        ]);
        $cache = new MemoryFashionExtractionCache();
        $metrics = new RecordingFashionMetrics();
        $extractor = new LlmFashionAttributeExtractor($llm, $cache, $metrics, maxAttempts: 1);

        $items = $extractor->extract([new RawFashionSuggestion('white trousers')]);

        self::assertCount(1, $items);
        self::assertSame('trousers', $items[0]->category);
        self::assertSame('white', $items[0]->color);
        self::assertNull($items[0]->material);
        self::assertSame('required', $llm->toolChoice);
        self::assertSame(0, $llm->options['temperature']);
        self::assertLessThanOrEqual(800, $llm->options['max_tokens']);
        self::assertSame('emit_fashion_attributes', $llm->tools[0]['function']['name']);
        self::assertFalse(str_contains(json_encode($llm->messages, JSON_THROW_ON_ERROR), 'product_id'));
        self::assertSame(1, $metrics->counts['fashion_extraction_calls_total'] ?? 0);
        self::assertSame(1, $metrics->counts['fashion_extraction_success_total'] ?? 0);

        $cached = $extractor->extract([new RawFashionSuggestion('white trousers')]);
        self::assertSame('trousers', $cached[0]->category);
        self::assertSame(1, $llm->calls, 'cache must avoid a repeated extraction call');
    }

    public function testMalformedSchemaGetsOneBoundedRetryThenFails(): void
    {
        $invalid = new LLMResponse(toolCalls: [new ToolCall('bad', 'emit_fashion_attributes', [
            'items' => [['category' => 'shoes', 'material' => 'invented', 'product_id' => 999]],
        ])]);
        $llm = new RecordingFashionExtractionLlm([$invalid, $invalid, $invalid]);
        $metrics = new RecordingFashionMetrics();
        $extractor = new LlmFashionAttributeExtractor(
            $llm,
            new MemoryFashionExtractionCache(),
            $metrics,
            maxAttempts: 2
        );

        try {
            $extractor->extract([new RawFashionSuggestion('stylish shoes')]);
            self::fail('Malformed extraction should fail');
        } catch (FashionExtractionException $error) {
            self::assertSame('invalid_schema', $error->category);
        }

        self::assertSame(2, $llm->calls);
        self::assertSame(2, $metrics->counts['fashion_extraction_invalid_schema_total'] ?? 0);
        self::assertSame(1, $metrics->counts['fashion_extraction_failure_total'] ?? 0);
    }

    public function testLiteralUnknownTokensAreCanonicalizedToNull(): void
    {
        $llm = new RecordingFashionExtractionLlm([
            new LLMResponse(toolCalls: [new ToolCall('extract-null-token', 'emit_fashion_attributes', [
                'items' => [[
                    'category' => 'shoes', 'subcategory' => 'null', 'color' => 'unknown',
                    'material' => 'Leather', 'style' => 'n/a', 'pattern' => 'none', 'fit' => null,
                ]],
            ])]),
        ]);
        $item = (new LlmFashionAttributeExtractor($llm, new MemoryFashionExtractionCache(), new RecordingFashionMetrics(), 1))
            ->extract([new RawFashionSuggestion('leather shoes')])[0];

        self::assertSame('footwear', $item->category);
        self::assertNull($item->subcategory);
        self::assertNull($item->color);
        self::assertSame('leather', $item->material);
        self::assertNull($item->style);
        self::assertNull($item->pattern);
    }

    public function testSemanticValidationCanonicalizesExplicitVietnameseAttributesAndDropsInference(): void
    {
        $llm = new RecordingFashionExtractionLlm([
            new LLMResponse(toolCalls: [new ToolCall('extract-vietnamese', 'emit_fashion_attributes', [
                'items' => [[
                    'category' => 'giày', 'subcategory' => 'giày lười', 'color' => 'nâu',
                    'material' => 'da', 'style' => 'lười', 'pattern' => null, 'fit' => null,
                ]],
            ])]),
        ]);

        $item = (new LlmFashionAttributeExtractor($llm, new MemoryFashionExtractionCache(), new RecordingFashionMetrics(), 1))
            ->extract([new RawFashionSuggestion('giày lười da nâu')])[0];

        self::assertSame([
            'category' => 'footwear', 'subcategory' => 'loafers', 'color' => 'brown',
            'material' => 'leather', 'style' => null, 'pattern' => null, 'fit' => null,
        ], $item->toArray());
    }

    public function testContradictorySemanticOutputGetsOneRepairWithExactContext(): void
    {
        $llm = new RecordingFashionExtractionLlm([
            new LLMResponse(toolCalls: [new ToolCall('bad-semantic', 'emit_fashion_attributes', [
                'items' => [[
                    'category' => 'shirt', 'subcategory' => 'sneakers', 'color' => null,
                    'material' => null, 'style' => null, 'pattern' => null, 'fit' => null,
                ]],
            ])]),
            new LLMResponse(toolCalls: [new ToolCall('repaired', 'emit_fashion_attributes', [
                'items' => [[
                    'category' => 'footwear', 'subcategory' => 'sneakers', 'color' => null,
                    'material' => null, 'style' => null, 'pattern' => null, 'fit' => null,
                ]],
            ])]),
        ]);
        $metrics = new RecordingFashionMetrics();
        $extractor = new LlmFashionAttributeExtractor($llm, new MemoryFashionExtractionCache(), $metrics, 2);

        $item = $extractor->extract([new RawFashionSuggestion('sneakers')])[0];

        self::assertSame('footwear', $item->category);
        self::assertSame(2, $llm->calls);
        self::assertStringContainsString('sneakers', json_encode($llm->messagesByCall[1], JSON_THROW_ON_ERROR));
        self::assertStringContainsString('Repair the output', json_encode($llm->messagesByCall[1], JSON_THROW_ON_ERROR));
        self::assertSame(1, $metrics->counts['fashion_extraction_repair_attempts_total'] ?? 0);
        self::assertSame(1, $metrics->counts['fashion_extraction_repair_success_total'] ?? 0);
    }

    public function testNonCanonicalCategoryValueTriggersRepairRetry(): void
    {
        // T6: enum guard — "bottomwear" is not in the canonical category enum
        // so the first call must fail with invalid_schema and trigger exactly
        // one bounded repair retry.
        $llm = new RecordingFashionExtractionLlm([
            new LLMResponse(toolCalls: [new ToolCall('bad-enum', 'emit_fashion_attributes', [
                'items' => [[
                    'category' => 'bottomwear', 'subcategory' => null, 'color' => 'white',
                    'material' => 'denim', 'style' => null, 'pattern' => null, 'fit' => null,
                ]],
            ])]),
            new LLMResponse(toolCalls: [new ToolCall('repaired-enum', 'emit_fashion_attributes', [
                'items' => [[
                    'category' => 'trousers', 'subcategory' => null, 'color' => 'white',
                    'material' => 'denim', 'style' => null, 'pattern' => null, 'fit' => null,
                ]],
            ])]),
        ]);
        $metrics = new RecordingFashionMetrics();
        $extractor = new LlmFashionAttributeExtractor($llm, new MemoryFashionExtractionCache(), $metrics, 2);

        $item = $extractor->extract([new RawFashionSuggestion('white denim trousers')])[0];

        self::assertSame('trousers', $item->category);
        self::assertSame('white', $item->color);
        self::assertSame(2, $llm->calls, 'Repair retry must be attempted for invalid enum');
        self::assertSame(1, $metrics->counts['fashion_extraction_repair_attempts_total'] ?? 0);
        self::assertSame(1, $metrics->counts['fashion_extraction_repair_success_total'] ?? 0);
    }

    public function testEnumConstrainedToolSchemaContainsCanonicalValues(): void
    {
        // T3: the emitted tool schema must constrain category and subcategory
        // to the canonical enum so the provider rejects non-standard strings.
        $llm = new RecordingFashionExtractionLlm([
            new LLMResponse(toolCalls: [new ToolCall('schema-check', 'emit_fashion_attributes', [
                'items' => [[
                    'category' => 'shirt', 'subcategory' => null, 'color' => null,
                    'material' => null, 'style' => null, 'pattern' => null, 'fit' => null,
                ]],
            ])]),
        ]);
        $extractor = new LlmFashionAttributeExtractor($llm, new MemoryFashionExtractionCache(), new RecordingFashionMetrics(), 1);
        $extractor->extract([new RawFashionSuggestion('a casual top')]);

        $schema = $llm->tools[0]['function']['parameters']['properties']['items']['items']['properties'];
        self::assertArrayHasKey('enum', $schema['category']);
        self::assertContains('shirt', $schema['category']['enum']);
        self::assertContains('trousers', $schema['category']['enum']);
        self::assertContains('footwear', $schema['category']['enum']);
        self::assertContains('jacket', $schema['category']['enum']);
        self::assertContains('dress', $schema['category']['enum']);
        self::assertContains('skirt', $schema['category']['enum']);
        self::assertContains('accessory', $schema['category']['enum']);
        self::assertContains(null, $schema['category']['enum']);
        self::assertArrayHasKey('enum', $schema['subcategory']);
        self::assertContains('sneakers', $schema['subcategory']['enum']);
        self::assertContains('blazer', $schema['subcategory']['enum']);
        self::assertArrayHasKey('enum', $schema['material']);
        self::assertContains('denim', $schema['material']['enum']);
        self::assertContains('linen', $schema['material']['enum']);
        self::assertArrayHasKey('enum', $schema['fit']);
        self::assertContains('wide_leg', $schema['fit']['enum']);
        self::assertContains('slim', $schema['fit']['enum']);
    }

    public function testRateLimitIsInfrastructureFailureAndIsNotImmediatelyRetried(): void
    {
        $llm = new ThrowingFashionExtractionLlm(new RuntimeException('LLM error (HTTP 429): rate limit exceeded'));
        $extractor = new LlmFashionAttributeExtractor($llm, new MemoryFashionExtractionCache(), new RecordingFashionMetrics(), 2);

        try {
            $extractor->extract([new RawFashionSuggestion('an unusual coordinated garment')]);
            self::fail('Rate-limited extraction must fail');
        } catch (FashionExtractionException $error) {
            self::assertSame('rate_limit', $error->category);
        }
        self::assertSame(1, $llm->calls);
    }

    public function testConfidentDeterministicFastPathSkipsLlmAndReportsMetric(): void
    {
        $llm = new RecordingFashionExtractionLlm([]);
        $metrics = new RecordingFashionMetrics();
        $extractor = new LlmFashionAttributeExtractor($llm, new MemoryFashionExtractionCache(), $metrics, 2);

        $item = $extractor->extract([new RawFashionSuggestion('white denim trousers')])[0];

        self::assertSame([
            'category' => 'trousers', 'subcategory' => null, 'color' => 'white',
            'material' => 'denim', 'style' => null, 'pattern' => null, 'fit' => null,
        ], $item->toArray());
        self::assertSame(0, $llm->calls);
        self::assertSame(1, $metrics->counts['fashion_extraction_fast_path_total'] ?? 0);
    }

    public function testNonSequentialInputKeysPreserveSuggestionOrderAcrossFastAndLlmPaths(): void
    {
        $llm = new RecordingFashionExtractionLlm([
            new LLMResponse(toolCalls: [new ToolCall('mixed-path', 'emit_fashion_attributes', [
                'items' => [[
                    'category' => 'trousers', 'subcategory' => null, 'color' => null,
                    'material' => null, 'style' => null, 'pattern' => null, 'fit' => null,
                ]],
            ])]),
        ]);
        $extractor = new LlmFashionAttributeExtractor($llm, new MemoryFashionExtractionCache(), new RecordingFashionMetrics(), 1);

        $items = $extractor->extract([
            8 => new RawFashionSuggestion('white denim trousers'),
            9 => new RawFashionSuggestion('nice trousers'),
        ]);

        self::assertCount(2, $items);
        self::assertSame('white', $items[0]->color);
        self::assertSame('trousers', $items[1]->category);
    }
}

final class RecordingFashionExtractionLlm implements LLMProvider
{
    public int $calls = 0;
    public array $messages = [];
    public array $tools = [];
    public string $toolChoice = '';
    public array $options = [];
    public array $messagesByCall = [];

    /** @param list<LLMResponse> $responses */
    public function __construct(private array $responses) {}

    public function chat(array $messages, array $tools = [], string $toolChoice = 'auto', array $options = []): LLMResponse
    {
        $this->messagesByCall[] = $messages;
        $this->messages = $messages;
        $this->tools = $tools;
        $this->toolChoice = $toolChoice;
        $this->options = $options;
        return $this->responses[min($this->calls++, count($this->responses) - 1)];
    }
}

final class ThrowingFashionExtractionLlm implements LLMProvider
{
    public int $calls = 0;
    public function __construct(private Throwable $error) {}
    public function chat(array $messages, array $tools = [], string $toolChoice = 'auto', array $options = []): LLMResponse
    {
        $this->calls++;
        throw $this->error;
    }
}

final class MemoryFashionExtractionCache implements FashionExtractionCache
{
    public array $values = [];
    public function get(string $key): ?array { return $this->values[$key] ?? null; }
    public function set(string $key, array $items): void { $this->values[$key] = $items; }
}

final class RecordingFashionMetrics implements FashionPipelineMetrics
{
    public array $counts = [];
    public function increment(string $metric): void { $this->counts[$metric] = ($this->counts[$metric] ?? 0) + 1; }
}
