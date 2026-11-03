<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OrderRepositoryTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SqliteShop::pdo();
        $this->pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, total_price REAL,
            status TEXT, created_at TEXT)');
        $this->pdo->exec('CREATE TABLE order_items (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER,
            product_id INTEGER, quantity INTEGER, price REAL)');
        SqliteShop::product($this->pdo, 7, 1, 'Áo', 100000, 5, '');
    }

    public function testCheckoutReadsCartLinesWithPriceAndStock(): void
    {
        (new CartRepository($this->pdo))->insertLineWithSize(1, 7, 2, 'M');

        $lines = (new OrderRepository($this->pdo))->cartLinesForCheckout(1, false);

        $this->assertCount(1, $lines);
        $this->assertSame(['product_id', 'quantity', 'price', 'stock'], array_keys($lines[0]));
        $this->assertSame(2, (int)$lines[0]['quantity']);
        $this->assertSame([], (new OrderRepository($this->pdo))->cartLinesForCheckout(2, false));
    }

    public function testInsertOrderItemsAndReads(): void
    {
        $repository = new OrderRepository($this->pdo);

        $orderId = $repository->insertOrder(1, 250000.0);
        $repository->insertItem($orderId, 7, 2, 125000.0);

        $order = $repository->findForUser($orderId, 1);
        $this->assertNotNull($order);
        $this->assertSame('Chờ xử lý', $order['status']);
        $this->assertNull($repository->findForUser($orderId, 2));
        $items = $repository->itemsForOrder($orderId);
        $this->assertSame(['id', 'product_id', 'quantity', 'price', 'name', 'image'], array_keys($items[0]));
        $this->assertSame('Áo', $items[0]['name']);
        $this->assertSame(1, $repository->count());
        $this->assertCount(1, $repository->listForUser(1));
        $this->assertCount(0, $repository->listForUser(1, 'Đã hủy'));
        $this->assertCount(1, $repository->listForUser(1, 'all'));
    }

    public function testStatusUpdateStatsAndRevenue(): void
    {
        $repository = new OrderRepository($this->pdo);
        $orderId = $repository->insertOrder(1, 250000.0);
        $this->assertSame(0.0, $repository->completedRevenue());
        $this->assertEquals(['total_spent' => null, 'completed_count' => 0], $repository->completedStats(1));

        $this->assertSame(1, $repository->updateStatus($orderId, 'Đã hoàn thành'));
        $this->assertSame(0, $repository->updateStatus(999, 'Đã hủy'));

        $this->assertSame(250000.0, $repository->completedRevenue());
        $this->assertEquals(['total_spent' => 250000, 'completed_count' => 1], $repository->completedStats(1));
    }

    public function testRecentPurchasesAreTheUsersLatestLines(): void
    {
        $repository = new OrderRepository($this->pdo);
        $orderId = $repository->insertOrder(1, 100000.0);
        $repository->insertItem($orderId, 7, 1, 100000.0);

        $rows = $repository->recentPurchases(1);

        $this->assertSame(['date', 'product'], array_keys($rows[0]));
        $this->assertSame('Áo', $rows[0]['product']);
        $this->assertSame([], $repository->recentPurchases(2));
    }
}
