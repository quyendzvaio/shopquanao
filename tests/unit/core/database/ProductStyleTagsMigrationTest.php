<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Every seeded product gets 1-3 tags from the closed vocabulary. */
final class ProductStyleTagsMigrationTest extends TestCase
{
    private const FILE = ROOT_DIR . '/database/migrations/2026_10_15_product_style_tags.sql';

    public function testAddsColumnAndTagsEverySeededProduct(): void
    {
        $sql = (string)file_get_contents(self::FILE);
        $this->assertMatchesRegularExpression('/ALTER TABLE products ADD COLUMN style_tags VARCHAR\(100\) NOT NULL DEFAULT \'\'/', $sql);

        preg_match_all("/UPDATE products SET style_tags = '([a-z,]+)' WHERE id = (\d+);/", $sql, $m, PREG_SET_ORDER);
        $ids = array_map(fn($row) => (int)$row[2], $m);
        $this->assertSame(range(50, 99), $ids);

        foreach ($m as [, $csv, $id]) {
            $tags = explode(',', $csv);
            $this->assertSame(StyleTag::parseList($csv), $tags, "product $id has an invalid or duplicate tag");
            $this->assertGreaterThanOrEqual(1, count($tags), "product $id");
            $this->assertLessThanOrEqual(StyleTag::MAX_PER_PRODUCT, count($tags), "product $id");
        }
    }

    public function testEveryTagIsUsedAtLeastOnce(): void
    {
        $sql = (string)file_get_contents(self::FILE);
        foreach (StyleTag::ALL as $tag) {
            $this->assertMatchesRegularExpression("/'[a-z,]*\b$tag\b[a-z,]*'/", $sql, "unused tag $tag");
        }
    }
}
