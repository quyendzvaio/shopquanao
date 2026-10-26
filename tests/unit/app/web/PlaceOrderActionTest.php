<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PlaceOrderActionTest extends TestCase
{
    private function shop(int $stock = 5): PDO
    {
        $pdo = SqliteShop::pdo();
        $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, total_price REAL,
            status TEXT, created_at TEXT)');
        $pdo->exec('CREATE TABLE order_items (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER,
            product_id INTEGER, quantity INTEGER, price REAL)');
        SqliteShop::product($pdo, 7, 1, 'Áo thun', 150000, $stock, '');
        return $pdo;
    }

    /** Posts with the session's CSRF token by default; pass $token = null for none, or another value for a wrong one. */
    private function visit(PDO $pdo, string $method, array $session, ?string $token = 'tok'): PageResult
    {
        $post = ['full_name' => 'A', 'phone' => '0900', 'address' => 'HN', 'note' => ''];
        $session['csrf_token'] = 'tok';
        if ($token !== null) {
            $post['csrf_token'] = $token;
        }

        return (new PlaceOrderAction(new OrderService($pdo)))->handle(new WebRequest($method, [], $post, []), $session);
    }

    private function rows(PDO $pdo, string $table): int
    {
        return (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    public function testGuestOrNonPostGoesHomeWithoutWriting(): void
    {
        $pdo = $this->shop();
        $pdo->exec("INSERT INTO cart (user_id, product_id, quantity, size) VALUES (5, 7, 1, 'S')");

        foreach ([['POST', []], ['GET', ['user_id' => 5]]] as [$method, $session]) {
            $result = $this->visit($pdo, $method, $session);

            $this->assertSame('redirect', $result->kind());
            $this->assertSame('index.php', $result->payload());
        }
        $this->assertSame(0, $this->rows($pdo, 'orders'));
        $this->assertSame(1, $this->rows($pdo, 'cart'));
    }

    public function testSuccessPlacesThePendingVietnameseOrderDecrementsStockAndClearsTheCart(): void
    {
        $pdo = $this->shop(5);
        $pdo->exec("INSERT INTO cart (user_id, product_id, quantity, size) VALUES (5, 7, 2, 'M')");

        $result = $this->visit($pdo, 'POST', ['user_id' => 5]);

        $this->assertSame('output', $result->kind());
        $this->assertSame(
            "<script>alert('Đặt hàng thành công! Fashion Shop sẽ liên hệ bạn sớm nhất.'); window.location.href='index.php';</script>",
            $result->payload()
        );
        $order = $pdo->query('SELECT user_id, total_price, status FROM orders')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(5, (int) $order['user_id']);
        $this->assertSame(300000.0, (float) $order['total_price']);
        $this->assertSame('Chờ xử lý', $order['status']);
        $this->assertSame(1, $this->rows($pdo, 'order_items'));
        $this->assertSame(3, (int) $pdo->query('SELECT stock FROM products WHERE id = 7')->fetchColumn());
        $this->assertSame(0, $this->rows($pdo, 'cart'));
    }

    public function testInsufficientStockHaltsWithTheProductIdAndLeavesStockAndCartAlone(): void
    {
        $pdo = $this->shop(1);
        $pdo->exec("INSERT INTO cart (user_id, product_id, quantity, size) VALUES (5, 7, 2, 'M')");

        $result = $this->visit($pdo, 'POST', ['user_id' => 5]);

        $this->assertSame('halt', $result->kind());
        $this->assertSame('Lỗi đặt hàng: Sản phẩm ID 7 đã hết hàng!', $result->payload());
        $this->assertSame(1, (int) $pdo->query('SELECT stock FROM products WHERE id = 7')->fetchColumn());
        $this->assertSame(0, $this->rows($pdo, 'orders'));
        $this->assertSame(1, $this->rows($pdo, 'cart'));
    }

    public function testEmptyCartHaltsAndCreatesNoOrder(): void
    {
        $pdo = $this->shop();

        $result = $this->visit($pdo, 'POST', ['user_id' => 5]);

        $this->assertSame('halt', $result->kind());
        $this->assertSame('Lỗi đặt hàng: Giỏ hàng của bạn đang trống.', $result->payload());
        $this->assertSame(0, $this->rows($pdo, 'orders'));
    }

    public function testDatabaseErrorHaltsWithTheGenericMessageAndNoSqlText(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'phplog');
        $previous = ini_set('error_log', $log);
        try {
            $pdo = $this->shop();
            $pdo->exec("INSERT INTO cart (user_id, product_id, quantity, size) VALUES (5, 7, 1, 'S')");
            $pdo->exec('DROP TABLE order_items');

            $result = $this->visit($pdo, 'POST', ['user_id' => 5]);
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $this->assertSame('halt', $result->kind());
        $this->assertSame('Lỗi hệ thống, vui lòng thử lại sau.', $result->payload());
        $this->assertStringNotContainsStringIgnoringCase('SQLSTATE', $result->payload());
        $this->assertStringNotContainsString('order_items', $result->payload());
        $this->assertStringContainsString('no such table', (string) file_get_contents($log));
        unlink($log);
        $this->assertSame(0, $this->rows($pdo, 'orders'));
        $this->assertSame(1, $this->rows($pdo, 'cart'));
    }

    public function testAnyOtherUnexpectedErrorAlsoHaltsWithTheGenericMessage(): void
    {
        $pdo = $this->shop();
        $pdo->exec("INSERT INTO cart (user_id, product_id, quantity, size) VALUES (5, 7, 1, 'S')");
        // A concurrent writer wins: the stock update touches no row, so OrderService throws 'Stock changed ...'
        $pdo->exec('CREATE TRIGGER skip_stock BEFORE UPDATE ON products BEGIN SELECT RAISE(IGNORE); END');

        $log = tempnam(sys_get_temp_dir(), 'phplog');
        $previous = ini_set('error_log', $log);
        try {
            $result = $this->visit($pdo, 'POST', ['user_id' => 5]);
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $this->assertSame('halt', $result->kind());
        $this->assertSame('Lỗi hệ thống, vui lòng thử lại sau.', $result->payload());
        $this->assertStringNotContainsString('Stock changed', $result->payload());
        $this->assertStringContainsString('Stock changed', (string) file_get_contents($log));
        unlink($log);
        $this->assertSame(0, $this->rows($pdo, 'orders'));
    }

    public function testEnvAdminHaltsWithTheAdminMessageAndNothingIsWritten(): void
    {
        $pdo = $this->shop();
        $pdo->exec("INSERT INTO cart (user_id, product_id, quantity, size) VALUES (0, 7, 1, 'S')");
        $sessions = [
            ['user_id' => 0, 'role' => 'admin', 'admin_logged_in' => true],
            ['user_id' => 0, 'role' => 'admin'],
            ['user_id' => 0, 'admin_logged_in' => true],
            ['user_id' => -1, 'role' => 'admin'],
        ];

        foreach ($sessions as $session) {
            foreach (['POST', 'GET'] as $method) {
                foreach (['tok', null] as $token) {
                    $result = $this->visit($pdo, $method, $session, $token);

                    $this->assertSame('halt', $result->kind());
                    $this->assertSame('Tài khoản quản trị không dùng để mua hàng.', $result->payload());
                }
            }
        }
        $this->assertSame(0, $this->rows($pdo, 'orders'));
        $this->assertSame(0, $this->rows($pdo, 'order_items'));
        $this->assertSame(5, (int) $pdo->query('SELECT stock FROM products WHERE id = 7')->fetchColumn());
        $this->assertSame(1, $this->rows($pdo, 'cart'));
    }

    public function testDatabaseAdminWithARealUserIdCanStillCheckOut(): void
    {
        $pdo = $this->shop();
        $pdo->exec("INSERT INTO cart (user_id, product_id, quantity, size) VALUES (5, 7, 1, 'S')");

        $result = $this->visit($pdo, 'POST', ['user_id' => 5, 'role' => 'admin', 'admin_logged_in' => true]);

        $this->assertSame('output', $result->kind());
        $this->assertSame(1, $this->rows($pdo, 'orders'));
    }

    public function testMissingOrWrongTokenGoesHomeWithoutWriting(): void
    {
        $pdo = $this->shop();
        $pdo->exec("INSERT INTO cart (user_id, product_id, quantity, size) VALUES (5, 7, 1, 'S')");

        foreach ([null, 'forged', ''] as $token) {
            $result = $this->visit($pdo, 'POST', ['user_id' => 5], $token);

            $this->assertSame('redirect', $result->kind());
            $this->assertSame('index.php', $result->payload());
        }
        $this->assertSame(0, $this->rows($pdo, 'orders'));
        $this->assertSame(5, (int) $pdo->query('SELECT stock FROM products WHERE id = 7')->fetchColumn());
        $this->assertSame(1, $this->rows($pdo, 'cart'));
    }

    public function testASessionWithoutATokenNeverPlacesAnOrder(): void
    {
        $pdo = $this->shop();
        $pdo->exec("INSERT INTO cart (user_id, product_id, quantity, size) VALUES (5, 7, 1, 'S')");
        $request = new WebRequest('POST', [], ['csrf_token' => ''], []);

        $result = (new PlaceOrderAction(new OrderService($pdo)))->handle($request, ['user_id' => 5]);

        $this->assertSame('index.php', $result->payload());
        $this->assertSame(0, $this->rows($pdo, 'orders'));
    }
}
