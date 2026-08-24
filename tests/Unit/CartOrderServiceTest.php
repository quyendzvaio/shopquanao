<?php

final class CartOrderServiceTest extends \PHPUnit\Framework\TestCase
{
    private PDO $pdo;
    private CartService $cart;
    private OrderService $orders;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, price REAL, stock INTEGER, image TEXT)');
        $this->pdo->exec("CREATE TABLE cart (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, product_id INTEGER, quantity INTEGER, size TEXT)");
        $this->pdo->exec("CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, total_price REAL, status TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $this->pdo->exec('CREATE TABLE order_items (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, product_id INTEGER, quantity INTEGER, price REAL)');
        $this->pdo->exec("INSERT INTO products VALUES (1, 'Áo test', 150000, 5, 'test.jpg')");
        $this->cart = new CartService($this->pdo);
        $this->orders = new OrderService($this->pdo);
    }

    public function testCartIsScopedToCurrentUser(): void
    {
        $first = $this->cart->add(10, ['product_id' => 1, 'quantity' => 1, 'size' => 'M']);
        $this->cart->add(20, ['product_id' => 1, 'quantity' => 2, 'size' => 'L']);

        $this->assertCount(1, $this->cart->list(10)['cart']);
        $this->assertSame(1, $this->cart->list(10)['cart'][0]['quantity']);
        $this->expectException(RuntimeException::class);
        $this->cart->remove(20, $first['cart_id']);
    }

    public function testOrderDetailCannotCrossUserBoundary(): void
    {
        $this->pdo->exec("INSERT INTO orders (id, user_id, total_price, status) VALUES (7, 10, 150000, 'Chờ xử lý')");
        $this->expectException(RuntimeException::class);
        $this->orders->detail(20, 7);
    }

    public function testCreateOrderRejectsEmptyCart(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cart is empty');
        $this->orders->create(10);
    }

    public function testCreateOrderMovesCartAndDecrementsStockAtomically(): void
    {
        $this->cart->add(10, ['product_id' => 1, 'quantity' => 2, 'size' => 'M']);
        $created = $this->orders->create(10);

        $this->assertSame(300000.0, $created['total_price']);
        $this->assertSame([], $this->cart->list(10)['cart']);
        $this->assertSame(3, (int) $this->pdo->query('SELECT stock FROM products WHERE id = 1')->fetchColumn());
        $this->assertSame(2, (int) $this->pdo->query('SELECT quantity FROM order_items')->fetchColumn());
    }
}
