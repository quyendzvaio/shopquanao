<?php

final class StyliticsStyleReferenceMapperTest extends \PHPUnit\Framework\TestCase
{
    public function testMapsOutfitItemsToStyleReferences(): void
    {
        $raw = json_decode((string) file_get_contents(TEST_DIR . '/fixtures/stylitics/complete-the-look-response.json'), true, 512, JSON_THROW_ON_ERROR);
        $occasion = null;
        $references = (new StyliticsStyleReferenceMapper())->map($raw, $occasion);

        self::assertCount(3, $references, 'unmappable item (gadget) must be dropped, not coerced');
        self::assertSame('office', $occasion);

        $byRole = [];
        foreach ($references as $reference) {
            $byRole[$reference->role] = $reference;
        }
        self::assertArrayHasKey('bottom', $byRole);
        self::assertArrayHasKey('shoe', $byRole);
        self::assertArrayHasKey('accessory', $byRole);

        self::assertSame('trousers', $byRole['bottom']->subcategory);
        self::assertSame(['gray'], $byRole['bottom']->colors);
        self::assertSame('stylitics', $byRole['bottom']->sourceProvider);
        self::assertSame('STL-TR-1001', $byRole['bottom']->sourceReferenceId);
        self::assertSame(0.8, $byRole['bottom']->confidence);
        self::assertSame('office', $byRole['bottom']->occasionTags[0] ?? null);
    }

    public function testExplicitRoleNormalizesPluralForms(): void
    {
        $mapper = new StyliticsStyleReferenceMapper();
        $references = $mapper->map(['outfits' => [['items' => [['item_number' => 'X', 'role' => 'bottoms', 'category' => 'trousers']]]]]);
        self::assertSame('bottom', $references[0]->role);
    }

    public function testEmptyOutfitsIsRejected(): void
    {
        $this->expectException(StyliticsApiException::class);
        (new StyliticsStyleReferenceMapper())->map(['outfits' => []]);
    }

    public function testAllUnmappableItemsIsRejected(): void
    {
        $raw = ['outfits' => [['items' => [['item_number' => 'X', 'category' => 'gadget']]]]];
        $this->expectException(StyliticsApiException::class);
        (new StyliticsStyleReferenceMapper())->map($raw);
    }

    public function testDuplicateItemsDedupeByReferenceId(): void
    {
        $item = ['item_number' => 'DUP-1', 'category' => 'bottom', 'subcategory' => 'trousers'];
        $references = (new StyliticsStyleReferenceMapper())->map(['outfits' => [['items' => [$item, $item]]]]);
        self::assertCount(1, $references);
    }

    public function testUnusableImageUrlsAreDropped(): void
    {
        $references = (new StyliticsStyleReferenceMapper())->map(['outfits' => [['items' => [
            ['item_number' => 'IMG-1', 'category' => 'top', 'image_url' => 'javascript:alert(1)'],
        ]]]]);
        self::assertNull($references[0]->referenceImageUrl);
    }
}
