<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FreeShippingServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SqliteShop::pdo();
        SqliteShop::product($this->pdo, 53, 1, 'Áo Len Cổ Tròn Xám', 415000, 8, 'congso,phongvan,mualanh');
        SqliteShop::product($this->pdo, 58, 1, 'Áo Thun Graphic Phối Màu', 210000, 15, 'streetstyle,dichoi');
        SqliteShop::product($this->pdo, 99, 4, 'Cột Tóc Scrunchies Lụa', 45000, 20, 'dichoi,basic', []);
    }

    public function testGuestGetsThresholdAndLoginRequirement(): void
    {
        $result = (new FreeShippingService($this->pdo, new FreeShippingPolicy()))->suggest(null, []);

        $this->assertSame('requires_login', $result['status']);
        $this->assertSame(500000, $result['threshold']);
        $this->assertSame([], $result['products']);
    }

    public function testCartBelowThresholdGetsSuggestionsAndAStableFingerprint(): void
    {
        $this->pdo->exec("INSERT INTO cart (user_id, product_id, quantity, size) VALUES (7, 53, 1, 'M')");
        $service = new FreeShippingService($this->pdo, new FreeShippingPolicy());

        $first = $service->suggest(7, ['limit' => 1]);
        $second = $service->suggest(7, ['limit' => 1]);

        $this->assertSame('gap', $first['status']);
        $this->assertSame(85000, $first['gap']);
        $this->assertSame([58], array_column($first['products'], 'id'));
        $this->assertSame($first['cart_fingerprint'], $second['cart_fingerprint']);

        $this->pdo->exec('UPDATE cart SET quantity = 2 WHERE user_id = 7');
        $this->assertNotSame($first['cart_fingerprint'], $service->suggest(7, [])['cart_fingerprint']);
    }
}
