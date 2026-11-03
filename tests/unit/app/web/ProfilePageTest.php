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
        $pdo->exec("CREATE TABLE support_tickets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
            order_id INTEGER NULL, type TEXT NOT NULL, summary TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'moi',
            admin_note TEXT NULL, chat_session_id INTEGER NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec("INSERT INTO support_tickets (id, user_id, order_id, type, summary, status, admin_note, created_at)
            VALUES (1, 5, 1, 'doi_tra', '<script>x</script>', 'moi', '<script>x</script>', '2026-01-05 09:30:00'),
                   (2, 6, 4, 'khac', 'của người khác', 'moi', NULL, '2026-01-06 10:00:00')");
        return $pdo;
    }

    private function visit(array $query, array $session): PageResult
    {
        return (new ProfilePage(new OrderService($pdo = $this->db()), new SupportTicketService($pdo)))->handle(new WebRequest('GET', $query, [], []), $session);
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

    public function testViewDataHasOnlyTheUsersTickets(): void
    {
        $data = $this->visit([], ['user_id' => '5', 'username' => 'An'])->data();

        $this->assertSame([1], array_column($data['tickets'], 'id'));
        $this->assertSame('Đổi size / đổi trả', $data['tickets'][0]['type_label']);
        $this->assertSame('Mới', $data['tickets'][0]['status_label']);
    }

    public function testTicketFieldsAreEscaped(): void
    {
        $data = $this->visit([], ['user_id' => 5, 'username' => 'An'])->data();
        extract($data);
        ob_start();
        include __DIR__ . '/../../../../templates/pages/profile.php';
        $out = (string) ob_get_clean();

        $this->assertStringContainsString('&lt;script&gt;', $out);
        $this->assertStringNotContainsString('<script>x', $out);
        $this->assertStringContainsString('05/01/2026 09:30', $out);
    }
}
