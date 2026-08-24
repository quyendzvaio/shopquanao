<?php

final class ParallelComplementaryProductSearcherTest extends \PHPUnit\Framework\TestCase
{
    public function testAllRequirementsAreSubmittedAsOneBoundedConcurrentBatch(): void
    {
        $gateway = new RecordingConcurrentSearchGateway();
        $searcher = new ParallelComplementaryProductSearcher($gateway, 3);
        $requirements = [
            new ShopComplementaryRequirement(1, 'trousers', 'quần tây', 2, [], ['be'], []),
            new ShopComplementaryRequirement(2, 'shoes', 'giày', null, ['basic'], ['trắng'], []),
            new ShopComplementaryRequirement(3, 'jacket', 'áo khoác', 1, [], [], []),
        ];

        $groups = $searcher->search($requirements);

        $this->assertSame(1, $gateway->batchCalls);
        $this->assertSame(3, $gateway->receivedConcurrency);
        $this->assertCount(3, $gateway->receivedSearches);
        $this->assertCount(3, $groups);
        $this->assertSame('trousers', $groups[0]['requirement']['category']);
        $this->assertSame(501, $groups[0]['products'][0]['id']);
    }

    public function testIndependentFailureAndZeroResultGroupsRemainExplicit(): void
    {
        $gateway = new RecordingConcurrentSearchGateway(failSecond: true, emptyThird: true);
        $searcher = new ParallelComplementaryProductSearcher($gateway, 2);
        $groups = $searcher->search([
            new ShopComplementaryRequirement(1, 'trousers', 'quần tây', 2, [], [], []),
            new ShopComplementaryRequirement(2, 'shoes', 'giày', null, [], [], []),
            new ShopComplementaryRequirement(3, 'bag', 'túi xách', 4, [], [], []),
        ]);

        $this->assertFalse($groups[0]['search_failed']);
        $this->assertTrue($groups[1]['search_failed']);
        $this->assertSame([], $groups[1]['products']);
        $this->assertFalse($groups[2]['search_failed']);
        $this->assertSame([], $groups[2]['products']);
    }

    public function testProviderIdsCannotLeakIntoShopProductCards(): void
    {
        $gateway = new RecordingConcurrentSearchGateway(includeProviderIds: true);
        $groups = (new ParallelComplementaryProductSearcher($gateway))->search([
            new ShopComplementaryRequirement(1, 'trousers', 'quần tây', 2, [], [], []),
        ]);

        $product = $groups[0]['products'][0];
        $this->assertSame(501, $product['id']);
        $this->assertArrayNotHasKey('provider_product_id', $product);
        $this->assertArrayNotHasKey('provider_variant_id', $product);
        $this->assertArrayNotHasKey('provider_color_id', $product);
    }

    public function testZeroResultsRelaxWithinCategoryWithoutFallingBackToAnyProduct(): void
    {
        $gateway = new RelaxingConcurrentSearchGateway();
        $metrics = new SearchRecordingFashionMetrics();
        $requirement = new FashionRequirement(
            1,
            'trousers',
            'quần tây',
            2,
            ['công sở'],
            ['white'],
            ['jean']
        );

        $groups = (new ParallelComplementaryProductSearcher($gateway, 4, $metrics))->search([$requirement]);

        self::assertSame(3, $gateway->batchCalls);
        self::assertSame(2, $groups[0]['relaxation_level']);
        self::assertSame(777, $groups[0]['products'][0]['id']);
        self::assertSame(2, $metrics->counts['fashion_search_relaxation_total'] ?? 0);
        self::assertSame(2, $gateway->queries[2]['category_id']);
        self::assertSame('white', $gateway->queries[2]['color']);
        self::assertArrayNotHasKey('material', $gateway->queries[2]);
        self::assertNotSame(['in_stock' => true], $gateway->queries[2]);
    }
}

final class SearchRecordingFashionMetrics implements FashionPipelineMetrics
{
    public array $counts = [];
    public function increment(string $metric): void { $this->counts[$metric] = ($this->counts[$metric] ?? 0) + 1; }
}

final class RelaxingConcurrentSearchGateway implements ConcurrentProductSearchGateway
{
    public int $batchCalls = 0;
    public array $queries = [];

    public function searchBatch(array $searches, int $maxConcurrency): array
    {
        $this->batchCalls++;
        $key = array_key_first($searches);
        $this->queries[$this->batchCalls - 1] = $searches[$key];
        return [$key => [
            'success' => true,
            'products' => $this->batchCalls === 3 ? [['id' => 777, 'name' => 'Grounded trousers']] : [],
            'error' => null,
            'duration_ms' => 10,
        ]];
    }
}

final class RecordingConcurrentSearchGateway implements ConcurrentProductSearchGateway
{
    public int $batchCalls = 0;
    public int $receivedConcurrency = 0;
    public array $receivedSearches = [];

    public function __construct(
        private bool $failSecond = false,
        private bool $emptyThird = false,
        private bool $includeProviderIds = false
    ) {
    }

    public function searchBatch(array $searches, int $maxConcurrency): array
    {
        $this->batchCalls++;
        $this->receivedConcurrency = $maxConcurrency;
        $this->receivedSearches = $searches;
        $results = [];
        foreach (array_keys($searches) as $index => $key) {
            if ($index === 1 && $this->failSecond) {
                $results[$key] = ['success' => false, 'products' => [], 'error' => 'timeout', 'duration_ms' => 50];
                continue;
            }
            $products = $index === 2 && $this->emptyThird ? [] : [[
                'id' => 501 + $index,
                'name' => 'Shop product',
                'provider_product_id' => $this->includeProviderIds ? 'must-not-leak' : null,
                'provider_variant_id' => $this->includeProviderIds ? 'must-not-leak' : null,
                'provider_color_id' => $this->includeProviderIds ? 'must-not-leak' : null,
            ]];
            $results[$key] = ['success' => true, 'products' => $products, 'error' => null, 'duration_ms' => 50];
        }
        return $results;
    }
}
