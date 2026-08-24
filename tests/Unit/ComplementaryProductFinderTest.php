<?php

final class ComplementaryProductFinderTest extends \PHPUnit\Framework\TestCase
{
    public function testSharedPipelinePreservesRequirementGroupsAndShopGrounding(): void
    {
        $finder = new ComplementaryProductFinder(
            new FixtureRawSuggestionProvider(),
            new FixtureFashionAttributeExtractor(),
            new FashionRequirementNormalizer(),
            new ParallelComplementaryProductSearcher(new FinderGateway())
        );
        $result = $finder->find(50, 5001);

        $this->assertSame('success', $result['status']);
        $this->assertCount(2, $result['groups']);
        $this->assertSame([701, 702], array_column($result['products'], 'id'));
        $this->assertSame('footwear', $result['groups'][1]['requirement']['category']);
        $this->assertSame('white denim trousers', $result['raw_suggestions'][0]['text']);
        $this->assertSame('trousers', $result['extracted_items'][0]['category']);
        $this->assertArrayNotHasKey('provider_item_id', $result['raw_suggestions'][0]);
        $this->assertArrayNotHasKey('provider_product_id', $result['products'][0]);
    }
}

final class FixtureRawSuggestionProvider implements RawFashionSuggestionProvider
{
    public function suggestForAnchor(int $shopProductId, ?int $shopVariantId = null): array
    {
        return [
            new RawFashionSuggestion('white denim trousers', providerContext: ['provider_item_id' => 'never-a-shop-id']),
            new RawFashionSuggestion('minimal white sneakers', providerContext: ['provider_item_id' => 'never-a-shop-id-2']),
        ];
    }
}

final class FixtureFashionAttributeExtractor implements FashionAttributeExtractor
{
    public function extract(array $suggestions): array
    {
        return [
            new ExtractedFashionItem('trousers', null, 'white', 'denim', null, null, null),
            new ExtractedFashionItem('footwear', 'sneakers', 'white', null, 'minimal', null, null),
        ];
    }
}

final class FinderGateway implements ConcurrentProductSearchGateway
{
    public function searchBatch(array $searches, int $maxConcurrency): array
    {
        $result = [];
        foreach (array_keys($searches) as $index => $key) {
            $result[$key] = [
                'success' => true,
                'products' => [['id' => 701 + $index, 'name' => 'Shop SKU ' . ($index + 1)]],
                'error' => null,
                'duration_ms' => 1,
            ];
        }
        return $result;
    }
}
