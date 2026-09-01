<?php

use PHPUnit\Framework\TestCase;

final class PrivateCatalogStyleMapperTest extends TestCase
{
    public function testRoleAndCategoryHardFiltersRejectWrongCategory(): void
    {
        $gateway = new RecordingPrivateSearchGateway([
            ['id' => 11, 'name' => 'Black chunky sneakers', 'category' => 'footwear', 'stock' => 5],
            ['id' => 12, 'name' => 'Black formal leather loafers', 'category' => 'footwear', 'stock' => 3],
            ['id' => 13, 'name' => 'Black trousers', 'category' => 'bottoms', 'stock' => 9],
        ]);
        $mapper = new PrivateCatalogStyleMapper(new ParallelComplementaryProductSearcher($gateway));
        $result = $mapper->map(new StyleReference(
            'shoe', 'footwear', 'loafers', ['black'], ['leather'], ['formal'], ['smart casual'],
            null, 'black formal leather loafer', null, 'glance', 'glance-ref-1'
        ));

        self::assertSame('mapped', $result->mappingStatus);
        self::assertSame(12, $result->selectedProduct['id']);
        self::assertSame([12], array_column($result->candidates, 'id'));
        self::assertSame([
            ['id' => 11, 'reason' => 'no_confident_mapping'],
            ['id' => 13, 'reason' => 'role_mismatch'],
        ], $result->evidence['rejected']);
        self::assertSame('glance-ref-1', $result->reference->sourceReferenceId);
        self::assertStringNotContainsString('glance-ref-1', json_encode($result->selectedProduct, JSON_THROW_ON_ERROR));
    }

    public function testUnavailableProductsAreHardRejectedAndNoMatchIsSafe(): void
    {
        $gateway = new RecordingPrivateSearchGateway([
            ['id' => 21, 'name' => 'Navy trousers', 'category' => 'bottoms', 'stock' => 0],
        ]);
        $mapper = new PrivateCatalogStyleMapper(new ParallelComplementaryProductSearcher($gateway));
        $result = $mapper->map(new StyleReference('bottom', 'Bottomwear', null, ['navy'], [], [], [], null, 'navy trousers'));

        self::assertSame('no_match', $result->mappingStatus);
        self::assertNull($result->selectedProduct);
        self::assertSame([], $result->candidates);
        self::assertSame([['id' => 21, 'reason' => 'unavailable']], $result->evidence['rejected']);
    }

    public function testFormalLoaferDoesNotFallBackToAnExplicitSneakerSubtype(): void
    {
        $gateway = new RecordingPrivateSearchGateway([[
            'id' => 22,
            'name' => 'Black leather sneakers',
            'category' => 'footwear',
            'subcategory' => 'sneakers',
            'stock' => 4,
        ]]);
        $mapper = new PrivateCatalogStyleMapper(new ParallelComplementaryProductSearcher($gateway));
        $result = $mapper->map(new StyleReference(
            'shoe', 'footwear', 'loafers', ['black'], ['leather'], ['formal'], ['smart casual'],
            null, 'black formal leather loafer'
        ));

        self::assertSame('no_match', $result->mappingStatus);
        self::assertNull($result->selectedProduct);
        self::assertSame([['id' => 22, 'reason' => 'no_confident_mapping']], $result->evidence['rejected']);
    }

    public function testProviderFieldsAreRemovedFromPrivateCandidateOutput(): void
    {
        $gateway = new RecordingPrivateSearchGateway([[
            'id' => 31,
            'name' => 'White shirt',
            'category' => 'tops',
            'stock' => 2,
            'provider_product_id' => 'glance-product',
            'provider_variant_id' => 'glance-variant',
            'provider_color_id' => 'glance-color',
        ]]);
        $mapper = new PrivateCatalogStyleMapper(new ParallelComplementaryProductSearcher($gateway));
        $result = $mapper->map(new StyleReference('top', 'Topwear', null, [], [], ['minimal']));

        self::assertSame('mapped', $result->mappingStatus);
        self::assertArrayNotHasKey('provider_product_id', $result->selectedProduct);
        self::assertArrayNotHasKey('provider_variant_id', $result->selectedProduct);
        self::assertArrayNotHasKey('provider_color_id', $result->selectedProduct);
    }

    public function testMapManyUsesOneBoundedParallelSearchBatch(): void
    {
        $gateway = new RecordingPrivateSearchGateway([
            ['id' => 41, 'name' => 'Navy trousers', 'category' => 'bottoms', 'stock' => 2],
            ['id' => 42, 'name' => 'White shirt', 'category' => 'tops', 'stock' => 2],
        ]);
        $mapper = new PrivateCatalogStyleMapper(new ParallelComplementaryProductSearcher($gateway, 2));
        $results = $mapper->mapMany([
            new StyleReference('bottom', 'Bottomwear'),
            new StyleReference('top', 'Topwear'),
        ]);

        self::assertCount(2, $results);
        self::assertSame(1, $gateway->batchCalls);
        self::assertSame(['mapped', 'mapped'], array_map(fn (MappedStyleReference $result): string => $result->mappingStatus, $results));
    }
}

final class RecordingPrivateSearchGateway implements ConcurrentProductSearchGateway
{
    public int $batchCalls = 0;

    /** @param list<array<string,mixed>> $products */
    public function __construct(private array $products) {}

    public function searchBatch(array $searches, int $maxConcurrency): array
    {
        $this->batchCalls++;
        $result = [];
        foreach ($searches as $key => $_query) {
            $result[$key] = [
                'success' => true,
                'products' => $this->products,
                'error' => null,
                'duration_ms' => 1,
            ];
        }
        return $result;
    }
}
