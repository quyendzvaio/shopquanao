<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OutfitServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SqliteShop::pdo();
        SqliteShop::sizeGuides($this->pdo);
        SqliteShop::product($this->pdo, 61, 1, 'Áo Dài Tay Cổ Tim', 250000, 10, 'congso,phongvan,basic');
        SqliteShop::product($this->pdo, 66, 2, 'Quần Tây Ống Đứng Xám', 540000, 11, 'congso,phongvan,dutiec', ['S']);
        SqliteShop::product($this->pdo, 88, 3, 'Chân Váy Chữ A Công Sở Vạt Xẻ', 430000, 8, 'congso,phongvan');
    }

    public function testToolResultCarriesOutfitsProductsAndResolvedSizes(): void
    {
        $result = (new OutfitService($this->pdo))->build([
            'style_tags' => ['phongvan', 'not-a-tag'],
            'budget' => 800000,
            'height' => 170,
            'weight' => 65,
        ]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame([1 => 'M', 2 => 'M', 3 => 'L'], $result['size_by_category']);
        // 66 only has size S, so the M-sized shopper gets the skirt set.
        $this->assertSame([61, 88], $result['outfits'][0]['product_ids']);
        $this->assertSame(680000, $result['outfits'][0]['total_price']);
        $this->assertSame(1, $result['products'][0]['outfit_index']);
        $this->assertSame(['phongvan'], $result['style_tags']);
    }

    public function testExplicitSizeOverridesMeasurementsAndReasonIsExposed(): void
    {
        $result = (new OutfitService($this->pdo))->build(['style_tags' => ['phongvan'], 'budget' => 300000, 'size' => 'm']);

        $this->assertSame('no_outfit', $result['status']);
        $this->assertSame(OutfitResult::BUDGET_TOO_LOW, $result['reason']);
        $this->assertSame(680000, $result['cheapest_total']);
        $this->assertSame([], $result['products']);
    }
}
