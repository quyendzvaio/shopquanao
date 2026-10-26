<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AdminOrdersPageTest extends TestCase
{
    private function db(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT)');
        $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER, total_price REAL, status TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec("INSERT INTO users (id, username) VALUES (5, 'lan')");
        $pdo->exec("INSERT INTO orders (id, user_id, total_price, status) VALUES (3, 5, 300000, 'Chờ xử lý')");
        return $pdo;
    }

    /** Posts with the session's CSRF token by default; pass $token = null for none, or another value for a wrong one. */
    private function post(PDO $pdo, string $status, ?string $token = 'tok', string $method = 'POST'): PageResult
    {
        $page = new AdminOrdersPage(new OrderService($pdo));
        $fields = ['update_status' => '1', 'order_id' => '3', 'status' => $status];
        if ($token !== null) {
            $fields['csrf_token'] = $token;
        }

        return $page->handle(new WebRequest($method, [], $fields, []), ['role' => 'admin', 'csrf_token' => 'tok']);
    }

    private function statusOfOrder3(PDO $pdo): string
    {
        return (string) $pdo->query('SELECT status FROM orders WHERE id = 3')->fetchColumn();
    }

    public function testEveryWhitelistedStatusIsStored(): void
    {
        foreach (OrderService::STATUSES as $status) {
            $pdo = $this->db();

            $result = $this->post($pdo, $status);

            $this->assertSame('redirect', $result->kind());
            $this->assertSame($status, $this->statusOfOrder3($pdo));
        }
    }

    public function testAnInvalidStatusRedirectsAndLeavesTheRowUnchanged(): void
    {
        $pdo = $this->db();

        $result = $this->post($pdo, 'bogus');

        $this->assertSame('redirect', $result->kind());
        $this->assertSame('manage_orders.php', $result->payload());
        $this->assertSame('Chờ xử lý', $this->statusOfOrder3($pdo));
    }

    public function testTheWhitelistIsTheApiList(): void
    {
        $this->assertSame(['Chờ xử lý', 'Đang giao', 'Đã hoàn thành', 'Đã hủy'], OrderService::STATUSES);
    }

    public function testMissingOrWrongTokenRedirectsAndLeavesTheRowUnchanged(): void
    {
        $pdo = $this->db();

        foreach ([null, 'forged', ''] as $token) {
            $result = $this->post($pdo, 'Đã hủy', $token);

            $this->assertSame('redirect', $result->kind());
            $this->assertSame('manage_orders.php', $result->payload());
        }
        $this->assertSame('Chờ xử lý', $this->statusOfOrder3($pdo));
    }

    public function testAGetNeverUpdatesTheStatusEvenWithAValidToken(): void
    {
        $pdo = $this->db();

        $result = $this->post($pdo, 'Đã hủy', 'tok', 'GET');

        $this->assertTrue($result->isView());
        $this->assertSame('Chờ xử lý', $this->statusOfOrder3($pdo));
    }

    public function testASessionWithoutATokenNeverUpdatesTheStatus(): void
    {
        $pdo = $this->db();
        $fields = ['update_status' => '1', 'order_id' => '3', 'status' => 'Đã hủy', 'csrf_token' => ''];

        (new AdminOrdersPage(new OrderService($pdo)))->handle(new WebRequest('POST', [], $fields, []), ['role' => 'admin']);

        $this->assertSame('Chờ xử lý', $this->statusOfOrder3($pdo));
    }
}
