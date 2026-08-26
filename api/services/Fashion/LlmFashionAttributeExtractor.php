<?php

final class LlmFashionAttributeExtractor implements FashionAttributeExtractor
{
    public const SCHEMA_VERSION = '3';
    public const PROMPT_VERSION = '3';
    public const NORMALIZER_VERSION = '1';

    private DeterministicFashionAttributeParser $deterministic;
    private FashionExtractionSemanticValidator $semanticValidator;
    private string $modelId;

    public function __construct(
        private LLMProvider $llm,
        private FashionExtractionCache $cache = new ApplicationFashionExtractionCache(),
        private FashionPipelineMetrics $metrics = new StructuredLogFashionMetrics(),
        private int $maxAttempts = 2,
        ?DeterministicFashionAttributeParser $deterministic = null,
        ?FashionExtractionSemanticValidator $semanticValidator = null,
        ?string $modelId = null
    ) {
        $this->maxAttempts = max(1, min(2, $this->maxAttempts));
        $this->deterministic = $deterministic ?? new DeterministicFashionAttributeParser();
        $this->semanticValidator = $semanticValidator ?? new FashionExtractionSemanticValidator($this->deterministic);
        $this->modelId = trim((string) ($modelId ?? (getenv('FASHION_EXTRACTION_LLM_MODEL') ?: getenv('LLM_MODEL') ?: 'unknown')));
    }

    public function extract(array $suggestions): array
    {
        if ($suggestions === []) return [];
        $suggestions = array_values($suggestions);
        foreach ($suggestions as $suggestion) {
            if (!$suggestion instanceof RawFashionSuggestion) {
                throw new InvalidArgumentException('Extractor accepts only RawFashionSuggestion values');
            }
        }

        $cacheKey = $this->cacheKey($suggestions);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            try {
                $cached = array_values($cached);
                return array_map(
                    fn (array $item, int $index): ExtractedFashionItem => $this->semanticValidator->validate(ExtractedFashionItem::fromArray($item), $suggestions[$index]->text),
                    $cached,
                    array_keys($cached)
                );
            } catch (Throwable) {
                // Ignore poisoned/stale cache data and perform a fresh extraction.
            }
        }

        $results = array_fill(0, count($suggestions), null);
        $llmSuggestions = [];
        $llmIndexes = [];
        foreach ($suggestions as $index => $suggestion) {
            $deterministic = $this->deterministic->parse($suggestion->text);
            if ($this->deterministic->isConfidentFastPath($deterministic)) {
                $results[$index] = $deterministic;
                $this->metrics->increment('fashion_extraction_fast_path_total');
            } else {
                $llmSuggestions[] = $suggestion;
                $llmIndexes[] = $index;
                $this->metrics->increment('fashion_extraction_llm_path_total');
            }
        }

        if ($llmSuggestions === []) {
            /** @var list<ExtractedFashionItem> $results */
            $this->storeSuccessful($cacheKey, $results);
            return $results;
        }

        $last = null;
        $invalidSnapshot = null;
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $this->metrics->increment('fashion_extraction_calls_total');
            try {
                $response = $this->llm->chat(
                    $attempt === 1
                        ? $this->messages($llmSuggestions)
                        : $this->repairMessages($llmSuggestions, $invalidSnapshot ?? '{}', $last?->getMessage() ?? 'invalid schema'),
                    [$this->tool()],
                    'required',
                    [
                        'temperature' => 0,
                        'max_tokens' => max(200, min(800, (int) (getenv('FASHION_EXTRACTION_MAX_TOKENS') ?: 600))),
                        'reasoning' => false,
                        'stream' => false,
                    ]
                );
                $invalidSnapshot = $this->responseSnapshot($response);
                $items = $this->validateResponse($response, $llmSuggestions);
                foreach ($items as $offset => $item) $results[$llmIndexes[$offset]] = $item;
                /** @var list<ExtractedFashionItem> $results */
                if ($attempt === 2) $this->metrics->increment('fashion_extraction_repair_success_total');
                $this->storeSuccessful($cacheKey, $results);
                return $results;
            } catch (FashionExtractionException $error) {
                $last = $error;
                if ($error->category === 'invalid_schema') {
                    $this->metrics->increment('fashion_extraction_invalid_schema_total');
                }
                if ($error->category !== 'invalid_schema' || $attempt >= $this->maxAttempts) break;
                $this->metrics->increment('fashion_extraction_repair_attempts_total');
            } catch (Throwable $error) {
                $last = $this->infrastructureFailure($error);
                // Infrastructure failures are surfaced to the caller/evaluator;
                // schema repair retries must never amplify 429s or outages.
                break;
            }
        }

        $this->metrics->increment('fashion_extraction_failure_total');
        throw $last ?? new FashionExtractionException('llm_failure', 'Fashion extraction failed');
    }

    /** @param list<RawFashionSuggestion> $suggestions */
    private function cacheKey(array $suggestions): string
    {
        $raw = array_map(
            static fn (RawFashionSuggestion $suggestion): array => ['source' => $suggestion->source, 'text' => $suggestion->text],
            $suggestions
        );
        return 'fashion_extraction|' . hash('sha256', json_encode([
            'provider_mode' => 'findmine_demo',
            'raw' => $raw,
            'extractor_schema' => self::SCHEMA_VERSION,
            'prompt_version' => self::PROMPT_VERSION,
            'normalizer_version' => self::NORMALIZER_VERSION,
            'model_id' => $this->modelId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param list<RawFashionSuggestion> $suggestions */
    private function messages(array $suggestions): array
    {
        $input = array_map(
            static fn (RawFashionSuggestion $suggestion, int $index): array => [
                'suggestion_number' => $index + 1,
                'text' => $suggestion->text,
            ],
            $suggestions,
            array_keys($suggestions)
        );
        return [
            ['role' => 'system', 'content' => implode(' ', [
                'You extract fashion attributes from provided styling suggestions.',
                'Return only structured output matching the supplied schema.',
                'For every mentioned clothing item extract ONLY attributes explicitly stated in the input.',
                'Rules:',
                '- Never recommend anything.',
                '- Never add a garment that was not present in the input.',
                '- Never output product IDs.',
                '- Never assume inventory.',
                '- Never infer material, color, style, fit, or pattern when absent from the text — use null.',
                '- Never use non-canonical values; use only the exact enum strings defined in the schema.',
                '- Canonical category values: shirt, trousers, footwear, jacket, dress, skirt, accessory.',
                '- For Vietnamese garments: áo thun/hoodie/polo = category "shirt"; quần jean/jeans = category "trousers" subcategory "jeans"; giày/dép = category "footwear"; áo khoác/blazer = category "jacket"; váy/đầm = category "dress"; chân váy = category "skirt"; thắt lưng = category "accessory" subcategory "belt".',
                '- Map colors to English canonical names: đen=black, trắng=white, xanh navy=navy, nâu=brown, be=beige, đỏ=red, xanh=blue, xám=gray, kem=cream.',
                '- Map materials to English canonical names: len=wool, vải lanh=linen, da=leather.',
                '- Keep separate garments as separate items.',
                '- Unknown attributes must be null.',
            ])],
            ['role' => 'user', 'content' => json_encode(['suggestions' => $input], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)],
        ];
    }

    /** @param list<RawFashionSuggestion> $suggestions */
    private function repairMessages(array $suggestions, string $invalidOutput, string $validationError): array
    {
        return [
            ['role' => 'system', 'content' => 'Repair the output to satisfy the supplied schema. Do not add or infer new fashion attributes. Return only the required function call.'],
            ['role' => 'user', 'content' => json_encode([
                'original_suggestions' => array_map(static fn (RawFashionSuggestion $item): string => $item->text, $suggestions),
                'invalid_structured_output' => $invalidOutput,
                'validation_error' => $validationError,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)],
        ];
    }

    private function tool(): array
    {
        $nullableString = ['type' => ['string', 'null']];
        return [
            'type' => 'function',
            'function' => [
                'name' => 'emit_fashion_attributes',
                'description' => 'Return extracted fashion attributes only. Use null when an attribute is unknown or absent.',
                'strict' => true,
                'parameters' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['items'],
                    'properties' => [
                        'items' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => ['category', 'subcategory', 'color', 'material', 'style', 'pattern', 'fit'],
                                'properties' => [
                                    'category' => ['type' => ['string', 'null'], 'enum' => ['shirt', 'trousers', 'footwear', 'jacket', 'dress', 'skirt', 'accessory', null]],
                                    'subcategory' => ['type' => ['string', 'null'], 'enum' => ['sneakers', 'loafers', 'dress_shoes', 'blazer', 'hoodie', 'polo', 'jeans', 'midi_dress', 'belt', null]],
                                    'color' => $nullableString,
                                    'material' => ['type' => ['string', 'null'], 'enum' => ['denim', 'linen', 'leather', 'wool', 'cotton', null]],
                                    'style' => ['type' => ['string', 'null'], 'enum' => ['minimal', 'casual', 'simple', 'lightweight', null]],
                                    'pattern' => ['type' => ['string', 'null'], 'enum' => ['striped', 'floral', 'pleated', null]],
                                    'fit' => ['type' => ['string', 'null'], 'enum' => ['wide_leg', 'slim', 'oversized', 'regular', null]],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return list<ExtractedFashionItem> */
    /** @param list<RawFashionSuggestion> $suggestions @return list<ExtractedFashionItem> */
    private function validateResponse(LLMResponse $response, array $suggestions): array
    {
        $suggestions = array_values($suggestions);
        $expectedCount = count($suggestions);
        $call = $response->getFirstToolCall();
        if ($call === null || $call->name !== 'emit_fashion_attributes') {
            throw new FashionExtractionException('invalid_schema', 'Fashion extractor did not return the required structured function');
        }
        if (array_keys($call->arguments) !== ['items'] || !is_array($call->arguments['items'])) {
            throw new FashionExtractionException('invalid_schema', 'Fashion extractor response object is invalid');
        }
        if (count($call->arguments['items']) !== $expectedCount) {
            throw new FashionExtractionException('invalid_schema', 'Fashion extractor must preserve one item per suggestion');
        }
        try {
            return array_map(
                function (mixed $item, int $index) use ($suggestions): ExtractedFashionItem {
                    if (!is_array($item)) throw new InvalidArgumentException('Fashion extraction item must be an object');
                    foreach (array_keys($item) as $field) {
                        if (is_string($item[$field]) && in_array(strtolower(trim($item[$field])), ['null', 'unknown', 'none', 'n/a', 'na'], true)) {
                            $item[$field] = null;
                        }
                    }
                    return $this->semanticValidator->validate(ExtractedFashionItem::fromArray($item), $suggestions[$index]->text);
                },
                array_values($call->arguments['items']),
                array_keys(array_values($call->arguments['items']))
            );
        } catch (Throwable $error) {
            throw new FashionExtractionException('invalid_schema', 'Fashion extractor item schema is invalid', $error);
        }
    }

    /** @param list<ExtractedFashionItem> $items */
    private function storeSuccessful(string $cacheKey, array $items): void
    {
        $this->cache->set($cacheKey, array_map(static fn (ExtractedFashionItem $item): array => $item->toArray(), $items));
        $this->metrics->increment('fashion_extraction_success_total');
    }

    private function responseSnapshot(LLMResponse $response): string
    {
        $call = $response->getFirstToolCall();
        return json_encode([
            'finish_reason' => $response->finishReason,
            'content' => $response->content,
            'tool_name' => $call?->name,
            'arguments' => $call?->arguments,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function infrastructureFailure(Throwable $error): FashionExtractionException
    {
        if ($error instanceof LLMTransportException) {
            return new FashionExtractionException($error->category, 'Fashion extraction LLM infrastructure failure', $error);
        }
        $message = strtolower($error->getMessage());
        $category = match (true) {
            str_contains($message, '429'), str_contains($message, 'rate limit'), str_contains($message, 'too many requests') => 'rate_limit',
            str_contains($message, 'timeout'), str_contains($message, 'timed out') => 'timeout',
            str_contains($message, 'connection'), str_contains($message, 'unavailable'), str_contains($message, 'http 502'), str_contains($message, 'http 503') => 'provider_unavailable',
            default => 'llm_failure',
        };
        return new FashionExtractionException($category, 'Fashion extraction LLM infrastructure failure', $error);
    }
}
