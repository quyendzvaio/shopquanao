<?php

class ProductionPipelineTest extends \PHPUnit\Framework\TestCase
{
    public function testReturnIntentHasShippingAsSecondary(): void
    {
        $extractor = new IntentAndConstraintExtractor();
        $intent = $extractor->extract('Đổi size có mất phí ship không?');

        $this->assertSame('return_exchange', $intent['primary_intent']);
        $this->assertContains('shipping', $intent['secondary_intents']);
        $this->assertContains('exchange_eligibility', $intent['requested_fields']);
        $this->assertContains('exchange_shipping_fee', $intent['requested_fields']);
    }

    public function testProductIdPlansProductDetailOnly(): void
    {
        $extractor = new IntentAndConstraintExtractor();
        $planner = new ToolPlanner();

        $intent = $extractor->extract('áo mã 52 xem chi tiết');
        $plan = $planner->plan($intent);
        $tools = array_map(fn($call) => $call['tool'], $plan['batches'][0] ?? []);

        $this->assertSame('product_detail', $intent['primary_intent']);
        $this->assertSame(['get_product_detail'], $tools);
    }

    public function testMissingSizeSlotsCreatesClarificationPlan(): void
    {
        $extractor = new IntentAndConstraintExtractor();
        $planner = new ToolPlanner();

        $intent = $extractor->extract('mình mặc size gì?');
        $plan = $planner->plan($intent);

        $this->assertSame('size_advice', $intent['primary_intent']);
        $this->assertSame('clarification', $plan['response_type']);
        $this->assertContains('height', $intent['missing_slots']);
        $this->assertContains('weight', $intent['missing_slots']);
    }

    public function testEvidenceNormalizerDoesNotExposeRawToolMetadata(): void
    {
        $normalizer = new EvidenceNormalizer();
        $normalized = $normalizer->normalize(['primary_intent' => 'product_search'], [
            'results' => [
                'product_search' => [
                    'tool' => 'search_products',
                    'success' => true,
                    'duration_ms' => 12,
                    'result' => [
                        'products' => [[
                            'id' => 52,
                            'name' => 'Áo Khoác Bomber Kaki Đen',
                            'price' => 550000,
                            'stock' => 12,
                            'image' => 'ak_01.jpg',
                        ]],
                    ],
                ],
            ],
        ]);

        $this->assertSame(52, $normalized['cards'][0]['id']);
        $this->assertSame('/product.php?id=52', $normalized['cards'][0]['url']);
        $this->assertSame('/images/ak_01.jpg', $normalized['cards'][0]['image_url']);
        $this->assertStringNotContainsString('localhost', $normalized['cards'][0]['url']);
        $this->assertStringNotContainsString('localhost', $normalized['cards'][0]['image_url']);
        $this->assertArrayNotHasKey('duration_ms', $normalized['cards'][0]);
        $this->assertNotEmpty($normalized['evidence']);
    }

    public function testFastParserUnderstandsProductDetailWithoutLlm(): void
    {
        $partial = (new FastParser())->parse('Chi tiết sản phẩm mã 52')->toArray();
        $intent = (new MergeEngine())->merge($partial, ['used' => false, 'inferred_fields' => []]);
        $plan = (new ToolPlanner())->plan($intent);

        $this->assertSame(52, $partial['resolved_fields']['product_id']['value']);
        $this->assertSame('product_detail', $intent['primary_intent']);
        $this->assertFalse($intent['semantic_completion']['used']);
        $this->assertSame('get_product_detail', $plan['batches'][0][0]['tool']);
        $this->assertSame(52, $plan['batches'][0][0]['args']['product_id']);
    }

    public function testPartialSemanticCompletionAddsOnlyUnresolvedFields(): void
    {
        $partial = (new FastParser())->parse('Tìm áo sơ mi trắng dưới 500k, mặc đi phỏng vấn nhưng không quá già.')->toArray();
        $partial['conflicts'] = (new ConflictDetector())->detect($partial);
        $llm = new FakeSemanticCompletionLlm([
            'inferred_fields' => [
                'occasion' => ['value' => 'interview', 'confidence' => 0.91],
                'style' => ['value' => ['youthful', 'semi_formal'], 'confidence' => 0.88],
                'avoid' => ['value' => ['overly_mature'], 'confidence' => 0.87],
                'max_price' => ['value' => 600000, 'confidence' => 0.5],
            ],
            'unresolved_remaining' => [],
        ]);

        $completion = (new LLMSemanticCompletion($llm))->complete($partial, []);
        $intent = (new MergeEngine())->merge($partial, $completion);

        $this->assertSame(1, $llm->calls);
        $this->assertSame('áo sơ mi', $intent['entities']['product_type']);
        $this->assertSame('white', $intent['entities']['color']);
        $this->assertSame(500000, $intent['entities']['max_price']);
        $this->assertSame('interview', $intent['entities']['occasion']);
        $this->assertSame(['youthful', 'semi_formal'], $intent['entities']['style']);
        $this->assertSame(['overly_mature'], $intent['entities']['avoid']);
        $this->assertArrayNotHasKey('max_price', $completion['inferred_fields']);
    }

    public function testConflictDetectorFindsPriceConflict(): void
    {
        $partial = (new FastParser())->parse('Tìm áo dưới 500k, khoảng 700k cũng được.')->toArray();
        $conflicts = (new ConflictDetector())->detect($partial);

        $this->assertNotEmpty($conflicts);
        $this->assertSame('max_price', $conflicts[0]['field']);
        $this->assertCount(2, $conflicts[0]['values']);
    }

    public function testSocialFillerDoesNotRequireLlm(): void
    {
        $partial = (new FastParser())->parse('Tìm áo đen dưới 300k giúp mình với nhé.')->toArray();
        $actionable = array_values(array_filter(
            $partial['unresolved_spans'],
            fn($span) => ($span['affects_execution'] ?? false) === true
        ));

        $this->assertSame('product_search', $partial['resolved_fields']['intent']['value']);
        $this->assertSame([], $actionable);
    }

    public function testSlotMemoryResolvesPronounProductId(): void
    {
        $partial = (new FastParser())->parse('Cái này còn size L không?', [
            'slots' => ['last_product_id' => 52],
        ])->toArray();

        $this->assertSame(52, $partial['resolved_fields']['product_id']['value']);
        $this->assertSame('slot_memory', $partial['resolved_fields']['product_id']['source']);

        $withoutMemory = (new FastParser())->parse('Cái này còn size L không?')->toArray();
        $this->assertArrayNotHasKey('product_id', $withoutMemory['resolved_fields']);
    }

    public function testMergeRejectsLockedFieldOverwrite(): void
    {
        $partial = (new FastParser())->parse('Tìm áo dưới 500k')->toArray();
        $intent = (new MergeEngine())->merge($partial, [
            'used' => true,
            'inferred_fields' => [
                'max_price' => ['value' => 600000, 'confidence' => 0.95],
                'occasion' => ['value' => 'casual', 'confidence' => 0.8],
            ],
        ]);

        $this->assertSame(500000, $intent['entities']['max_price']);
        $this->assertSame('casual', $intent['entities']['occasion']);
        $this->assertSame('max_price', $intent['locked_field_overwrite_attempts'][0]['field']);
    }
}

class FakeSemanticCompletionLlm implements LLMProvider
{
    public int $calls = 0;
    private array $response;

    public function __construct(array $response)
    {
        $this->response = $response;
    }

    public function chat(array $messages, array $tools = [], string $toolChoice = 'auto'): LLMResponse
    {
        $this->calls++;
        return new LLMResponse(json_encode($this->response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
