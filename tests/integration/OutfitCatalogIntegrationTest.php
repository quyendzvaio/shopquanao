<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** UC-A against the real schema + migrations (shop_test). */
final class OutfitCatalogIntegrationTest extends TestCase
{
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        $host = (string)(getenv('DB_HOST') ?: '');
        if ($host === '') {
            $this->markTestSkipped('DB_HOST not set; live catalog test skipped');
        }
        $this->pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, getenv('DB_NAME') ?: 'shop_db'),
            (string)(getenv('DB_USER') ?: 'shop_user'),
            (string)(getenv('DB_PASS') ?: 'shop_pass'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    public function testEverySeededProductIsTagged(): void
    {
        $items = (new OutfitCatalogRepository($this->pdo))->all();
        $this->assertCount(50, $items);
        foreach ($items as $item) {
            $this->assertNotSame([], $item->tags, "product {$item->id} has no style tags");
        }
    }

    public function testInterviewDemoUsesTheBudget(): void
    {
        $result = (new OutfitService($this->pdo))->build(['style_tags' => ['phongvan'], 'budget' => 800000, 'height' => 170, 'weight' => 65]);

        $this->assertSame([61, 66], $result['outfits'][0]['product_ids']);
        $this->assertSame(790000, $result['outfits'][0]['total_price']);
    }
}
