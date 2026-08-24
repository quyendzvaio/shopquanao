<?php

final class FindMineFashionProviderTest extends \PHPUnit\Framework\TestCase
{
    public function testProviderUsesExactMappedVariantAndReturnsValidatedPlan(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("CREATE TABLE fashion_provider_product_mapping (
            id INTEGER PRIMARY KEY AUTOINCREMENT, shop_product_id INTEGER NOT NULL, shop_variant_id INTEGER,
            mapping_scope TEXT NOT NULL DEFAULT 'product', provider TEXT NOT NULL, provider_product_id TEXT NOT NULL,
            provider_variant_id TEXT NOT NULL DEFAULT '', provider_color_id TEXT NOT NULL DEFAULT '', provider_identifiers TEXT,
            sync_status TEXT NOT NULL DEFAULT 'pending', sync_version TEXT, last_synced_at DATETIME,
            last_error TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(provider, shop_product_id, mapping_scope), UNIQUE(provider, provider_product_id, provider_variant_id, provider_color_id)
        )");
        $repository = new FashionProviderMappingRepository($pdo);
        $mapping = new FashionProviderProductMapping(50, 'findmine', 'fm-product-50', 5001, 'fm-var-5001', 'fm-white', 'pending', null, null, null, [
            'product_id' => 'fm-product-50', 'product_color_id' => 'fm-white', 'product_pattern' => 'stripe',
        ]);
        $repository->savePending($mapping);
        $repository->markSynced('findmine', 50, 5001, 'catalog-v1');
        $client = new RecordingFindMineClient();

        $result = (new FindMineFashionProvider($repository, $client, retryAttempts: 0))
            ->completeTheLook(new AnchorProductRef(50, 'findmine', 'ignored-by-repository', 5001));

        $this->assertTrue($result->isSuccess());
        $this->assertSame('fm-product-50', $client->arguments['product_id']);
        $this->assertSame('fm-white', $client->arguments['product_color_id']);
        $this->assertSame('stripe', $client->arguments['product_identifiers']['product_pattern']);
        $this->assertSame('beige', $result->plan->requirements[0]->colors[0]);
    }

    public function testMissingMappingDoesNotCallProvider(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("CREATE TABLE fashion_provider_product_mapping (
            id INTEGER PRIMARY KEY AUTOINCREMENT, shop_product_id INTEGER NOT NULL, shop_variant_id INTEGER,
            mapping_scope TEXT NOT NULL DEFAULT 'product', provider TEXT NOT NULL, provider_product_id TEXT NOT NULL,
            provider_variant_id TEXT NOT NULL DEFAULT '', provider_color_id TEXT NOT NULL DEFAULT '', provider_identifiers TEXT,
            sync_status TEXT NOT NULL DEFAULT 'pending', sync_version TEXT, last_synced_at DATETIME,
            last_error TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(provider, shop_product_id, mapping_scope), UNIQUE(provider, provider_product_id, provider_variant_id, provider_color_id)
        )");
        $client = new RecordingFindMineClient();
        $result = (new FindMineFashionProvider(new FashionProviderMappingRepository($pdo), $client))
            ->completeTheLook(new AnchorProductRef(50, 'findmine', 'not-used'));

        $this->assertFalse($result->isSuccess());
        $this->assertSame('mapping_not_found', $result->errorCode);
        $this->assertFalse($client->called);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonRetryableProviderErrors')]
    public function testNonRetryableProviderErrorsAreTranslatedWithoutReplay(string $category, string $expected): void
    {
        [$repository, $anchor] = $this->syncedMapping();
        $client = new FailingFindMineClient(new FindMineProviderException($category, 'sensitive provider detail', false));

        $result = (new FindMineFashionProvider($repository, $client, retryAttempts: 3))->completeTheLook($anchor);

        self::assertSame($expected, $result->errorCode);
        self::assertSame(1, $client->calls);
        self::assertStringNotContainsString('sensitive', (string) $result->errorMessage);
    }

    public static function nonRetryableProviderErrors(): array
    {
        return [
            'authentication' => ['AUTHENTICATION_ERROR', 'authentication_failed'],
            'unknown product' => ['UNKNOWN_PROVIDER_PRODUCT', 'unknown_provider_product'],
            'invalid request' => ['INVALID_REQUEST', 'invalid_request'],
        ];
    }

    public function testRateLimitIsBoundedlyRetriedAndTranslated(): void
    {
        [$repository, $anchor] = $this->syncedMapping();
        $client = new FailingFindMineClient(new FindMineProviderException('RATE_LIMITED', '429 secret detail', true));

        $result = (new FindMineFashionProvider($repository, $client, retryAttempts: 1))->completeTheLook($anchor);

        self::assertSame('rate_limited', $result->errorCode);
        self::assertSame(2, $client->calls);
    }

    private function syncedMapping(): array
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("CREATE TABLE fashion_provider_product_mapping (
            id INTEGER PRIMARY KEY AUTOINCREMENT, shop_product_id INTEGER NOT NULL, shop_variant_id INTEGER,
            mapping_scope TEXT NOT NULL DEFAULT 'product', provider TEXT NOT NULL, provider_product_id TEXT NOT NULL,
            provider_variant_id TEXT NOT NULL DEFAULT '', provider_color_id TEXT NOT NULL DEFAULT '', provider_identifiers TEXT,
            sync_status TEXT NOT NULL DEFAULT 'pending', sync_version TEXT, last_synced_at DATETIME,
            last_error TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(provider, shop_product_id, mapping_scope), UNIQUE(provider, provider_product_id, provider_variant_id, provider_color_id)
        )");
        $repository = new FashionProviderMappingRepository($pdo);
        $repository->savePending(new FashionProviderProductMapping(50, 'findmine', 'provider-50'));
        $repository->markSynced('findmine', 50, null, 'catalog-v1');
        return [$repository, new AnchorProductRef(50, 'findmine', 'provider-50')];
    }
}

final class RecordingFindMineClient implements FindMineMcpClientContract
{
    public bool $called = false;
    public array $arguments = [];

    public function initialize(): array { return ['protocolVersion' => '2025-11-25']; }
    public function listTools(): array { return [['name' => 'get_complete_the_look']]; }
    public function call(string $toolName, array $arguments): array
    {
        $this->called = true;
        $this->arguments = $arguments;
        return ['result' => 'success', 'response_uuid' => 'response-1', 'looks' => [[
            'look_id' => 'look-1', 'items' => [[
                'item_id' => 'fm-beige-trousers', 'category' => 'trousers', 'color' => 'beige',
            ]],
        ]]];
    }
}

final class FailingFindMineClient implements FindMineMcpClientContract
{
    public int $calls = 0;

    public function __construct(private FindMineProviderException $error) {}
    public function initialize(): array { return []; }
    public function listTools(): array { return []; }
    public function call(string $toolName, array $arguments): array
    {
        $this->calls++;
        throw $this->error;
    }
}
