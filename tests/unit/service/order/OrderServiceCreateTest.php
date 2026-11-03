<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderServiceCreateTest extends TestCase
{
    private function shop(int $quantity): PDO
    {
        $pdo = SqliteShop::pdo();
        $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, total_price REAL,
            status TEXT, created_at TEXT)');
        $pdo->exec('CREATE TABLE order_items (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER,
            product_id INTEGER, quantity INTEGER, price REAL)');
        SqliteShop::product($pdo, 7, 1, 'Áo thun', 150000, 5, '');
        SqliteShop::product($pdo, 8, 1, 'Quần', 200000, 5, '');
        $pdo->exec("INSERT INTO cart (user_id, product_id, quantity, size) VALUES (5, 8, 1, 'S'), (5, 7, {$quantity}, 'M')");

        return $pdo;
    }

    private function rows(PDO $pdo, string $table): int
    {
        return (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    /** @return array<string,array{0:int}> */
    public static function nonPositive(): array
    {
        return ['negative' => [-1], 'zero' => [0]];
    }

    #[DataProvider('nonPositive')]
    public function testALineWithAQuantityBelowOneIsRefusedAndNothingIsWritten(int $quantity): void
    {
        $pdo = $this->shop($quantity);

        try {
            (new OrderService($pdo))->create(5);
            $this->fail('a cart line with quantity ' . $quantity . ' must be refused');
        } catch (RuntimeException $error) {
            $this->assertSame('Invalid quantity for product 7', $error->getMessage());
        }

        $this->assertFalse($pdo->inTransaction());
        $this->assertSame(0, $this->rows($pdo, 'orders'));
        $this->assertSame(0, $this->rows($pdo, 'order_items'));
        $this->assertSame(2, $this->rows($pdo, 'cart'));
        $this->assertSame([5, 5], array_map('intval', $pdo->query('SELECT stock FROM products ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function testAValidCartStillPlacesTheOrder(): void
    {
        $pdo = $this->shop(2);

        $result = (new OrderService($pdo))->create(5);

        $this->assertSame(500000.0, $result['total_price']);
        $this->assertSame(1, $this->rows($pdo, 'orders'));
        $this->assertSame(3, (int) $pdo->query('SELECT stock FROM products WHERE id = 7')->fetchColumn());
        $this->assertSame(0, $this->rows($pdo, 'cart'));
    }
}
