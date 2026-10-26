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

    private function post(PDO $pdo, string $status): PageResult
    {
        $page = new AdminOrdersPage(new OrderService($pdo));

        return $page->handle(
            new WebRequest('POST', [], ['update_status' => '1', 'order_id' => '3', 'status' => $status], []),
            ['role' => 'admin']
        );
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
}
