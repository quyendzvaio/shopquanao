<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Live catalog color search (requires MariaDB). Verifies the data + SQL layer:
 * black must return the black-coded products, purple must exclude the
 * mislabeled "Cổ Tim" neckline product.
 */
final class CatalogColorSearchTest extends TestCase
{
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        $host = (string)(getenv('DB_HOST') ?: '');
        if ($host === '') {
            $this->markTestSkipped('DB_HOST not set; live catalog test skipped');
        }
        require_once ROOT_DIR . '/api/controllers/chatbot/ToolRegistry.php';
        $this->pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, getenv('DB_NAME') ?: 'shop_db'),
            (string)(getenv('DB_USER') ?: 'shop_user'),
            (string)(getenv('DB_PASS') ?: 'shop_pass'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    public function testBlackSearchReturnsBlackCodedProducts(): void
    {
        $registry = new ToolRegistry($this->pdo, null);
        $result = $registry->execute('search_products', [
            'search' => 'áo',
            'category_id' => 1,
            'color' => 'đen',
            'in_stock' => true,
        ]);
        $ids = array_column($result['products'] ?? [], 'id');
        $this->assertContains(52, $ids, 'bomber kaki đen missing from black search');
        $this->assertContains(63, $ids, 'sơ mi caro đỏ đen missing from black search');
    }

    public function testPurpleSearchExcludesNecklineProduct(): void
    {
        $registry = new ToolRegistry($this->pdo, null);
        $result = $registry->execute('search_products', [
            'search' => 'áo',
            'category_id' => 1,
            'color' => 'tím',
            'in_stock' => true,
        ]);
        $ids = array_column($result['products'] ?? [], 'id');
        $this->assertNotContains(61, $ids, 'cổ tim neckline must not match purple');
    }
}
