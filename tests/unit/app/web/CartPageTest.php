<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CartPageTest extends TestCase
{
    private function shop(): PDO
    {
        $pdo = SqliteShop::pdo();
        SqliteShop::product($pdo, 7, 1, 'Áo thun', 150000, 3, '');
        SqliteShop::product($pdo, 8, 1, 'Quần', 200000, 3, '');
        $pdo->exec("INSERT INTO cart (id, user_id, product_id, quantity, size) VALUES (1, 5, 7, 2, 'M'), (2, 5, 8, 1, 'L'), (3, 6, 7, 1, 'S')");
        return $pdo;
    }

    private function page(PDO $pdo): CartPage
    {
        return new CartPage(new CartService($pdo));
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        $session = [];

        $result = $this->page($this->shop())->handle(new WebRequest('GET', [], [], []), $session);

        $this->assertSame('redirect', $result->kind());
        $this->assertSame('login.php?error=vui_long_dang_nhap', $result->payload());
    }

    public function testListProvidesTheUsersCartLines(): void
    {
        $session = ['user_id' => 5];

        $result = $this->page($this->shop())->handle(new WebRequest('GET', [], [], []), $session);

        $this->assertTrue($result->isView());
        $items = $result->data()['cart_items'];
        $this->assertCount(2, $items);
        foreach (['cart_id', 'product_id', 'name', 'price', 'image', 'quantity', 'size', 'subtotal'] as $key) {
            $this->assertArrayHasKey($key, $items[0]);
        }
        $this->assertSame('Áo thun', $items[0]['name']);
    }

    public function testRemoveDeletesOnlyTheUsersLineAndRedirectsToTheCart(): void
    {
        $pdo = $this->shop();
        $session = ['user_id' => 5];

        $result = $this->page($pdo)->handle(new WebRequest('GET', ['remove' => '1'], [], []), $session);

        $this->assertSame('redirect', $result->kind());
        $this->assertSame('cart.php', $result->payload());
        $this->assertSame(['2', '3'], array_map('strval', $pdo->query('SELECT id FROM cart ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function testRemovingAnotherUsersLineChangesNothing(): void
    {
        $pdo = $this->shop();
        $session = ['user_id' => 5];

        $this->page($pdo)->handle(new WebRequest('GET', ['remove' => '3'], [], []), $session);

        $this->assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM cart')->fetchColumn());
    }

    public function testGuestRemoveStillRedirectsToLoginAndDeletesNothing(): void
    {
        $pdo = $this->shop();
        $session = [];

        $result = $this->page($pdo)->handle(new WebRequest('GET', ['remove' => '1'], [], []), $session);

        $this->assertSame('login.php?error=vui_long_dang_nhap', $result->payload());
        $this->assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM cart')->fetchColumn());
    }
}
