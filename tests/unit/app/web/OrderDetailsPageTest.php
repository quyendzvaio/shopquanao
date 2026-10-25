<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OrderDetailsPageTest extends TestCase
{
    private function shop(): PDO
    {
        $pdo = SqliteShop::pdo();
        SqliteShop::product($pdo, 7, 1, 'Áo thun', 150000, 3, '');
        $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER, total_price REAL, status TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE order_items (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, product_id INTEGER,
            quantity INTEGER, price REAL)');
        $pdo->exec("INSERT INTO orders (id, user_id, total_price, status) VALUES (3, 5, 300000, 'pending')");
        $pdo->exec('INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (3, 7, 2, 150000)');
        return $pdo;
    }

    private function visit(array $query, array $session): PageResult
    {
        return (new OrderDetailsPage(new OrderService($this->shop())))->handle(new WebRequest('GET', $query, [], []), $session);
    }

    public function testGuestOrMissingIdRedirectsToLogin(): void
    {
        $this->assertSame('login.php', $this->visit(['id' => '3'], [])->payload());
        $this->assertSame('login.php', $this->visit([], ['user_id' => 5])->payload());
    }

    public function testOwnOrderShowsItsItems(): void
    {
        $result = $this->visit(['id' => '3'], ['user_id' => 5]);

        $this->assertTrue($result->isView());
        $data = $result->data();
        $this->assertSame(3, $data['order_id']);
        $this->assertSame('pending', $data['order']['status']);
        $this->assertEquals(300000, $data['order']['total_price']);
        $this->assertSame('Áo thun', $data['items'][0]['name']);
        $this->assertEquals(2, $data['items'][0]['quantity']);
    }

    public function testForeignOrMissingOrderHalts(): void
    {
        foreach ([['id' => '3', 'user' => 6], ['id' => '99', 'user' => 5]] as $case) {
            $result = $this->visit(['id' => $case['id']], ['user_id' => $case['user']]);

            $this->assertSame('halt', $result->kind());
            $this->assertSame('Đơn hàng không tồn tại hoặc bạn không có quyền xem.', $result->payload());
        }
    }
}
