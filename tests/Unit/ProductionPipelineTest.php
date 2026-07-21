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

    public function testDeterministicParserUnderstandsProductDetailWithoutLlm(): void
    {
        $partial = (new DeterministicIntentParser())->parse('Chi tiết sản phẩm mã 52')->toArray();
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
        $partial = (new DeterministicIntentParser())->parse('Tìm áo sơ mi trắng dưới 500k, mặc đi phỏng vấn nhưng không quá già.')->toArray();
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
        $partial = (new DeterministicIntentParser())->parse('Tìm áo dưới 500k, khoảng 700k cũng được.')->toArray();
        $conflicts = (new ConflictDetector())->detect($partial);

        $this->assertNotEmpty($conflicts);
        $this->assertSame('max_price', $conflicts[0]['field']);
        $this->assertCount(2, $conflicts[0]['values']);
    }

    public function testSocialFillerDoesNotRequireLlm(): void
    {
        $partial = (new DeterministicIntentParser())->parse('Tìm áo đen dưới 300k giúp mình với nhé.')->toArray();
        $actionable = array_values(array_filter(
            $partial['unresolved_spans'],
            fn($span) => ($span['affects_execution'] ?? false) === true
        ));

        $this->assertSame('product_search', $partial['resolved_fields']['intent']['value']);
        $this->assertSame([], $actionable);
    }

    public function testSlotMemoryResolvesPronounProductId(): void
    {
        $partial = (new DeterministicIntentParser())->parse('Cái này còn size L không?', [
            'slots' => ['last_product_id' => 52],
        ])->toArray();

        $this->assertSame(52, $partial['resolved_fields']['product_id']['value']);
        $this->assertSame('slot_memory', $partial['resolved_fields']['product_id']['source']);

        $withoutMemory = (new DeterministicIntentParser())->parse('Cái này còn size L không?')->toArray();
        $this->assertArrayNotHasKey('product_id', $withoutMemory['resolved_fields']);
    }

    public function testProductSlotMemoryDoesNotContaminateStandalonePolicyTurn(): void
    {
        $parser = new DeterministicIntentParser();
        $memory = [
            'slots' => [
                'product_type' => 'áo',
                'category_id' => 1,
                'last_product_id' => 52,
            ],
        ];

        $policy = $parser->parse('Đơn từ 500k có được miễn phí giao hàng không?', $memory)->toArray();
        $continuation = $parser->parse('Áo đó còn size L không?', $memory)->toArray();

        $this->assertSame('shipping', $policy['resolved_fields']['intent']['value']);
        $this->assertArrayNotHasKey('product_type', $policy['resolved_fields']);
        $this->assertArrayNotHasKey('product_id', $policy['resolved_fields']);
        $this->assertSame('product_detail', $continuation['resolved_fields']['intent']['value']);
        $this->assertSame(52, $continuation['resolved_fields']['product_id']['value']);
    }

    public function testProductNounAloneDoesNotTurnPolicyQuestionIntoMixedIntent(): void
    {
        $parser = new DeterministicIntentParser();

        $shipping = $parser->parse('Nếu tôi mua áo 550k thì có được miễn phí giao hàng không?')->toArray();
        $return = $parser->parse('Nếu tôi đã nhận hàng rồi nhưng áo không vừa thì có đổi size được không?')->toArray();
        $mixed = $parser->parse('Áo bomber mã 52 còn hàng không và nếu không vừa size thì đổi được không?')->toArray();

        $this->assertSame('shipping', $shipping['resolved_fields']['intent']['value']);
        $this->assertSame('return_exchange', $return['resolved_fields']['intent']['value']);
        $this->assertSame('mixed_product_policy', $mixed['resolved_fields']['intent']['value']);
    }

    public function testPolicyResponseKeepsThirdSentenceFromTopRerankedChunk(): void
    {
        $intent = (new IntentAndConstraintExtractor())->extract(
            'Shop giao hàng trong bao lâu đối với nội thành và các khu vực khác?'
        );
        $response = (new ResponseGenerator())->generate(
            'Shop giao hàng trong bao lâu đối với nội thành và các khu vực khác?',
            $intent,
            [
                'cards' => [],
                'evidence' => [
                    [
                        'source' => 'policy_rag',
                        'fact_type' => 'shipping',
                        'value' => 'Có, shop giao hàng toàn quốc. Thời gian 2-5 ngày tùy khu vực. Nội thành nhận trong 24h.',
                    ],
                    [
                        'source' => 'policy_rag',
                        'fact_type' => 'shipping',
                        'value' => 'Nội dung FAQ trùng lặp không nên được ghép thêm.',
                    ],
                ],
            ],
            ['response_type' => 'final_answer']
        );

        $this->assertStringContainsString('2-5 ngày', $response['message']);
        $this->assertStringContainsString('24h', $response['message']);
        $this->assertStringNotContainsString('trùng lặp', $response['message']);
    }

    public function testMergeRejectsLockedFieldOverwrite(): void
    {
        $partial = (new DeterministicIntentParser())->parse('Tìm áo dưới 500k')->toArray();
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

    public function testPlanValidatorRequiresPolicyRetrieval(): void
    {
        $intent = (new IntentAndConstraintExtractor())->extract('Shop đổi trả trong bao lâu?');
        $capabilities = CapabilityRegistry::fromToolDefinitions((new ToolRegistry(getTestPDO(), null))->getDefinitions());
        $validator = new PlanValidator($capabilities);

        $invalid = $validator->validate(['batches' => [[]], 'response_type' => 'final_answer'], $intent);
        $this->assertFalse($invalid['passed']);
        $this->assertContains('policy_requires_retrieve_knowledge', $invalid['errors']);

        $valid = $validator->validate((new ToolPlanner($capabilities))->plan($intent), $intent);
        $this->assertTrue($valid['passed']);
        $this->assertSame('return', $valid['sanitized_plan']['batches'][0][0]['args']['category']);
        $this->assertSame(5, $valid['sanitized_plan']['batches'][0][0]['args']['limit']);
    }

    public function testPlanValidatorRejectsProductDetailSearchRoute(): void
    {
        $intent = (new IntentAndConstraintExtractor())->extract('cho tôi xem sản phẩm mã 52');
        $capabilities = CapabilityRegistry::fromToolDefinitions((new ToolRegistry(getTestPDO(), null))->getDefinitions());
        $validator = new PlanValidator($capabilities);

        $result = $validator->validate([
            'batches' => [[
                ['tool' => 'search_products', 'args' => ['search' => '52'], 'id' => 'wrong_search'],
            ]],
            'response_type' => 'final_answer',
        ], $intent);

        $this->assertFalse($result['passed']);
        $this->assertContains('product_detail_requires_get_product_detail', $result['errors']);
        $this->assertContains('product_detail_must_not_search', $result['errors']);
    }

    public function testEvidenceScorerPassesProductDetailWithCorrectId(): void
    {
        $intent = (new IntentAndConstraintExtractor())->extract('cho tôi xem sản phẩm mã 52');
        $normalized = [
            'cards' => [[
                'id' => 52,
                'name' => 'Áo Khoác Bomber Kaki Đen',
                'price' => 550000,
                'stock' => 12,
                'url' => '/product.php?id=52',
                'image_url' => '/images/ak_01.jpg',
            ]],
            'evidence' => [
                ['source' => 'product_detail', 'fact_type' => 'name', 'product_id' => 52, 'value' => 'Áo Khoác Bomber Kaki Đen', 'confidence' => 1],
                ['source' => 'product_detail', 'fact_type' => 'price', 'product_id' => 52, 'value' => 550000, 'confidence' => 1],
                ['source' => 'product_detail', 'fact_type' => 'stock', 'product_id' => 52, 'value' => 12, 'confidence' => 1],
            ],
        ];

        $score = (new LightweightEvidenceScorer())->score($intent, $normalized, ['hard_failures' => []]);

        $this->assertTrue($score['passed']);
        $this->assertGreaterThanOrEqual(0.75, $score['score']);
    }

    public function testEvidenceScorerFailsProductDetailWithWrongId(): void
    {
        $intent = (new IntentAndConstraintExtractor())->extract('cho tôi xem sản phẩm mã 52');
        $normalized = [
            'cards' => [[
                'id' => 51,
                'name' => 'Áo Sơ Mi Linen Xanh',
                'price' => 320000,
                'stock' => 5,
                'url' => '/product.php?id=51',
                'image_url' => '',
            ]],
            'evidence' => [
                ['source' => 'product_detail', 'fact_type' => 'name', 'product_id' => 51, 'value' => 'Áo Sơ Mi Linen Xanh', 'confidence' => 1],
            ],
        ];

        $score = (new LightweightEvidenceScorer())->score($intent, $normalized, ['hard_failures' => ['product_id_mismatch']]);

        $this->assertFalse($score['passed']);
        $this->assertContains('product_id', $score['missing_evidence']);
    }

    public function testEvidenceScorerFailsProductSearchAboveMaxPrice(): void
    {
        $intent = (new IntentAndConstraintExtractor())->extract('tìm áo dưới 500k');
        $normalized = [
            'cards' => [[
                'id' => 52,
                'name' => 'Áo Khoác Bomber Kaki Đen',
                'price' => 550000,
                'stock' => 12,
                'url' => '/product.php?id=52',
                'image_url' => '',
            ]],
            'evidence' => [
                ['source' => 'product_search', 'fact_type' => 'result_count', 'value' => 1, 'confidence' => 1],
            ],
        ];

        $score = (new LightweightEvidenceScorer())->score($intent, $normalized, ['hard_failures' => []]);

        $this->assertFalse($score['passed']);
        $this->assertContains('price_constraint', $score['missing_evidence']);
    }

    public function testEvidenceScorerPassesPolicyContent(): void
    {
        $intent = (new IntentAndConstraintExtractor())->extract('Shop đổi trả trong bao lâu?');
        $normalized = [
            'cards' => [],
            'evidence' => [[
                'source' => 'policy_rag',
                'fact_type' => 'return',
                'value' => 'Shop hỗ trợ đổi trả trong 7 ngày nếu sản phẩm còn nguyên tem mác.',
                'confidence' => 0.9,
            ]],
        ];

        $score = (new LightweightEvidenceScorer())->score($intent, $normalized, ['hard_failures' => []]);

        $this->assertTrue($score['passed']);
        $this->assertSame('return', $score['recommended_next_action']);
    }

    public function testEvidenceScorerRejectsPolicyContentFromWrongDomain(): void
    {
        $intent = (new IntentAndConstraintExtractor())->extract('Shop đổi trả trong bao lâu?');
        $normalized = [
            'cards' => [],
            'evidence' => [[
                'source' => 'policy_rag',
                'fact_type' => 'shipping',
                'value' => 'Phí giao hàng nội thành là 30.000 đồng.',
                'confidence' => 0.9,
            ]],
        ];

        $score = (new LightweightEvidenceScorer())->score($intent, $normalized, ['hard_failures' => []]);

        $this->assertFalse($score['passed']);
        $this->assertContains('policy_content', $score['missing_evidence']);
        $this->assertSame('rewrite_query', $score['recommended_next_action']);
    }

    public function testDecisionRouterRepeatsLowPolicyEvidenceThenFallsBackOnBudget(): void
    {
        $intent = (new IntentAndConstraintExtractor())->extract('Shop đổi trả trong bao lâu?');
        $router = new ReasoningDecisionRouter();

        $repeat = $router->decide($intent, ['batches' => [[['tool' => 'retrieve_knowledge']]]], ['evidence' => []], ['hard_failures' => []], [
            'passed' => false,
            'missing_evidence' => ['policy_source'],
        ], [
            'loop_count' => 1,
            'tool_calls' => 1,
            'query_rewrites' => 0,
            'tool_retries' => 0,
        ], false);
        $this->assertSame('rewrite_query', $repeat['action']);

        $fallback = $router->decide($intent, ['batches' => [[['tool' => 'retrieve_knowledge']]]], ['evidence' => []], ['hard_failures' => []], [
            'passed' => false,
            'missing_evidence' => ['policy_source'],
        ], [
            'loop_count' => 3,
            'tool_calls' => 3,
            'query_rewrites' => 1,
            'tool_retries' => 0,
        ], false);
        $this->assertSame('fallback', $fallback['action']);
    }

    public function testNoProgressDetectorBlocksRepeatedFingerprint(): void
    {
        $detector = new NoProgressDetector();
        $execution = [
            'results' => [
                'product_search' => [
                    'tool' => 'search_products',
                    'args' => ['search' => 'áo thun'],
                    'result' => ['products' => []],
                ],
            ],
        ];

        $first = $detector->observe($execution);
        $second = $detector->observe($execution);

        $this->assertFalse($first['no_progress']);
        $this->assertTrue($second['no_progress']);
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
