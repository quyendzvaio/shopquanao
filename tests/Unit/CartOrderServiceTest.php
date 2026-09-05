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

    public function testAddParticipatesInCallerTransactionAndWritesOutboxForActiveChatSession(): void
    {
        $this->pdo->exec("CREATE TABLE chat_sessions (id INTEGER PRIMARY KEY, user_id INTEGER, status TEXT, updated_at TEXT)");
        $this->pdo->exec("INSERT INTO chat_sessions VALUES (42, 10, 'active', CURRENT_TIMESTAMP)");
        $this->pdo->exec("CREATE TABLE fashion_event_outbox (id INTEGER PRIMARY KEY AUTOINCREMENT,event_id TEXT UNIQUE,event_type TEXT,event_version INTEGER,aggregate_key TEXT,payload TEXT,status TEXT)");
        $this->createProactiveStateTable();

        $this->pdo->beginTransaction();
        $this->cart->add(10, ['product_id' => 1, 'quantity' => 1, 'size' => 'M']);

        $payload = json_decode((string) $this->pdo->query('SELECT payload FROM fashion_event_outbox')->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('cart.item_added', $payload['event_type']);
        $this->assertSame('42', $payload['session_id']);
        $state = (new ProactiveStylingStateStore($this->pdo))->get(10, '42');
        $this->assertSame(1, $state['pending_product_id']);
        $this->assertSame(2, $state['remaining_user_turns']);
        $this->assertTrue($this->pdo->inTransaction());
        $this->pdo->rollBack();
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM cart')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM fashion_event_outbox')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM proactive_styling_state')->fetchColumn());
    }

    public function testAddRollsBackOwnedTransactionWhenOutboxWriteFails(): void
    {
        $this->pdo->exec('CREATE TABLE fashion_event_outbox (id INTEGER PRIMARY KEY)');

        try {
            $this->cart->add(10, ['product_id' => 1, 'quantity' => 1, 'size' => 'M']);
            self::fail('Expected the invalid outbox schema to reject the write');
        } catch (PDOException) {
            self::assertFalse($this->pdo->inTransaction());
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM cart')->fetchColumn());
        }
    }

    private function createProactiveStateTable(): void
    {
        $this->pdo->exec("CREATE TABLE proactive_styling_state(user_id INTEGER,session_id TEXT,pending_product_id INTEGER,pending_variant_id INTEGER,remaining_user_turns INTEGER,source_event_id TEXT,state_version INTEGER DEFAULT 0,eligible INTEGER,status TEXT DEFAULT 'not_armed',failure_reason TEXT,retry_count INTEGER DEFAULT 0,last_attempt_at TEXT,suggested_anchor_product_id INTEGER,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(user_id,session_id))");
    }
}
