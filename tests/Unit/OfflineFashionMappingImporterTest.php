<?php

final class OfflineFashionMappingImporterTest extends \PHPUnit\Framework\TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY)');
        $this->pdo->exec('INSERT INTO products (id) VALUES (50), (51)');
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
    }

    public function testOfflineImportIsIdempotentAndMarksVerifiedMappingsSynced(): void
    {
        $repository = new FashionProviderMappingRepository($this->pdo);
        $importer = new OfflineFashionMappingImporter($this->pdo, $repository);
        $rows = [[
            'shop_product_id' => 50,
            'provider_product_id' => 'fm-50',
            'provider_color_id' => 'white',
            'provider_identifiers_json' => '{"product_id":"fm-50","product_color_id":"white","product_pattern":"solid"}',
        ]];

        $first = $importer->import($rows, 'findmine', 'feed-2026-08-24');
        $second = $importer->import($rows, 'findmine', 'feed-2026-08-24');

        $this->assertSame(1, $first['synced']);
        $this->assertSame(1, $second['synced']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM fashion_provider_product_mapping')->fetchColumn());
        $this->assertSame('white', $repository->findSynced('findmine', 50)->providerColorId);
        $this->assertSame('solid', $repository->findSynced('findmine', 50)->providerIdentifiers['product_pattern']);
    }

    public function testImportContinuesAfterIndependentInvalidRows(): void
    {
        $importer = new OfflineFashionMappingImporter(
            $this->pdo,
            new FashionProviderMappingRepository($this->pdo)
        );
        $report = $importer->import([
            ['shop_product_id' => 999, 'provider_product_id' => 'missing-shop-product'],
            ['shop_product_id' => 51, 'provider_product_id' => 'fm-51'],
        ], 'findmine', 'feed-v1');

        $this->assertSame(2, $report['processed']);
        $this->assertSame(1, $report['failed']);
        $this->assertSame(1, $report['synced']);
        $this->assertSame(999, $report['failures'][0]['shop_product_id']);
    }
}
