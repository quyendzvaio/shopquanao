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

    private function manyTickets(PDO $pdo, int $count): void
    {
        $ins = $pdo->prepare("INSERT INTO support_tickets (user_id, type, summary, status, created_at) VALUES (5, 'khac', 'x', 'moi', ?)");
        for ($i = 0; $i < $count; $i++) {
            $ins->execute([sprintf('2026-02-%02d 10:%02d:00', intdiv($i, 20) + 1, $i % 20)]);
        }
    }

    private function renderProfile(array $query): string
    {
        $pdo = $this->db();
        $this->manyTickets($pdo, 22); // + the one from db() = 23 for user 5
        $page = new ProfilePage(new OrderService($pdo), new SupportTicketService($pdo));
        $session = ['user_id' => 5, 'username' => 'An'];
        $data = $page->handle(new WebRequest('GET', $query, [], []), $session)->data();
        extract($data);
        ob_start();
        include __DIR__ . '/../../../../templates/pages/profile.php';

        return (string) ob_get_clean();
    }

    public function testTicketsArePagedByTenWithTicketPage(): void
    {
        $pdo = $this->db();
        $this->manyTickets($pdo, 22);
        $page = new ProfilePage(new OrderService($pdo), new SupportTicketService($pdo));
        $session = ['user_id' => 5, 'username' => 'An'];

        $this->assertCount(10, $page->handle(new WebRequest('GET', [], [], []), $session)->data()['tickets']);
        $d3 = $page->handle(new WebRequest('GET', ['ticket_page' => '3'], [], []), $session)->data();
        $this->assertCount(3, $d3['tickets']);
        $this->assertSame(3, $d3['ticket_pages']);
        $this->assertSame(3, $d3['ticket_page']);
        $this->assertSame(1, $page->handle(new WebRequest('GET', ['ticket_page' => 'abc'], [], []), $session)->data()['ticket_page']);
        $this->assertSame(3, $page->handle(new WebRequest('GET', ['ticket_page' => '99'], [], []), $session)->data()['ticket_page']);
    }

    public function testTicketPagerKeepsTheOrderFilter(): void
    {
        $out = $this->renderProfile(['status' => 'Đã hoàn thành', 'ticket_page' => '2']);

        $this->assertStringContainsString('Trang 2/3', $out);
        $this->assertStringContainsString('profile.php?status=%C4%90%C3%A3+ho%C3%A0n+th%C3%A0nh&amp;ticket_page=1', $out);
        $this->assertStringContainsString('profile.php?status=%C4%90%C3%A3+ho%C3%A0n+th%C3%A0nh&amp;ticket_page=3', $out);
        $this->assertStringContainsString('Trước', $out);
        $this->assertStringContainsString('Sau', $out);

        $plain = $this->renderProfile([]);
        $this->assertStringContainsString('Trang 1/3', $plain);
        $this->assertStringContainsString('profile.php?ticket_page=2', $plain);
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
