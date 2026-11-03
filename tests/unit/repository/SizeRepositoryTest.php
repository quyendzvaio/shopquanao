<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SizeRepositoryTest extends TestCase
{
    public function testSizesForProductReturnsItsRowsInTheRequestedFetchMode(): void
    {
        $pdo = SqliteShop::pdo();
        SqliteShop::product($pdo, 7, 1, 'Áo', 100000, 1, '', ['S', 'M']);
        SqliteShop::product($pdo, 8, 1, 'Áo khác', 100000, 1, '', ['XL']);
        $repository = new SizeRepository($pdo);

        $assoc = $repository->sizesForProduct(7);
        $both = $repository->sizesForProduct(7, PDO::FETCH_BOTH);

        $this->assertSame([['id' => 1, 'product_id' => 7, 'size_name' => 'S'], ['id' => 2, 'product_id' => 7, 'size_name' => 'M']], $assoc);
        $this->assertSame(['id' => 1, 0 => 1, 'product_id' => 7, 1 => 7, 'size_name' => 'S', 2 => 'S'], $both[0]);
        $this->assertSame([], $repository->sizesForProduct(99));
    }

    public function testSizesForProductsAndAllProductSizes(): void
    {
        $pdo = SqliteShop::pdo();
        SqliteShop::product($pdo, 8, 1, 'Áo khác', 100000, 1, '', ['XL']);
        SqliteShop::product($pdo, 7, 1, 'Áo', 100000, 1, '', ['S', 'M']);
        SqliteShop::product($pdo, 9, 1, 'Áo nữa', 100000, 1, '', ['L']);
        $repository = new SizeRepository($pdo);

        $some = $repository->sizesForProducts([7, 8]);
        $all = $repository->allProductSizes();

        $this->assertEqualsCanonicalizing(
            [['product_id' => 8, 'size_name' => 'XL'], ['product_id' => 7, 'size_name' => 'S'], ['product_id' => 7, 'size_name' => 'M']],
            $some
        );
        $this->assertSame(
            [[7, 'S'], [7, 'M'], [8, 'XL'], [9, 'L']],
            array_map(fn(array $row) => [(int)$row['product_id'], $row['size_name']], $all)
        );
    }

    public function testSuggestionGuidesOrderBySizeOnSqlite(): void
    {
        $pdo = SqliteShop::pdo();
        SqliteShop::sizeGuides($pdo);
        $pdo->exec("INSERT INTO size_guides (category_id, size_name) VALUES (1, 'XL'), (1, 'XS')");
        $repository = new SizeRepository($pdo);

        $category = $repository->suggestionGuidesForCategory(1);
        $all = $repository->allSuggestionGuides();

        $this->assertSame(['S', 'M', 'L', 'XL', 'XS'], array_column($category, 'size_name'));
        $this->assertSame([1, 1, 1, 1, 1, 2, 2, 2, 3, 3], array_map('intval', array_column($all, 'category_id')));
        $this->assertSame(['S', 'M', 'L', 'XL', 'XS', 'S', 'M', 'L', 'M', 'L'], array_column($all, 'size_name'));
    }
}
