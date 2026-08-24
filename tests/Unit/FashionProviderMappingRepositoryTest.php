<?php

final class FashionProviderMappingRepositoryTest extends \PHPUnit\Framework\TestCase
{
    private PDO $pdo;
    private FashionProviderMappingRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec("CREATE TABLE fashion_provider_product_mapping (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            shop_product_id INTEGER NOT NULL,
            shop_variant_id INTEGER,
            mapping_scope TEXT NOT NULL DEFAULT 'product',
            provider TEXT NOT NULL,
            provider_product_id TEXT NOT NULL,
            provider_variant_id TEXT NOT NULL DEFAULT '',
            provider_color_id TEXT NOT NULL DEFAULT '',
            provider_identifiers TEXT,
            sync_status TEXT NOT NULL DEFAULT 'pending',
            sync_version TEXT,
            last_synced_at DATETIME,
            last_error TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(provider, shop_product_id, mapping_scope),
            UNIQUE(provider, provider_product_id, provider_variant_id, provider_color_id)
        )");
        $this->repository = new FashionProviderMappingRepository($this->pdo);
    }

    public function testMappingMovesFromPendingToSyncedAndBecomesAnAnchor(): void
    {
        $mapping = new FashionProviderProductMapping(50, 'findmine', 'fm-50', 5001, 'fm-var-M', 'white');
        $this->repository->savePending($mapping);
        $this->assertNull($this->repository->findSynced('findmine', 50, 5001));

        $this->repository->markSynced('findmine', 50, 5001, 'catalog-v1');
        $synced = $this->repository->findSynced('findmine', 50, 5001);

        $this->assertNotNull($synced);
        $this->assertSame('catalog-v1', $synced->syncVersion);
        $this->assertSame('fm-var-M', $synced->toAnchor()->providerVariantId);
        $this->assertSame(5001, $synced->toAnchor()->shopVariantId);
    }

    public function testFailedMappingCanBeSafelyRetried(): void
    {
        $mapping = new FashionProviderProductMapping(51, 'findmine', 'fm-51');
        $this->repository->savePending($mapping);
        $this->repository->markFailed('findmine', 51, null, 'provider unavailable');

        $retry = $this->repository->findForSync('findmine');
        $this->assertCount(1, $retry);
        $this->assertSame('failed', $retry[0]->syncStatus);
        $this->assertSame('provider unavailable', $retry[0]->lastError);

        $this->repository->savePending($mapping);
        $this->assertSame('pending', $this->repository->findForSync('findmine')[0]->syncStatus);
    }

    public function testUniqueProviderIdentityPreventsConflictingShopMappings(): void
    {
        $this->repository->savePending(new FashionProviderProductMapping(50, 'findmine', 'fm-shared'));

        $this->expectException(PDOException::class);
        $this->repository->savePending(new FashionProviderProductMapping(51, 'findmine', 'fm-shared'));
    }

    public function testLatestPendingDefinitionIsIdempotentlyUpdated(): void
    {
        $this->repository->savePending(new FashionProviderProductMapping(50, 'findmine', 'fm-old'));
        $this->repository->savePending(new FashionProviderProductMapping(50, 'findmine', 'fm-new', syncVersion: 'v2'));

        $rows = $this->repository->findForSync('findmine');
        $this->assertCount(1, $rows);
        $this->assertSame('fm-new', $rows[0]->providerProductId);
        $this->assertSame('v2', $rows[0]->syncVersion);
    }

    public function testExactVariantColorMappingsCoexistWithoutAmbiguousFallback(): void
    {
        $white = new FashionProviderProductMapping(50, 'findmine', 'fm-50', 5001, 'fm-white-41', 'white');
        $black = new FashionProviderProductMapping(50, 'findmine', 'fm-50', 5002, 'fm-black-41', 'black');
        foreach ([$white, $black] as $mapping) {
            $this->repository->savePending($mapping);
            $this->repository->markSynced('findmine', 50, $mapping->shopVariantId, 'catalog-v2');
        }

        $this->assertSame('white', $this->repository->findSynced('findmine', 50, 5001)->providerColorId);
        $this->assertSame('black', $this->repository->findSynced('findmine', 50, 5002)->providerColorId);
        $this->assertNull($this->repository->findSynced('findmine', 50));
    }
}
