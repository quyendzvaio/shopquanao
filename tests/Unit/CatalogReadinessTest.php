<?php

final class CatalogReadinessTest extends \PHPUnit\Framework\TestCase
{
    private PDO $pdo;
    private ToolRegistry $products;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->createSchema();
        $this->seedCatalog();
        $this->products = new ToolRegistry($this->pdo);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('footwearAliases')]
    public function testFootwearAliasesNormalizeToCanonicalSubcategories(string $input, string $expected): void
    {
        $this->assertSame($expected, CatalogTaxonomy::normalizeFootwearSubcategory($input));
    }

    public static function footwearAliases(): array
    {
        return [
            ['sneaker', 'sneakers'], ['trainers', 'sneakers'],
            ['Oxford shoes', 'dress_shoes'], ['Derby shoes', 'dress_shoes'],
            ['giày tây', 'dress_shoes'], ['loafer', 'loafers'], ['boots', 'boots'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('colorAliases')]
    public function testCanonicalColorAliases(string $input, string $expected): void
    {
        $this->assertSame($expected, ProductAttributeNormalizer::normalizeCanonicalColor($input));
    }

    public function testVietnameseWordDoDoesNotFabricateRedColor(): void
    {
        $colors = ProductAttributeNormalizer::extractCanonicalColorsFromProduct([
            'name' => 'Quần Jeans Xanh Đậm',
            'description' => 'Dễ phối đồ và giữ màu lâu.',
        ]);

        $this->assertSame(['navy'], $colors);
    }

    public static function colorAliases(): array
    {
        return [
            ['đen', 'black'], ['trắng', 'white'], ['xám', 'gray'], ['ghi', 'gray'],
            ['xanh navy', 'navy'], ['xanh đen', 'navy'], ['be', 'beige'], ['kem', 'cream'],
        ];
    }

    public function testProductWithMultipleColorsAndSizesIsHydratedWithoutNPlusOneShapeChanges(): void
    {
        $result = $this->products->execute('get_product_detail', ['product_id' => 201]);
        $product = $result['product'];

        $this->assertSame(201, $product['id']);
        $this->assertSame(650000.0, $product['price']);
        $this->assertSame(5, $product['stock']);
        $this->assertSame(['white', 'black'], $product['canonical_colors']);
        $this->assertCount(3, $product['variants']);
        $this->assertContains('41', $product['available_sizes']);
        $this->assertContains('42', $product['available_sizes']);
        $this->assertTrue($product['variants'][0]['inherits_price']);
    }

    public function testProductSearchByFootwearCategoryColorAndSubcategory(): void
    {
        $result = $this->products->execute('search_products', [
            'search' => 'white sneakers',
            'category' => 'footwear',
            'subcategory' => 'sneakers',
            'color' => 'white',
            'in_stock' => true,
        ]);

        $this->assertCount(1, $result['products']);
        $this->assertSame(201, $result['products'][0]['id']);
        $this->assertSame('footwear', $result['products'][0]['category']);
        $this->assertSame('sneakers', $result['products'][0]['subcategory']);
        $this->assertContains('white', $result['products'][0]['canonical_colors']);
    }

    public function testDressShoeSearchDoesNotReturnSneakers(): void
    {
        $result = $this->products->execute('search_products', [
            'search' => 'Oxford shoes',
            'color' => 'black',
        ]);

        $this->assertCount(1, $result['products']);
        $this->assertSame(202, $result['products'][0]['id']);
        $this->assertSame('dress_shoes', $result['products'][0]['subcategory']);
    }

    public function testExistingApparelSearchAndVariantlessProductRemainUsable(): void
    {
        $result = $this->products->execute('search_products', [
            'search' => 'áo thun',
            'category_id' => 1,
            'color' => 'white',
            'in_stock' => true,
        ]);

        $this->assertCount(1, $result['products']);
        $product = $result['products'][0];
        $this->assertSame(50, $product['id']);
        $this->assertSame([], $product['variants']);
        $this->assertArrayHasKey('available_sizes', $product);
        $this->assertArrayHasKey('available_colors', $product);
    }

    public function testBackfillCreatesInheritedVariantsWithoutInventingStockOrSku(): void
    {
        $report = (new CatalogVariantBackfill($this->pdo))->run();
        $second = (new CatalogVariantBackfill($this->pdo))->run();

        $this->assertSame(4, $report['products_processed']);
        $this->assertGreaterThanOrEqual(2, $report['variants_created']);
        $this->assertSame(0, $second['variants_created']);
        $row = $this->pdo->query("SELECT sku, stock, price FROM product_variants WHERE product_id = 50 LIMIT 1")
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertNull($row['sku']);
        $this->assertNull($row['stock']);
        $this->assertNull($row['price']);
    }

    public function testChatbotCardKeepsLegacyFieldsAndAddsVariants(): void
    {
        $result = $this->products->execute('search_products', ['search' => 'giày sneaker']);
        $normalized = (new EvidenceNormalizer())->normalize([], [
            'results' => [[
                'tool' => 'search_products', 'result' => $result, 'success' => true, 'duration_ms' => 1,
            ]],
        ]);
        $card = $normalized['cards'][0];

        foreach (['id', 'name', 'price', 'stock', 'available_sizes', 'available_colors'] as $legacyField) {
            $this->assertArrayHasKey($legacyField, $card);
        }
        $this->assertArrayHasKey('variants', $card);
        $this->assertArrayHasKey('canonical_colors', $card);
        $this->assertArrayNotHasKey('provider_product_id', $card);
    }

    private function createSchema(): void
    {
        $this->pdo->exec("CREATE TABLE categories (
            id INTEGER PRIMARY KEY, name TEXT NOT NULL, canonical_key TEXT NOT NULL UNIQUE, family TEXT NOT NULL
        )");
        $this->pdo->exec("CREATE TABLE product_subcategories (
            id INTEGER PRIMARY KEY, category_id INTEGER NOT NULL, canonical_key TEXT NOT NULL, display_name TEXT NOT NULL
        )");
        $this->pdo->exec("CREATE TABLE products (
            id INTEGER PRIMARY KEY, category_id INTEGER, subcategory_id INTEGER, name TEXT NOT NULL,
            price REAL NOT NULL, stock INTEGER NOT NULL, description TEXT, image TEXT
        )");
        $this->pdo->exec("CREATE TABLE colors (
            id INTEGER PRIMARY KEY, canonical_key TEXT NOT NULL UNIQUE, display_name TEXT NOT NULL,
            external_code TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $this->pdo->exec("CREATE TABLE product_variants (
            id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL, variant_key TEXT NOT NULL,
            sku TEXT UNIQUE, color_id INTEGER, size TEXT, price REAL, stock INTEGER,
            is_active INTEGER NOT NULL DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE(product_id, variant_key)
        )");
        $this->pdo->exec("CREATE TABLE product_sizes (
            id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, size_name TEXT
        )");
    }

    private function seedCatalog(): void
    {
        $this->pdo->exec("INSERT INTO categories VALUES
            (1, 'Áo', 'tops', 'apparel'), (2, 'Quần', 'bottoms', 'apparel'),
            (5, 'Giày dép', 'footwear', 'footwear')");
        $this->pdo->exec("INSERT INTO product_subcategories VALUES
            (501, 5, 'sneakers', 'Giày sneaker'), (502, 5, 'dress_shoes', 'Giày tây')");
        $this->pdo->exec("INSERT INTO colors (id, canonical_key, display_name) VALUES
            (1, 'black', 'Đen'), (2, 'white', 'Trắng'), (7, 'beige', 'Be')");
        $this->pdo->exec("INSERT INTO products VALUES
            (50, 1, NULL, 'Áo Thun Cotton Basic Trắng', 180000, 10, 'Cotton trắng', NULL),
            (201, 5, 501, 'Giày Sneaker Cloud', 650000, 5, 'Sneaker tối giản', NULL),
            (202, 5, 502, 'Giày Tây Oxford Classic', 890000, 3, 'Oxford công sở', NULL),
            (203, 2, NULL, 'Quần Không Rõ Màu', 400000, 1, 'Không có dữ liệu màu', NULL)");
        $this->pdo->exec("INSERT INTO product_sizes (product_id, size_name) VALUES (50, 'M'), (50, 'L')");
        $this->pdo->exec("INSERT INTO product_variants
            (product_id, variant_key, sku, color_id, size, price, stock, is_active) VALUES
            (201, 'white-41', 'SN-CLOUD-W-41', 2, '41', NULL, 2, 1),
            (201, 'white-42', 'SN-CLOUD-W-42', 2, '42', NULL, 1, 1),
            (201, 'black-42', 'SN-CLOUD-B-42', 1, '42', NULL, 2, 1),
            (202, 'black-41', 'OXFORD-B-41', 1, '41', 920000, 3, 1)");
    }
}
