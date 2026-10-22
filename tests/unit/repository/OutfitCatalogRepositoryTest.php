<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OutfitCatalogRepositoryTest extends TestCase
{
    public function testLoadsSlottedItemsWithTagsSizesAndPalette(): void
    {
        $pdo = SqliteShop::pdo();
        SqliteShop::product($pdo, 61, 1, 'Áo Dài Tay Cổ Tim', 250000, 10, 'congso,phongvan,basic');
        SqliteShop::product($pdo, 82, 3, 'Chân Váy Pleat xếp ly Midi', 470000, 9, 'congso,phongvan,hendo', ['M', 'L']);
        SqliteShop::product($pdo, 54, 1, 'Áo Polo Thể Thao Cao Cấp Đỏ', 290000, 3, 'thethao,bogus');
        SqliteShop::product($pdo, 99, 4, 'Cột Tóc Scrunchies Lụa', 45000, 20, 'dichoi,basic', []);
        SqliteShop::product($pdo, 120, 5, 'Giày', 500000, 1, 'dichoi');

        $items = [];
        foreach ((new OutfitCatalogRepository($pdo))->all() as $item) $items[$item->id] = $item;

        $this->assertSame([54, 61, 82, 99], array_keys($items));
        $this->assertSame(OutfitSlot::BOTTOM, $items[82]->slot);
        $this->assertSame(['L', 'M'], $items[82]->sizes);
        $this->assertSame(['thethao'], $items[54]->tags);
        $this->assertTrue($items[54]->statement);
        $this->assertFalse($items[61]->statement);
        $this->assertSame([], $items[99]->sizes);
        $this->assertSame(250000, $items[61]->price);
    }

    public function testMergesActiveInStockVariantSizesAndIgnoresOthers(): void
    {
        $pdo = SqliteShop::pdo();
        $pdo->exec('CREATE TABLE product_variants (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, size TEXT,
            stock INTEGER, is_active INTEGER)');
        SqliteShop::product($pdo, 61, 1, 'Áo Dài Tay Cổ Tim', 250000, 10, 'congso', ['M']);
        $insert = $pdo->prepare('INSERT INTO product_variants (product_id, size, stock, is_active) VALUES (?, ?, ?, ?)');
        $insert->execute([61, 'xl', 4, 1]);
        $insert->execute([61, 'S', 0, 1]);
        $insert->execute([61, 'L', 5, 0]);
        $insert->execute([61, 'M', 2, 1]);

        $items = (new OutfitCatalogRepository($pdo))->all();

        $this->assertSame(['M', 'XL'], $items[0]->sizes);
    }
}
