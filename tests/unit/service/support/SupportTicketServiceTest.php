<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SupportTicketServiceTest extends TestCase
{
    private PDO $pdo;
    private SupportTicketService $service;

    protected function setUp(): void
    {
        $this->pdo = SqliteShop::pdo();
        SqliteShop::supportTables($this->pdo);
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT)');
        $this->pdo->exec("INSERT INTO users (id, username) VALUES (1, 'an'), (2, 'binh')");
        $this->pdo->exec("INSERT INTO orders (id, user_id, total_price, status) VALUES (12, 1, 100000, 'Chờ xử lý'), (13, 2, 200000, 'Chờ xử lý')");
        $this->service = new SupportTicketService($this->pdo);
    }

    private function ticket(int $userId, ?int $orderId, string $type, string $status, string $createdAt = '2026-11-01 10:00:00'): int
    {
        $this->pdo->prepare('INSERT INTO support_tickets (user_id, order_id, type, summary, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$userId, $orderId, $type, 'tóm tắt', $status, $createdAt, $createdAt]);
        return (int) $this->pdo->lastInsertId();
    }

    private function rows(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM support_tickets')->fetchColumn();
    }

    public function testGuestGetsRequiresLogin(): void
    {
        $this->assertSame('requires_login', $this->service->draft(null, ['type' => 'khac', 'summary' => 'x'])['status']);
    }

    public function testOrderRequiredExceptForKhac(): void
    {
        $this->assertSame('order_required', $this->service->draft(1, ['type' => 'huy_don', 'summary' => 'x'])['status']);
        $khac = $this->service->draft(1, ['type' => 'khac', 'summary' => 'x']);
        $this->assertSame('draft', $khac['status']);
        $this->assertNull($khac['draft']['order_id']);
    }

    public function testOrderOfAnotherUserIsNotFound(): void
    {
        $this->assertSame('order_not_found', $this->service->draft(1, ['type' => 'doi_tra', 'order_id' => 13, 'summary' => 'x'])['status']);
    }

    public function testInvalidTypeOrSummary(): void
    {
        $this->assertSame('invalid', $this->service->draft(1, ['type' => 'bay', 'order_id' => 12, 'summary' => 'x'])['status']);
        foreach (['', '   ', str_repeat('a', 501)] as $summary) {
            $this->assertSame('invalid', $this->service->draft(1, ['type' => 'khac', 'summary' => $summary])['status']);
        }
        $this->assertSame('draft', $this->service->draft(1, ['type' => 'khac', 'summary' => str_repeat('a', 500)])['status']);
    }

    public function testDraftWritesNothing(): void
    {
        $this->assertSame('draft', $this->service->draft(1, ['type' => 'doi_tra', 'order_id' => 12, 'summary' => 'x'])['status']);
        $this->assertSame(0, $this->rows());
    }

    public function testDuplicateOpenOnlyWithAnOrder(): void
    {
        $id = $this->ticket(1, 12, 'doi_tra', 'dang_xu_ly');
        $dup = $this->service->draft(1, ['type' => 'doi_tra', 'order_id' => 12, 'summary' => 'x']);
        $this->assertSame('duplicate_open', $dup['status']);
        $this->assertSame($id, $dup['ticket_id']);
        $this->assertSame('dang_xu_ly', $dup['ticket_status']);
        $this->assertSame('draft', $this->service->draft(1, ['type' => 'hang_loi', 'order_id' => 12, 'summary' => 'x'])['status']);

        $this->ticket(1, null, 'khac', 'moi');
        $this->assertSame('draft', $this->service->draft(1, ['type' => 'khac', 'summary' => 'x'])['status']);
        $this->ticket(1, null, 'khac', 'moi');
        $this->assertSame('draft', $this->service->draft(1, ['type' => 'khac', 'summary' => 'x'])['status']);
    }

    public function testClosedTicketIsNotADuplicate(): void
    {
        $this->ticket(1, 12, 'doi_tra', 'da_xong');
        $this->assertSame('draft', $this->service->draft(1, ['type' => 'doi_tra', 'order_id' => 12, 'summary' => 'x'])['status']);
    }

    public function testTooManyOpen(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->ticket(1, null, 'khac', $i % 2 === 0 ? 'moi' : 'dang_xu_ly');
        }
        for ($i = 0; $i < 3; $i++) {
            $this->ticket(1, null, 'khac', 'da_xong');
        }
        $this->assertSame('draft', $this->service->draft(1, ['type' => 'khac', 'summary' => 'x'])['status']);
        $this->ticket(1, null, 'khac', 'moi');
        $this->assertSame('too_many_open', $this->service->draft(1, ['type' => 'khac', 'summary' => 'x'])['status']);
    }

    public function testCreateRechecksDuplicateAndLimit(): void
    {
        $draft = $this->service->draft(1, ['type' => 'doi_tra', 'order_id' => 12, 'summary' => ' đổi size M '])['draft'];
        $this->ticket(1, 12, 'doi_tra', 'moi');
        $this->assertSame('duplicate_open', $this->service->create(1, $draft, 7)['status']);
        $this->assertSame(1, $this->rows());

        $this->pdo->exec('DELETE FROM support_tickets');
        for ($i = 0; $i < 5; $i++) {
            $this->ticket(1, null, 'khac', 'moi');
        }
        $this->assertSame('too_many_open', $this->service->create(1, $draft, 7)['status']);
        $this->assertSame(5, $this->rows());

        $this->pdo->exec('DELETE FROM support_tickets');
        $created = $this->service->create(1, $draft, 7);
        $this->assertSame('created', $created['status']);
        $row = $this->pdo->query('SELECT * FROM support_tickets')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame((int) $created['ticket_id'], (int) $row['id']);
        $this->assertSame('moi', $row['status']);
        $this->assertSame(7, (int) $row['chat_session_id']);
        $this->assertSame('đổi size M', $row['summary']);
    }

    public function testListForUserOnlyOwnTickets(): void
    {
        $mine = [];
        for ($i = 1; $i <= 6; $i++) {
            $mine[] = $this->ticket(1, 12, 'doi_tra', 'moi', sprintf('2026-11-0%d 10:00:00', $i));
        }
        $this->assertSame([], $this->service->listForUser(2, ['ticket_id' => $mine[0]])['tickets']);
        $list = $this->service->listForUser(1, [])['tickets'];
        $this->assertCount(5, $list);
        $this->assertSame($mine[5], $list[0]['id']);
        $this->assertSame('Đổi size / đổi trả', $list[0]['type_label']);
        $this->assertSame('Mới', $list[0]['status_label']);
        $this->assertCount(1, $this->service->listForUser(1, ['ticket_id' => $mine[0]])['tickets']);
        $this->assertTrue($this->service->listForUser(null, [])['requires_login']);
    }

    public function testAdminListDetailCountAndUpdate(): void
    {
        $a = $this->ticket(1, 12, 'doi_tra', 'moi');
        $this->ticket(2, 13, 'huy_don', 'da_xong');
        $this->assertCount(1, $this->service->adminList(null));
        $this->assertSame('an', $this->service->adminList(null)[0]['username']);
        $this->assertCount(1, $this->service->adminList('da_xong'));
        $this->assertSame([], $this->service->adminList('bogus'));
        $this->assertSame(1, $this->service->countNew());
        $this->assertNull($this->service->adminDetail(999));

        $this->assertTrue($this->service->adminUpdate($a, 'dang_xu_ly', '  ghi chú '));
        $detail = $this->service->adminDetail($a);
        $this->assertSame('ghi chú', $detail['admin_note']);
        $this->assertSame('dang_xu_ly', $detail['status']);
        $this->assertFalse($this->service->adminUpdate($a, 'deleted', ''));
        $this->assertFalse($this->service->adminUpdate(999, 'da_xong', ''));
        $this->assertSame(0, $this->service->countNew());
        $this->service->adminUpdate($a, 'da_xong', str_repeat('b', 2100));
        $this->assertSame(2000, mb_strlen($this->service->adminDetail($a)['admin_note']));
    }
}
