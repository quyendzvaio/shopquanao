<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization of OrderService against the live MariaDB schema. Pins today's behaviour so
 * that moving its SQL into repositories can be proven neutral. Leaves the DB as it found it.
 */
final class OrderServiceTest extends TestCase
{
    private PDO $pdo;
    private OrderService $orders;
    /** @var list<int> */
    private array $userIds = [];
    /** @var array<int,int> product id => original stock */
    private array $originalStock = [];

    protected function setUp(): void
    {
        $host = (string)(getenv('DB_HOST') ?: '');
        if ($host === '') {
            $this->markTestSkipped('DB_HOST not set; live service test skipped');
        }
        $this->pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, getenv('DB_NAME') ?: 'shop_db'),
            (string)(getenv('DB_USER') ?: 'shop_user'),
            (string)(getenv('DB_PASS') ?: 'shop_pass'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->orders = new OrderService($this->pdo);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        foreach ($this->userIds as $userId) {
            $this->pdo->prepare('DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE user_id = ?)')->execute([$userId]);
            $this->pdo->prepare('DELETE FROM orders WHERE user_id = ?')->execute([$userId]);
            $this->pdo->prepare('DELETE FROM cart WHERE user_id = ?')->execute([$userId]);
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        }
        foreach ($this->originalStock as $productId => $stock) {
            $this->pdo->prepare('UPDATE products SET stock = ? WHERE id = ?')->execute([$stock, $productId]);
        }
    }

    public function testCreateDecrementsStockWritesPendingStatusAndClearsCart(): void
    {
        $product = $this->product(10);
        $userId = $this->newUser();
        $this->addToCart($userId, $product['id'], 2);

        $result = $this->orders->create($userId);

        $this->assertSame('Order placed successfully', $result['message']);
        $this->assertSame(round(2 * $product['price'], 2), $result['total_price']);
        $orderId = $result['order_id'];

        $row = $this->pdo->query('SELECT user_id, status, total_price FROM orders WHERE id = ' . $orderId)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($userId, (int)$row['user_id']);
        $this->assertSame('Chờ xử lý', $row['status']);
        $this->assertSame(round(2 * $product['price'], 2), (float)$row['total_price']);

        $items = $this->pdo->query('SELECT product_id, quantity, price FROM order_items WHERE order_id = ' . $orderId)->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $items);
        $this->assertSame($product['id'], (int)$items[0]['product_id']);
        $this->assertSame(2, (int)$items[0]['quantity']);
        $this->assertSame($product['price'], (float)$items[0]['price']);

        $this->assertSame($product['stock'] - 2, $this->stock($product['id']));
        $this->assertSame(0, $this->cartCount($userId));
    }

    public function testCreateRefusesQuantityAboveStock(): void
    {
        $product = $this->product(10);
        $this->setStock($product['id'], 3);
        $userId = $this->newUser();
        $this->addToCart($userId, $product['id'], 4);

        try {
            $this->orders->create($userId);
            $this->fail('expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('Insufficient stock for product ' . $product['id'], $e->getMessage());
        }

        $this->assertSame(3, $this->stock($product['id']));
        $this->assertSame(1, $this->cartCount($userId));
        $this->assertSame(0, $this->orderCount($userId));
    }

    public function testCreateOnEmptyCartThrows(): void
    {
        $userId = $this->newUser();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cart is empty');
        $this->orders->create($userId);
    }

    public function testSecondOrderForLastUnitFails(): void
    {
        $product = $this->product(10);
        $this->setStock($product['id'], 1);
        $first = $this->newUser();
        $second = $this->newUser();
        $this->addToCart($first, $product['id'], 1);
        $this->addToCart($second, $product['id'], 1);

        $this->assertSame('Order placed successfully', $this->orders->create($first)['message']);
        try {
            $this->orders->create($second);
            $this->fail('expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('Insufficient stock for product ' . $product['id'], $e->getMessage());
        }

        $this->assertSame(0, $this->stock($product['id']));
        $this->assertSame(1, $this->orderCount($first));
        $this->assertSame(0, $this->orderCount($second));
        $this->assertSame(1, $this->cartCount($second));
    }

    public function testListFiltersByStatusAndCountsCompleted(): void
    {
        $userId = $this->newUser();
        $this->insertOrder($userId, 100000.00, 'Đã hoàn thành', '2030-01-01 10:00:00');
        $this->insertOrder($userId, 250000.50, 'Đã hoàn thành', '2030-01-02 10:00:00');
        $this->insertOrder($userId, 70000.00, 'Chờ xử lý', '2030-01-03 10:00:00');

        $all = $this->orders->list($userId);
        $this->assertSame('Đã tải danh sách đơn hàng.', $all['message']);
        $this->assertSame([70000.0, 250000.5, 100000.0], array_column($all['orders'], 'total_price'));
        $this->assertSame(['Chờ xử lý', 'Đã hoàn thành', 'Đã hoàn thành'], array_column($all['orders'], 'status'));
        $this->assertSame(['total_spent' => 350000.5, 'completed_count' => 2], $all['stats']);
        $this->assertSame(['id', 'total_price', 'status', 'created_at'], array_keys($all['orders'][0]));
        $this->assertIsInt($all['orders'][0]['id']);

        $this->assertCount(3, $this->orders->list($userId, 'all')['orders']);
        $this->assertCount(3, $this->orders->list($userId, '')['orders']);

        $completed = $this->orders->list($userId, 'Đã hoàn thành');
        $this->assertSame([250000.5, 100000.0], array_column($completed['orders'], 'total_price'));
        $this->assertSame(['total_spent' => 350000.5, 'completed_count' => 2], $completed['stats']);

        $pending = $this->orders->list($userId, 'Chờ xử lý');
        $this->assertCount(1, $pending['orders']);
        $this->assertSame(['total_spent' => 350000.5, 'completed_count' => 2], $pending['stats']);

        $none = $this->orders->list($this->newUser());
        $this->assertSame([], $none['orders']);
        $this->assertSame(['total_spent' => 0.0, 'completed_count' => 0], $none['stats']);
        $this->assertSame('Bạn chưa có đơn hàng nào.', $none['message']);
    }

    public function testDetailReturnsOrderWithItems(): void
    {
        $product = $this->product(10);
        $userId = $this->newUser();
        $this->addToCart($userId, $product['id'], 2);
        $orderId = $this->orders->create($userId)['order_id'];

        $detail = $this->orders->detail($userId, $orderId);

        $this->assertSame(['order'], array_keys($detail));
        $this->assertSame($orderId, $detail['order']['id']);
        $this->assertSame('Chờ xử lý', $detail['order']['status']);
        $this->assertCount(1, $detail['order']['items']);
        $item = $detail['order']['items'][0];
        $this->assertSame(['id', 'product_id', 'quantity', 'price', 'name', 'image'], array_keys($item));
        $this->assertSame($product['id'], $item['product_id']);
        $this->assertSame(2, $item['quantity']);
        $this->assertSame($product['price'], $item['price']);
    }

    public function testDetailOfAnotherUsersOrderThrows(): void
    {
        $owner = $this->newUser();
        $other = $this->newUser();
        $orderId = $this->insertOrder($owner, 1000.00, 'Chờ xử lý', '2030-01-01 10:00:00');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Order not found');
        $this->orders->detail($other, $orderId);
    }

    private function newUser(): int
    {
        $name = 'chartest_' . bin2hex(random_bytes(6));
        $this->pdo->prepare('INSERT INTO users (username, email, password) VALUES (?, ?, ?)')
            ->execute([$name, $name . '@example.test', 'x']);
        $id = (int)$this->pdo->lastInsertId();
        $this->userIds[] = $id;
        return $id;
    }

    /** @return array{id:int, price:float, stock:int} first in-stock product with at least $minStock units */
    private function product(int $minStock): array
    {
        $row = $this->pdo->query('SELECT id, price, stock FROM products WHERE stock >= ' . $minStock . ' ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, 'no product with enough stock');
        $product = ['id' => (int)$row['id'], 'price' => (float)$row['price'], 'stock' => (int)$row['stock']];
        $this->originalStock[$product['id']] ??= $product['stock'];
        return $product;
    }

    private function setStock(int $productId, int $stock): void
    {
        $this->originalStock[$productId] ??= $this->stock($productId);
        $this->pdo->prepare('UPDATE products SET stock = ? WHERE id = ?')->execute([$stock, $productId]);
    }

    private function stock(int $productId): int
    {
        return (int)$this->pdo->query('SELECT stock FROM products WHERE id = ' . $productId)->fetchColumn();
    }

    private function addToCart(int $userId, int $productId, int $quantity): void
    {
        $this->pdo->prepare('INSERT INTO cart (user_id, product_id, quantity, size) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $productId, $quantity, 'M']);
    }

    private function cartCount(int $userId): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM cart WHERE user_id = ' . $userId)->fetchColumn();
    }

    private function orderCount(int $userId): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM orders WHERE user_id = ' . $userId)->fetchColumn();
    }

    private function insertOrder(int $userId, float $total, string $status, string $createdAt): int
    {
        $this->pdo->prepare('INSERT INTO orders (user_id, total_price, status, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $total, $status, $createdAt]);
        return (int)$this->pdo->lastInsertId();
    }
}
