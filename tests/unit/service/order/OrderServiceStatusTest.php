<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OrderServiceStatusTest extends TestCase
{
    private function pdo(): PDO
    {
        $pdo = SqliteShop::pdo();
        $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER, total_price REAL, status TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE order_items (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, product_id INTEGER,
            quantity INTEGER, price REAL)');
        return $pdo;
    }

    public function testAnUnknownOrderIsAnAnswerNotAnError(): void
    {
        $result = (new OrderService($this->pdo()))->status(5, ['order_id' => 99]);

        $this->assertSame([], $result['orders']);
        $this->assertSame('Không tìm thấy đơn hàng này trong tài khoản của bạn.', $result['message']);
    }

    public function testADatabaseErrorIsNotReportedAsAMissingOrder(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('DROP TABLE orders');

        $this->expectException(PDOException::class);
        (new OrderService($pdo))->status(5, ['order_id' => 3]);
    }

    public function testCheckoutOfAnEmptyCartThrowsTheTypedException(): void
    {
        $this->expectException(EmptyCartException::class);
        $this->expectExceptionMessage('Cart is empty');
        (new OrderService($this->pdo()))->create(5);
    }
}
