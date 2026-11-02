<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AdminTicketsPageTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SqliteShop::pdo();
        SqliteShop::supportTables($this->pdo);
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT)');
        $this->pdo->exec("INSERT INTO users (id, username) VALUES (5, 'lan')");
        $this->pdo->exec("INSERT INTO orders (id, user_id, total_price, status) VALUES (12, 5, 100000, 'Chờ xử lý')");
        $this->ticket(1, 'moi');
        $this->ticket(2, 'dang_xu_ly');
        $this->ticket(3, 'da_xong');
        $this->ticket(4, 'tu_choi');
    }

    private function ticket(int $id, string $status, string $summary = 'đổi size'): void
    {
        $this->pdo->prepare("INSERT INTO support_tickets (id, user_id, order_id, type, summary, status, admin_note)
            VALUES (?, 5, 12, 'doi_tra', ?, ?, 'ghi chú cũ')")->execute([$id, $summary, $status]);
    }

    private function page(): AdminTicketsPage
    {
        return new AdminTicketsPage(new SupportTicketService($this->pdo));
    }

    private function admin(): array
    {
        return ['role' => 'admin', 'csrf_token' => 'tok'];
    }

    private function get(array $query, ?array $session = null): PageResult
    {
        return $this->page()->handle(new WebRequest('GET', $query, [], []), $session ?? $this->admin());
    }

    /** Posts with the session's CSRF token by default; pass $token = null for none. */
    private function post(array $fields, ?string $token = 'tok'): PageResult
    {
        $fields += ['update_ticket' => '1', 'ticket_id' => '1', 'status' => 'dang_xu_ly', 'admin_note' => 'đã gọi khách'];
        if ($token !== null) {
            $fields['csrf_token'] = $token;
        }

        return $this->page()->handle(new WebRequest('POST', [], $fields, []), $this->admin());
    }

    private function row(int $id): array
    {
        return $this->pdo->query("SELECT status, admin_note FROM support_tickets WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
    }

    public function testGuardDeniesNonAdmin(): void
    {
        foreach ([[], ['user_id' => 5, 'role' => 'user']] as $session) {
            $result = $this->get([], $session);

            $this->assertSame('redirect', $result->kind());
            $this->assertSame('login.php', $result->payload());
        }
    }

    public function testPostWithoutValidTokenWritesNothing(): void
    {
        foreach ([null, 'forged', ''] as $token) {
            $result = $this->post([], $token);

            $this->assertSame('redirect', $result->kind());
        }
        $this->assertSame(['status' => 'moi', 'admin_note' => 'ghi chú cũ'], $this->row(1));
    }

    public function testValidPostUpdatesStatusAndNote(): void
    {
        $result = $this->post([]);

        $this->assertSame('redirect', $result->kind());
        $this->assertSame('manage_tickets.php?id=1&msg=updated', $result->payload());
        $this->assertSame(['status' => 'dang_xu_ly', 'admin_note' => 'đã gọi khách'], $this->row(1));
    }

    public function testUnknownStatusWritesNothing(): void
    {
        $result = $this->post(['status' => 'bogus']);

        $this->assertSame('redirect', $result->kind());
        $this->assertSame(['status' => 'moi', 'admin_note' => 'ghi chú cũ'], $this->row(1));
    }

    public function testUnknownTicketIdWritesNothing(): void
    {
        $result = $this->post(['ticket_id' => '999']);

        $this->assertSame('redirect', $result->kind());
        $this->assertSame('manage_tickets.php', $result->payload());
        $this->assertSame(4, (int) $this->pdo->query('SELECT COUNT(*) FROM support_tickets')->fetchColumn());
        $this->assertSame(['status' => 'moi', 'admin_note' => 'ghi chú cũ'], $this->row(1));
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM support_tickets WHERE status = 'moi'")->fetchColumn());
    }

    public function testAGetNeverUpdates(): void
    {
        $this->page()->handle(
            new WebRequest('GET', ['status' => 'moi'], ['update_ticket' => '1', 'ticket_id' => '1', 'status' => 'da_xong', 'csrf_token' => 'tok'], []),
            $this->admin()
        );

        $this->assertSame('moi', $this->row(1)['status']);
    }

    public function testListDefaultsToOpenTickets(): void
    {
        $data = $this->get([])->data();

        $this->assertSame([2, 1], array_column($data['tickets'], 'id'));
        $this->assertNull($data['ticket']);
    }

    public function testStatusFilterAndAll(): void
    {
        $this->assertSame([3], array_column($this->get(['status' => 'da_xong'])->data()['tickets'], 'id'));
        $this->assertCount(4, $this->get(['status' => 'all'])->data()['tickets']);
        $this->assertSame([2, 1], array_column($this->get(['status' => 'bogus'])->data()['tickets'], 'id'));
    }

    public function testDetailIncludesTheOrderId(): void
    {
        $data = $this->get(['id' => '2'])->data();

        $this->assertSame(2, $data['ticket']['id']);
        $this->assertSame(12, $data['ticket']['order_id']);
        $this->assertSame('lan', $data['ticket']['username']);
    }

    public function testUnknownDetailFallsBackToTheList(): void
    {
        $data = $this->get(['id' => '999'])->data();

        $this->assertNull($data['ticket']);
        $this->assertNotEmpty($data['tickets']);
    }

    public function testTicketFieldsAreEscaped(): void
    {
        $this->ticket(9, 'moi', '<script>x</script>');
        $this->pdo->exec("UPDATE support_tickets SET admin_note = '<script>x</script>' WHERE id = 9");
        $this->pdo->exec("UPDATE users SET username = '<script>x</script>' WHERE id = 5");

        foreach ([[], ['id' => '9']] as $query) {
            $out = $this->render($this->get($query)->data());

            $this->assertStringContainsString('&lt;script&gt;', $out);
            $this->assertStringNotContainsString('<script>x', $out);
        }
    }

    private function render(array $data): string
    {
        $_SESSION = ['csrf_token' => 'tok'];
        extract($data);
        ob_start();
        include __DIR__ . '/../../../../../templates/pages/admin/tickets.php';

        return (string) ob_get_clean();
    }
}
