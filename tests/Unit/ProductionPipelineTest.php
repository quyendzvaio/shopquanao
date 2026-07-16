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
}
