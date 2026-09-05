<?php

class ProductionPipelineTest extends \PHPUnit\Framework\TestCase
{
    public function testReturnIntentHasShippingAsSecondary(): void
    {
        $extractor = new IntentResolver();
        $intent = $extractor->extract('Đổi size có mất phí ship không?');

        $this->assertSame('return_exchange', $intent['primary_intent']);
        $this->assertContains('shipping', $intent['secondary_intents']);
        $this->assertContains('exchange_eligibility', $intent['requested_fields']);
        $this->assertContains('exchange_shipping_fee', $intent['requested_fields']);
    }

    public function testProductIdPlansProductDetailOnly(): void
    {
        $extractor = new IntentResolver();
        $planner = new ToolPlanner();

        $intent = $extractor->extract('áo mã 52 xem chi tiết');
        $plan = $planner->plan($intent);
        $tools = array_map(fn($call) => $call['tool'], $plan['batches'][0] ?? []);

        $this->assertSame('product_detail', $intent['primary_intent']);
        $this->assertSame(['get_product_detail'], $tools);
    }

    public function testMissingSizeSlotsCreatesClarificationPlan(): void
    {
        $extractor = new IntentResolver();
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
        $this->assertFalse($intent['entity_enrichment']['used']);
        $this->assertSame('get_product_detail', $plan['batches'][0][0]['tool']);
        $this->assertSame(52, $plan['batches'][0][0]['args']['product_id']);
    }

    public function testResolvedDeterministicProductQuerySkipsFullLlmInputParser(): void
    {
        $llm = new FakeInputParserLlm([
            'intent' => 'product_search',
            'product_query' => 'áo sơ mi',
            'category_id' => 1,
            'max_price' => 500000,
            'min_price' => null,
            'color' => null,
            'size' => null,
            'in_stock' => null,
            'product_id' => null,
            'order_id' => null,
            'height_cm' => null,
            'weight_kg' => null,
            'style' => null,
            'occasion' => null,
            'avoid' => null,
        ]);

        $intent = (new IntentResolver($llm))->extract('tìm áo sơmi dưới 500k');
        $plan = (new ToolPlanner())->plan($intent);

        $this->assertSame('product_search', $intent['primary_intent']);
        $this->assertSame('áo sơ mi', $intent['entities']['product_type']);
        $this->assertSame(500000, $intent['entities']['max_price']);
        $this->assertSame('search_products', $plan['batches'][0][0]['tool']);
        $this->assertSame('áo sơ mi', $plan['batches'][0][0]['args']['search']);
        $this->assertSame(500000, $plan['batches'][0][0]['args']['max_price']);
        $this->assertSame(0, $llm->calls);
        $this->assertSame('', $llm->lastToolChoice);
    }

    public function testUnknownDeterministicQueryCanUseLlmInputParser(): void
    {
        $llm = new FakeInputParserLlm([
            'intent' => 'product_search',
            'product_query' => 'áo cardigan',
            'category_id' => 1,
            'max_price' => null,
            'min_price' => null,
            'color' => null,
            'size' => null,
            'in_stock' => null,
            'product_id' => null,
            'order_id' => null,
            'height_cm' => null,
            'weight_kg' => null,
            'style' => null,
            'occasion' => null,
            'avoid' => null,
        ]);

        $intent = (new IntentResolver($llm))->extract('cần một món cardigan');

        $this->assertSame('product_search', $intent['primary_intent']);
        $this->assertSame('áo cardigan', $intent['entities']['product_type']);
        $this->assertSame(1, $llm->calls);
        $this->assertSame('required', $llm->lastToolChoice);
    }

    public function testLlmInputParserReadsGatewayParameterMarkupWhenToolArgumentsAreEmpty(): void
    {
        $llm = new FakeMarkupInputParserLlm();

        $intent = (new IntentResolver($llm))->extract('cần một món cardigan dưới 500k');
        $plan = (new ToolPlanner())->plan($intent);

        $this->assertSame('product_search', $intent['primary_intent']);
        $this->assertSame('áo sơ mi', $intent['entities']['product_type']);
        $this->assertSame(500000, $plan['batches'][0][0]['args']['max_price']);
        $this->assertSame('áo sơ mi', $plan['batches'][0][0]['args']['search']);
    }

    public function testDeterministicParserNormalizesUnspacedProductTypesAndPriceConstraints(): void
    {
        $resolver = new IntentResolver();
        
        $intent1 = $resolver->extract('tìm sản phẩm áo sơmi dưới 500k');
        $this->assertSame('product_search', $intent1['primary_intent']);
        $this->assertSame('áo sơ mi', $intent1['entities']['product_type']);
        $this->assertSame(500000, $intent1['entities']['max_price']);

        $intent2 = $resolver->extract('quanjean dưới 400k');
        $this->assertSame('product_search', $intent2['primary_intent']);
        $this->assertSame('quần jeans', $intent2['entities']['product_type']);
        $this->assertSame(400000, $intent2['entities']['max_price']);

        $intent3 = $resolver->extract('aothun dưới 200k');
        $this->assertSame('product_search', $intent3['primary_intent']);
        $this->assertSame('áo thun', $intent3['entities']['product_type']);
        $this->assertSame(200000, $intent3['entities']['max_price']);
    }

    public function testOptionalEntityEnrichmentAddsOnlyUnresolvedFields(): void
    {
        $partial = (new DeterministicIntentParser())->parse('Tìm áo sơ mi trắng dưới 500k, mặc đi phỏng vấn nhưng không quá già.')->toArray();
        $partial['conflicts'] = (new ConflictDetector())->detect($partial);
        $llm = new FakeEntityEnrichmentLlm([
            'inferred_fields' => [
                'occasion' => ['value' => 'interview', 'confidence' => 0.91],
                'style' => ['value' => ['youthful', 'semi_formal'], 'confidence' => 0.88],
                'avoid' => ['value' => ['overly_mature'], 'confidence' => 0.87],
                'max_price' => ['value' => 600000, 'confidence' => 0.5],
            ],
            'unresolved_remaining' => [],
        ]);

        $enrichment = (new SemanticEntityEnricher($llm))->enrich($partial, []);
        $intent = (new MergeEngine())->merge($partial, $enrichment);

        $this->assertSame(1, $llm->calls);
        $this->assertSame('áo sơ mi', $intent['entities']['product_type']);
        $this->assertSame('trắng', $intent['entities']['color']);
        $this->assertSame(500000, $intent['entities']['max_price']);
        $this->assertSame('interview', $intent['entities']['occasion']);
        $this->assertSame(['youthful', 'semi_formal'], $intent['entities']['style']);
        $this->assertSame(['overly_mature'], $intent['entities']['avoid']);
        $this->assertArrayNotHasKey('max_price', $enrichment['inferred_fields']);
    }

    public function testLlmEntityEnrichmentCannotChangeSelectedTool(): void
    {
        $query = 'Tìm áo sơ mi trắng dưới 500k, mặc đi phỏng vấn nhưng không quá già.';
        $withoutLlm = (new IntentResolver())->resolve($query);
        $planWithoutLlm = (new ToolPlanner())->plan($withoutLlm['intent']);

        $llm = new FakeEntityEnrichmentLlm([
            'inferred_fields' => [
                'occasion' => ['value' => 'interview', 'confidence' => 0.95],
                'style' => ['value' => ['youthful'], 'confidence' => 0.9],
            ],
            'unresolved_remaining' => [],
        ]);
        $withLlm = (new IntentResolver($llm))->resolve($query);
        $planWithLlm = (new ToolPlanner())->plan($withLlm['intent']);

        $this->assertSame(['search_products'], $this->selectedTools($planWithoutLlm));
        $this->assertSame($this->selectedTools($planWithoutLlm), $this->selectedTools($planWithLlm));
        $this->assertSame('product_search', $withLlm['intent']['primary_intent']);
        $this->assertSame([], $llm->lastTools);
        $this->assertSame('none', $llm->lastToolChoice);
    }

    public function testUnsupportedCartActionNeverCallsLlmOrProductTools(): void
    {
        $llm = new FakeEntityEnrichmentLlm([
            'inferred_fields' => ['product_id' => ['value' => 52, 'confidence' => 1]],
            'unresolved_remaining' => [],
        ]);
        $resolution = (new IntentResolver($llm))->resolve('thêm áo mã 52 vào giỏ');
        $plan = (new ToolPlanner())->plan($resolution['intent']);

        $this->assertSame('unsupported_checkout', $resolution['intent']['primary_intent']);
        $this->assertSame([], $this->selectedTools($plan));
        $this->assertSame(0, $llm->calls);
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

    public function testRequestedProductSizeRoutesToProductSearchNotSizeAdvice(): void
    {
        $partial = (new DeterministicIntentParser())->parse('tìm áo size M màu đen còn hàng')->toArray();

        $this->assertSame('product_search', $partial['resolved_fields']['intent']['value']);
        $this->assertSame('M', $partial['resolved_fields']['size']['value']);
        $this->assertSame('đen', $partial['resolved_fields']['color']['value']);
        $this->assertTrue((bool)$partial['resolved_fields']['in_stock']['value']);
        $this->assertSame([], $partial['missing_fields']);
    }

    public function testProductAttributeNormalizerCanonicalizesColors(): void
    {
        $this->assertSame('đen', ProductAttributeNormalizer::normalizeColor('black'));
        $this->assertSame('đen', ProductAttributeNormalizer::normalizeColor('den'));
        $this->assertSame('đen', ProductAttributeNormalizer::normalizeColor('đen'));
        $this->assertSame('trắng', ProductAttributeNormalizer::normalizeColor('white'));
        $this->assertSame('xám', ProductAttributeNormalizer::normalizeColor('gray'));
        $this->assertSame('xám', ProductAttributeNormalizer::normalizeColor('ghi'));
    }

    public function testHeartNecklineIsNotParsedOrMatchedAsPurple(): void
    {
        $partial = (new DeterministicIntentParser())->parse('tìm áo dài tay cổ tim')->toArray();

        $this->assertArrayNotHasKey('color', $partial['resolved_fields']);
        $this->assertNull(ProductAttributeNormalizer::normalizeColor('áo dài tay cổ tim'));
        $this->assertFalse(ProductAttributeNormalizer::textMatchesColor('Áo Dài Tay Cổ Tim', 'tím'));
        $this->assertTrue(ProductAttributeNormalizer::textMatchesColor('Áo len màu tím', 'tim'));
    }

    public function testDecimalMeterHeightDoesNotTriggerRepeatedSizeClarification(): void
    {
        $intent = (new IntentResolver())->extract(
            'tôi là nam cao 1,62m nặng 55kg thì nên mặc áo size gì'
        );

        $this->assertSame('size_advice', $intent['primary_intent']);
        $this->assertSame(162, $intent['entities']['height']);
        $this->assertSame(55, $intent['entities']['weight']);
        $this->assertSame([], $intent['missing_slots']);
    }

    public function testVietnameseSingleDigitMeterHeightMeansDecimeters(): void
    {
        $intent = (new IntentResolver())->extract('mình nặng 49kg và cao 1m7');

        $this->assertSame('size_advice', $intent['primary_intent']);
        $this->assertSame(170, $intent['entities']['height_cm']);
        $this->assertSame(49, $intent['entities']['weight_kg']);
    }

    public function testProductConstraintVerifierFiltersWrongColorCards(): void
    {
        $intent = (new IntentResolver())->extract('tìm áo màu đen');
        $normalized = [
            'cards' => [
                [
                    'id' => 50,
                    'name' => 'Áo Thun Cotton Basic Trắng',
                    'description' => 'Cotton 100%',
                    'category_id' => 1,
                    'price' => 180000,
                    'stock' => 10,
                    'available_sizes' => ['M'],
                    'available_colors' => ['trắng'],
                ],
                [
                    'id' => 52,
                    'name' => 'Áo Khoác Bomber Kaki Đen',
                    'description' => 'Bomber kaki',
                    'category_id' => 1,
                    'price' => 550000,
                    'stock' => 12,
                    'available_sizes' => ['M'],
                    'available_colors' => ['đen'],
                ],
            ],
            'evidence' => [
                ['source' => 'product_search', 'fact_type' => 'result_count', 'value' => 2],
                ['source' => 'product_search', 'fact_type' => 'name', 'product_id' => 50, 'value' => 'Áo Thun Cotton Basic Trắng'],
                ['source' => 'product_search', 'fact_type' => 'name', 'product_id' => 52, 'value' => 'Áo Khoác Bomber Kaki Đen'],
            ],
        ];

        $verified = (new ProductConstraintVerifier())->verify($intent, $normalized);

        $this->assertCount(1, $verified['cards']);
        $this->assertSame(52, $verified['cards'][0]['id']);
        $resultCounts = array_values(array_filter($verified['evidence'], fn($item) => ($item['fact_type'] ?? '') === 'result_count'));
        $this->assertSame(1, (int)$resultCounts[0]['value']);
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
        $intent = (new IntentResolver())->extract(
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

    public function testComplementaryResponseNamesEachGroundedShopCardOnce(): void
    {
        $cards = [
            ['id' => 51, 'name' => 'Áo Sơ Mi Linen Tay Ngắn Xanh'],
            ['id' => 66, 'name' => 'Quần Tây Ống Đứng Xám'],
        ];
        $response = (new ResponseGenerator())->generate(
            'Sản phẩm mã 50 phối với gì?',
            ['primary_intent' => 'suggest_complementary_products'],
            [
                'cards' => $cards,
                'evidence' => [],
                'complementary_groups' => [
                    ['requirement' => ['shop_search' => 'áo sơ mi'], 'products' => [$cards[0]]],
                    ['requirement' => ['shop_search' => 'áo sơ mi'], 'products' => [$cards[0]]],
                    ['requirement' => ['shop_search' => 'quần tây'], 'products' => [$cards[1]]],
                ],
            ],
            ['response_type' => 'final_answer']
        );

        $this->assertSame(1, substr_count($response['message'], 'Áo Sơ Mi Linen Tay Ngắn Xanh'));
        $this->assertSame(1, substr_count($response['message'], 'Quần Tây Ống Đứng Xám'));
        $this->assertStringContainsString('mã 51', $response['message']);
        $this->assertStringContainsString('mã 66', $response['message']);
        $this->assertStringContainsString('Để phối cùng', $response['message']);
    }

    public function testOutfitSetWithShirtAndPantsUsesUnsupportedGuardrail(): void
    {
        $intent = (new IntentResolver())->extract('Phối giúp tôi một set đi chơi gồm áo và quần đi');
        $plan = (new ToolPlanner())->plan($intent);

        $this->assertSame('unsupported_outfit', $intent['primary_intent']);
        $this->assertSame([], $plan['batches']);
    }

    public function testGenericOutfitQuestionDoesNotInvokeAnchoredComplementaryTool(): void
    {
        $intent = (new IntentResolver())->extract('Áo thun trắng phối với quần gì cho đẹp?');
        $plan = (new ToolPlanner())->plan($intent);

        $this->assertSame('unsupported_outfit', $intent['primary_intent']);
        $this->assertSame([], $plan['batches']);
    }

    public function testFootwearWordDoesNotTriggerOldStyleEntityEnrichment(): void
    {
        $llm = new FakeEntityEnrichmentLlm([
            'inferred_fields' => [
                'style' => ['value' => ['youthful'], 'confidence' => 0.9],
                'avoid' => ['value' => ['old'], 'confidence' => 0.9],
            ],
            'unresolved_remaining' => [],
        ]);

        $resolution = (new IntentResolver($llm))->resolve(
            'Áo Sơ Mi Caro Đỏ Đen, mã sản phẩm 63, phối với quần và giày nào?'
        );

        $this->assertSame('suggest_complementary_products', $resolution['intent']['primary_intent']);
        $this->assertSame(63, $resolution['intent']['entities']['product_id']);
        $this->assertSame(0, $llm->calls);
        $this->assertSame('no_actionable_unresolved_span', $resolution['enrichment']['error']);
    }

    public function testPolicyResponseIdentifiesShopAsPolicySource(): void
    {
        $intent = (new IntentResolver())->extract('Shop có bảo hành lỗi đường may không?');
        $response = (new ResponseGenerator())->generate(
            'Shop có bảo hành lỗi đường may không?',
            $intent,
            [
                'cards' => [],
                'evidence' => [[
                    'source' => 'policy_rag',
                    'fact_type' => 'warranty',
                    'value' => 'Sản phẩm được bảo hành 30 ngày về lỗi đường may, lỗi vải.',
                ]],
            ],
            ['response_type' => 'final_answer']
        );

        $this->assertStringContainsString('shop', mb_strtolower($response['message']));
        $this->assertStringContainsString('lỗi đường may', $response['message']);
    }

    public function testReturnPolicyResponseIncludesSizeAndPersonalShippingRule(): void
    {
        $intent = (new IntentResolver())->extract('Nếu đơn đã nhận rồi mà áo không vừa thì có đổi size được không?');
        $response = (new ResponseGenerator())->generate(
            'Nếu đơn đã nhận rồi mà áo không vừa thì có đổi size được không?',
            $intent,
            [
                'cards' => [],
                'evidence' => [
                    [
                        'source' => 'policy_rag',
                        'fact_type' => 'return',
                        'value' => 'Có, đổi trả trong vòng 7 ngày kể từ ngày nhận hàng. Sản phẩm phải còn nguyên tem mác, chưa qua sử dụng.',
                    ],
                    [
                        'source' => 'policy_rag',
                        'fact_type' => 'return',
                        'value' => '- Nếu đổi size/màu do nhu cầu cá nhân, khách thanh toán phí vận chuyển hai chiều.',
                    ],
                ],
            ],
            ['response_type' => 'final_answer']
        );

        $this->assertStringContainsString('7 ngày', $response['message']);
        $this->assertStringContainsString('đổi size', $response['message']);
        $this->assertStringContainsString('phí vận chuyển', $response['message']);
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
        $intent = (new IntentResolver())->extract('Shop đổi trả trong bao lâu?');
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
        $intent = (new IntentResolver())->extract('cho tôi xem sản phẩm mã 52');
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
        $intent = (new IntentResolver())->extract('cho tôi xem sản phẩm mã 52');
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
        $intent = (new IntentResolver())->extract('cho tôi xem sản phẩm mã 52');
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
        $intent = (new IntentResolver())->extract('tìm áo dưới 500k');
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
        $intent = (new IntentResolver())->extract('Shop đổi trả trong bao lâu?');
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
        $intent = (new IntentResolver())->extract('Shop đổi trả trong bao lâu?');
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
        $intent = (new IntentResolver())->extract('Shop đổi trả trong bao lâu?');
        $router = new EvidenceDecisionRouter();

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
}

class FakeEntityEnrichmentLlm implements LLMProvider
{
    public int $calls = 0;
    public array $lastTools = [];
    public string $lastToolChoice = '';
    private array $response;

    public function __construct(array $response)
    {
        $this->response = $response;
    }

    public function chat(array $messages, array $tools = [], string $toolChoice = 'auto', array $options = []): LLMResponse
    {
        $this->calls++;
        $this->lastTools = $tools;
        $this->lastToolChoice = $toolChoice;
        return new LLMResponse(json_encode($this->response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

class FakeInputParserLlm implements LLMProvider
{
    public int $calls = 0;
    public string $lastToolChoice = '';

    public function __construct(private array $arguments)
    {
    }

    public function chat(array $messages, array $tools = [], string $toolChoice = 'auto', array $options = []): LLMResponse
    {
        $this->calls++;
        $this->lastToolChoice = $toolChoice;
        return new LLMResponse(toolCalls: [new ToolCall('input-parser', 'parse_chatbot_input', $this->arguments)]);
    }
}

class FakeMarkupInputParserLlm implements LLMProvider
{
    public function chat(array $messages, array $tools = [], string $toolChoice = 'auto', array $options = []): LLMResponse
    {
        return new LLMResponse(
            content: '<tool_call><function=parse_chatbot_input>'
                . '<parameter=intent>product_search</parameter>'
                . '<parameter=product_query>áo sơ mi</parameter>'
                . '<parameter=category_id>1</parameter>'
                . '<parameter=product_id>None</parameter>'
                . '<parameter=order_id>None</parameter>'
                . '<parameter=min_price>None</parameter>'
                . '<parameter=max_price>500000</parameter>'
                . '<parameter=color>None</parameter>'
                . '<parameter=size>None</parameter>'
                . '<parameter=in_stock>None</parameter>'
                . '<parameter=height_cm>None</parameter>'
                . '<parameter=weight_kg>None</parameter>'
                . '<parameter=occasion>None</parameter>'
                . '<parameter=style>None</parameter>'
                . '<parameter=avoid>None</parameter>'
                . '</function></tool_call>',
            toolCalls: [new ToolCall('gateway-markup', 'parse_chatbot_input', [])]
        );
    }
}
