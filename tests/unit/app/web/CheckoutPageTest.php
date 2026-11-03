<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CheckoutPageTest extends TestCase
{
    private function shop(): PDO
    {
        $pdo = SqliteShop::pdo();
        SqliteShop::product($pdo, 7, 1, 'Áo thun', 150000, 3, '');
        SqliteShop::product($pdo, 8, 1, 'Quần', 200000, 3, '');
        $pdo->exec('INSERT INTO cart (id, user_id, product_id, quantity, size) VALUES (1, 5, 7, 2, "M"), (2, 5, 8, 1, "L")');
        return $pdo;
    }

    private function visit(PDO $pdo, array $session): PageResult
    {
        return (new CheckoutPage(new CartService($pdo)))->handle(new WebRequest('GET', [], [], []), $session);
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        $result = $this->visit($this->shop(), []);

        $this->assertSame('redirect', $result->kind());
        $this->assertSame('login.php', $result->payload());
    }

    public function testCartLinesAndTotal(): void
    {
        $data = $this->visit($this->shop(), ['user_id' => 5])->data();

        $this->assertFalse($data['empty']);
        $this->assertCount(2, $data['cart_items']);
        $this->assertEquals(500000, $data['total_money']);
        foreach (['name', 'price', 'quantity', 'subtotal'] as $key) {
            $this->assertArrayHasKey($key, $data['cart_items'][0]);
        }
    }

    public function testEmptyCartIsMarkedEmpty(): void
    {
        $data = $this->visit($this->shop(), ['user_id' => 99])->data();

        $this->assertTrue($data['empty']);
    }
}
