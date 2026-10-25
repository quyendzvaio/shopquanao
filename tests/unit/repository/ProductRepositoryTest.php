<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProductRepositoryTest extends TestCase
{
    private const CARD_KEYS = [
        'id', 'category_id', 'subcategory_id', 'name', 'price', 'stock', 'image', 'description',
        'category_name', 'category', 'subcategory', 'subcategory_name',
    ];

    public function testFindByIdReturnsTheRowOrNull(): void
    {
        $repository = new ProductRepository($this->catalog());

        $row = $repository->findById(2);

        $this->assertNotNull($row);
        $this->assertSame('Áo Sơ Mi Xanh', $row['name']);
        $this->assertSame(1, (int)$row['category_id']);
        $this->assertNull($repository->findById(999));
    }

    public function testSearchWithoutCriteriaReturnsEveryProductByPriceAscending(): void
    {
        $rows = (new ProductRepository($this->catalog()))->search(new ProductSearchCriteria());

        $this->assertSame([1, 2, 4, 3], $this->ids($rows));
        $this->assertSame(self::CARD_KEYS, array_keys($rows[0]));
        $this->assertSame('Áo', $rows[0]['category_name']);
        $this->assertSame('tops', $rows[0]['category']);
        $this->assertSame('tshirt', $rows[0]['subcategory']);
        $this->assertSame('Áo thun', $rows[0]['subcategory_name']);
    }

    public function testSearchByCategoryId(): void
    {
        $this->assertSame([4, 3], $this->search(new ProductSearchCriteria(categoryId: 2)));
    }

    public function testSearchBySubcategory(): void
    {
        $this->assertSame([1], $this->search(new ProductSearchCriteria(subcategory: 'tshirt')));
    }

    public function testSearchByPriceRange(): void
    {
        $this->assertSame([2, 4, 3], $this->search(new ProductSearchCriteria(minPrice: 250000.0)));
        $this->assertSame([1, 2], $this->search(new ProductSearchCriteria(maxPrice: 250000.0)));
        $this->assertSame([2, 4], $this->search(new ProductSearchCriteria(minPrice: 200000.0, maxPrice: 350000.0)));
    }

    public function testSearchInStockCountsActiveVariantStock(): void
    {
        // 2 has no product stock but an active variant in stock; 3 only has an inactive one.
        $this->assertSame([1, 2, 4], $this->search(new ProductSearchCriteria(inStock: true)));
    }

    public function testSearchBySizeMatchesActiveVariantsOrLegacySizes(): void
    {
        $this->assertSame([4], $this->search(new ProductSearchCriteria(size: 'XL')));
        $this->assertSame([2], $this->search(new ProductSearchCriteria(size: 'L')));
        $this->assertSame([1], $this->search(new ProductSearchCriteria(size: 'M')), 'inactive variant size of 3 ignored');
    }

    public function testSearchByColorKeepsProductsWithoutActiveVariants(): void
    {
        $this->assertSame([1, 2, 3], $this->search(new ProductSearchCriteria(color: 'blue')));
        $this->assertSame([1, 4, 3], $this->search(new ProductSearchCriteria(color: 'black')));
    }

    public function testCardsByIdsAndFreshPriceAndStock(): void
    {
        $repository = new ProductRepository($this->catalog());

        $this->assertSame([1, 3], $this->ids($repository->cardsByIds([3, 1])));
        $this->assertSame(self::CARD_KEYS, array_keys($repository->cardsByIds([1])[0]));
        $this->assertSame(['id' => 2, 'price' => 250000.0, 'stock' => 0], $repository->priceAndStock(2));
        $this->assertNull($repository->priceAndStock(999));
    }

    public function testDetailStaticHasTaxonomyButNoPriceOrStock(): void
    {
        $repository = new ProductRepository($this->catalog());

        $row = $repository->detailStatic(1);

        $this->assertSame(
            ['id', 'category_id', 'subcategory_id', 'name', 'description', 'image', 'category_name', 'category',
                'subcategory', 'subcategory_name'],
            array_keys($row)
        );
        $this->assertNull($repository->detailStatic(999));
    }

    public function testListPageFiltersSortsAndCountsBeforePaging(): void
    {
        $repository = new ProductRepository($this->catalog());
        $filter = new ProductListFilter(category: 1);

        $page = $repository->listPage($filter, 'price_desc', 1, 1);

        $this->assertSame(2, $page['total']);
        $this->assertSame([1], $this->ids($page['rows']));
        $this->assertSame([4, 3, 2, 1], $this->ids($repository->listPage(new ProductListFilter(), 'newest', null, 0)['rows']));
        $this->assertSame([3, 4], $this->ids($repository->listPage(new ProductListFilter(category: 'bottoms'), 'name_asc', null, 0)['rows']));
    }

    public function testListPageMatchesPhraseOrEveryToken(): void
    {
        $repository = new ProductRepository($this->catalog());

        $phrase = $repository->listPage(new ProductListFilter(likeTerm: '%Kaki%'), 'newest', null, 0);
        $tokens = $repository->listPage(
            new ProductListFilter(likeTerm: '%Quần Đen%', tokens: ['Quần', 'Đen']),
            'newest',
            null,
            0
        );

        $this->assertSame([4], $this->ids($phrase['rows']));
        $this->assertSame([4], $this->ids($tokens['rows']));
        $this->assertSame(1, $tokens['total']);
    }

    public function testAllForBackfillListsEveryProductById(): void
    {
        $rows = (new ProductRepository($this->catalog()))->allForBackfill();

        $this->assertSame([1, 2, 3, 4], $this->ids($rows));
        $this->assertSame(['id', 'name', 'description'], array_keys($rows[0]));
    }

    /** @return list<int> */
    private function search(ProductSearchCriteria $criteria): array
    {
        return $this->ids((new ProductRepository($this->catalog()))->search($criteria));
    }

    /** @return list<int> */
    private function ids(array $rows): array
    {
        return array_map(fn(array $row) => (int)$row['id'], $rows);
    }

    /** SqliteShop plus the taxonomy and variant tables the catalog queries join. */
    private function catalog(): PDO
    {
        $pdo = SqliteShop::pdo();
        $pdo->exec('ALTER TABLE products ADD COLUMN subcategory_id INTEGER');
        $pdo->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY, name TEXT, canonical_key TEXT, family TEXT)');
        $pdo->exec('CREATE TABLE product_subcategories (id INTEGER PRIMARY KEY, category_id INTEGER, canonical_key TEXT,
            display_name TEXT)');
        $pdo->exec('CREATE TABLE colors (id INTEGER PRIMARY KEY, canonical_key TEXT, display_name TEXT, external_code TEXT)');
        $pdo->exec('CREATE TABLE product_variants (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, variant_key TEXT,
            sku TEXT, color_id INTEGER, size TEXT, price REAL, stock INTEGER, is_active INTEGER)');
        $pdo->exec("INSERT INTO categories VALUES (1, 'Áo', 'tops', 'apparel'), (2, 'Quần', 'bottoms', 'apparel')");
        $pdo->exec("INSERT INTO product_subcategories VALUES (10, 1, 'tshirt', 'Áo thun')");
        $pdo->exec("INSERT INTO colors VALUES (1, 'blue', 'Xanh', NULL), (2, 'black', 'Đen', NULL)");

        SqliteShop::product($pdo, 1, 1, 'Áo Thun Trắng', 150000, 5, '', ['S', 'M']);
        SqliteShop::product($pdo, 2, 1, 'Áo Sơ Mi Xanh', 250000, 0, '', ['L']);
        SqliteShop::product($pdo, 3, 2, 'Quần Jeans', 400000, 0, '', []);
        SqliteShop::product($pdo, 4, 2, 'Quần Kaki Đen', 300000, 2, '', []);
        $pdo->exec('UPDATE products SET subcategory_id = 10 WHERE id = 1');

        $variant = $pdo->prepare('INSERT INTO product_variants (product_id, variant_key, color_id, size, stock, is_active)
            VALUES (?, ?, ?, ?, ?, ?)');
        $variant->execute([2, 'blue-l', 1, 'L', 3, 1]);
        $variant->execute([3, 'blue-m', 2, 'M', 5, 0]);
        $variant->execute([4, 'black-xl', 2, 'xl', null, 1]);
        return $pdo;
    }
}
