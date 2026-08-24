<?php

final class FindMineV3ResponseAdapterTest extends \PHPUnit\Framework\TestCase
{
    public function testV3ItemsPreserveIdentityAndBecomeStructuredRequirements(): void
    {
        $plan = (new FindMineV3ResponseAdapter())->toPlan([
            'result' => 'success',
            'response_uuid' => 'response-1',
            'looks' => [[
                'look_id' => 'look-1',
                'items' => [
                    [
                        'item_id' => 'fm-trousers-1',
                        'title' => 'Beige tailored trousers',
                        'item_url' => 'https://findmine.example/trousers-1',
                        'image_url' => 'https://findmine.example/trousers-1.jpg',
                        'price' => 42,
                        'category' => 'trousers',
                        'color' => 'beige',
                        'style' => 'tailored',
                    ],
                    [
                        'item_id' => 'fm-sneakers-1',
                        'title' => 'White minimal sneakers',
                        'item_url' => 'https://findmine.example/sneakers-1',
                        'image_url' => 'https://findmine.example/sneakers-1.jpg',
                        'price' => 55,
                        'category' => 'footwear',
                        'subcategory' => 'sneakers',
                        'color' => 'white',
                        'style' => 'minimal',
                    ],
                ],
            ]],
        ], 50);

        $this->assertSame(50, $plan->anchorProductId);
        $this->assertCount(2, $plan->requirements);
        $this->assertSame('sneakers', $plan->requirements[1]->subcategory);
        $this->assertSame('white', $plan->requirements[1]->colors[0]);
        $this->assertSame('fm-trousers-1', $plan->providerItems[0]['provider_item_id']);
        $this->assertSame('response-1', $plan->providerResponseUuid);
    }

    public function testMcpTextEnvelopePreservesItemId(): void
    {
        $plan = (new FindMineV3ResponseAdapter())->toPlan([
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'looks' => [[
                        'look_id' => 'look-1',
                        'products' => [[
                            'product_id' => 'fm-shirt-1',
                            'name' => 'White shirt',
                            'category' => 'shirt',
                            'color' => 'white',
                        ]],
                    ]],
                ], JSON_THROW_ON_ERROR),
            ]],
        ], 50);

        $this->assertSame('fm-shirt-1', $plan->providerItems[0]['provider_item_id']);
        $this->assertSame('shirt', $plan->requirements[0]->category);
    }

    public function testMcpCompatibilityShapePreservesResponseUuidAndStructuredAttributes(): void
    {
        $plan = (new FindMineV3ResponseAdapter())->toPlan(['content' => [['type' => 'text', 'text' => json_encode([
            'response_uuid' => 'live-response-uuid',
            'looks' => [['look_id' => 'look-live', 'products' => [[
                'item_id' => 'provider-item-1', 'title' => 'Tailored trousers',
                'attributes' => ['category' => 'trousers', 'color' => 'navy', 'style' => 'tailored'],
            ]]]],
        ], JSON_THROW_ON_ERROR)]]], 50);

        self::assertSame('live-response-uuid', $plan->providerResponseUuid);
        self::assertSame('trousers', $plan->requirements[0]->category);
        self::assertSame(['navy'], $plan->requirements[0]->colors);
        self::assertSame(['tailored'], $plan->requirements[0]->styles);
    }

    public function testMalformedItemIdentityFailsStrictly(): void
    {
        $this->expectException(FindMineProviderException::class);
        $this->expectExceptionMessage('missing item_id');
        (new FindMineV3ResponseAdapter())->toPlan([
            'result' => 'success',
            'looks' => [['items' => [['title' => 'No identity', 'category' => 'shirt']]]],
        ], 50);
    }

    public function testEmptyResultIsNotConvertedIntoFabricatedRequirements(): void
    {
        $this->expectException(FindMineProviderException::class);
        $this->expectExceptionMessage('no complementary items');
        (new FindMineV3ResponseAdapter())->toPlan(['result' => 'success', 'looks' => []], 50);
    }

    public function testProviderErrorResultIsTranslated(): void
    {
        $this->expectException(FindMineProviderException::class);
        $this->expectExceptionMessage('INVALID_STORE');
        (new FindMineV3ResponseAdapter())->toPlan([
            'result' => 'error',
            'reason' => 'INVALID_STORE',
            'looks' => [],
        ], 50);
    }
}
