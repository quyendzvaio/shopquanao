<?php

use PHPUnit\Framework\TestCase;

final class GlanceLiveResponseMapperTest extends TestCase
{
    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(ROOT_DIR . '/tests/fixtures/glance/' . $name), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testMapsMultipleStructuredOutfitItemsToReferences(): void
    {
        $references = (new GlanceLiveResponseMapper())->map($this->fixture('mapper-structured.json'));

        self::assertCount(2, $references);
        self::assertSame(['bottom', 'shoe'], array_map(fn (StyleReference $r): string => $r->role, $references));
        self::assertSame('sanitized-bottom-1', $references[0]->sourceReferenceId);
        self::assertSame('trousers', $references[0]->category);
        self::assertSame(['navy'], $references[0]->colors);
        self::assertSame(['smart casual'], $references[0]->styleTags);
        self::assertSame('https://images.example.invalid/navy-trousers.jpg', $references[0]->referenceImageUrl);
        self::assertSame('glance', $references[0]->sourceProvider);
    }

    public function testMapsObservedGlanceOutfitsProductsShape(): void
    {
        $references = (new GlanceLiveResponseMapper())->map($this->fixture('mapper-live-shape.json'));

        self::assertCount(2, $references);
        self::assertSame(['bottom', 'shoe'], array_map(fn (StyleReference $r): string => $r->role, $references));
        self::assertSame('provider-sku-bottom', $references[0]->sourceReferenceId);
        self::assertSame('trousers', $references[0]->category);
        self::assertSame(['smart-casual'], $references[0]->occasionTags);
        self::assertSame('https://images.example.invalid/brown-loafers.jpg', $references[1]->referenceImageUrl);
        self::assertStringNotContainsString('provider-variant-bottom', json_encode($references[0]->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testMapsSingleReferenceAndInfersRoleFromCategory(): void
    {
        $references = (new GlanceLiveResponseMapper())->map([
            'structuredContent' => [
                'recommendations' => [[
                    'product_id' => 'ref-1',
                    'name' => 'White shirt',
                    'category' => 'shirt',
                ]],
            ],
        ]);

        self::assertCount(1, $references);
        self::assertSame('top', $references[0]->role);
        self::assertSame('White shirt, shirt', $references[0]->referenceText);
    }

    public function testHandlesMissingOptionalAttributesWithoutFabrication(): void
    {
        $reference = (new GlanceLiveResponseMapper())->map([
            'structuredContent' => ['items' => [[
                'item_id' => 'ref-2',
                'category' => 'bottom',
            ]]],
        ])[0];

        self::assertSame([], $reference->colors);
        self::assertSame([], $reference->materials);
        self::assertSame([], $reference->styleTags);
        self::assertNull($reference->referenceImageUrl);
        self::assertSame('bottom', $reference->role);
    }

    public function testUsesStructuredLikeJsonTextAsFallback(): void
    {
        $references = (new GlanceLiveResponseMapper())->map([
            'content' => [[
                'type' => 'text',
                'text' => "Result:\n```json\n{\"items\":[{\"item_id\":\"ref-3\",\"title\":\"Black sneakers\",\"category\":\"footwear\"}]}\n```",
            ]],
        ]);

        self::assertCount(1, $references);
        self::assertSame('shoe', $references[0]->role);
        self::assertSame('ref-3', $references[0]->sourceReferenceId);
    }

    public function testUnknownFieldsAreIgnoredAndProviderIdCannotBecomeShopSku(): void
    {
        $reference = (new GlanceLiveResponseMapper())->map([
            'structuredContent' => ['items' => [[
                'item_id' => 'provider-only-id',
                'title' => 'Brown loafers',
                'category' => 'footwear',
                'shop_sku' => 'must-not-be-used',
                'unknown_nested' => ['secret' => 'ignored'],
            ]]],
        ])[0];

        self::assertSame('provider-only-id', $reference->sourceReferenceId);
        self::assertStringNotContainsString('must-not-be-used', json_encode($reference->toArray(), JSON_THROW_ON_ERROR));
    }

    public function testEmptyStructuredAndTextResponsesFailClosed(): void
    {
        $this->expectException(GlanceResponseMappingException::class);
        $this->expectExceptionMessage('no mappable styling references');
        (new GlanceLiveResponseMapper())->map([
            'structuredContent' => [],
            'content' => [['type' => 'text', 'text' => 'Styling unavailable']],
        ]);
    }

    public function testMalformedReferenceFailsClosed(): void
    {
        $this->expectException(GlanceResponseMappingException::class);
        $this->expectExceptionMessage('invalid');
        (new GlanceLiveResponseMapper())->map([
            'structuredContent' => ['items' => [[
                'item_id' => 'ref-bad',
                'description' => 'No category or role',
            ]]],
        ]);
    }
}
