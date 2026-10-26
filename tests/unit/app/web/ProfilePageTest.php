<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProfilePageTest extends TestCase
{
    private function db(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER, total_price REAL, status TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $insert = $pdo->prepare('INSERT INTO orders (id, user_id, total_price, status, created_at) VALUES (?, ?, ?, ?, ?)');
        $insert->execute([1, 5, 100000, 'Chờ xử lý', '2026-01-01 10:00:00']);
        $insert->execute([2, 5, 200000, 'Đã hoàn thành', '2026-01-02 10:00:00']);
        $insert->execute([3, 5, 50000, 'Đã hoàn thành', '2026-01-03 10:00:00']);
        $insert->execute([4, 6, 999000, 'Đã hoàn thành', '2026-01-04 10:00:00']);
        return $pdo;
    }

    private function visit(array $query, array $session): PageResult
    {
        return (new ProfilePage(new OrderService($this->db())))->handle(new WebRequest('GET', $query, [], []), $session);
    }

    public function testGuestRedirectsToLogin(): void
    {
        $this->assertSame('login.php', $this->visit([], [])->payload());
    }

    public function testAllOrdersNewestFirstWithCompletedTotals(): void
    {
        $data = $this->visit([], ['user_id' => 5, 'username' => 'An'])->data();

        $this->assertSame('all', $data['status_filter']);
        $this->assertSame('An', $data['username']);
        $this->assertSame([3, 2, 1], array_map('intval', array_column($data['my_orders'], 'id')));
        $this->assertEquals(250000, $data['stat']['total_spent']);
        $this->assertEquals(2, $data['stat']['completed_count']);
    }

    public function testStatusFilterKeepsTheTotalsOverEveryCompletedOrder(): void
    {
        $data = $this->visit(['status' => 'Chờ xử lý'], ['user_id' => 5, 'username' => 'An'])->data();

        $this->assertSame([1], array_map('intval', array_column($data['my_orders'], 'id')));
        $this->assertEquals(2, $data['stat']['completed_count']);
    }
}
