<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** SupportTicketRepository against the live MariaDB schema (needs the 2026_11_03 migration). Leaves the DB as it found it. */
final class SupportTicketRepositoryTest extends TestCase
{
    private PDO $pdo;
    private SupportTicketRepository $repo;
    private int $userId;
    private int $orderId;

    protected function setUp(): void
    {
        $host = (string) (getenv('DB_HOST') ?: '');
        if ($host === '') {
            $this->markTestSkipped('DB_HOST not set; live repository test skipped');
        }
        $this->pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, getenv('DB_NAME') ?: 'shop_db'),
            (string) (getenv('DB_USER') ?: 'shop_user'),
            (string) (getenv('DB_PASS') ?: 'shop_pass'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->repo = new SupportTicketRepository($this->pdo);
        $name = 'tkttest_' . bin2hex(random_bytes(6));
        $this->pdo->prepare('INSERT INTO users (username, email, password) VALUES (?, ?, ?)')
            ->execute([$name, $name . '@example.test', 'x']);
        $this->userId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO orders (user_id, total_price, status) VALUES (?, 100000, 'Chờ xử lý')")->execute([$this->userId]);
        $this->orderId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $this->pdo->prepare('DELETE FROM support_tickets WHERE user_id = ?')->execute([$this->userId]);
        $this->pdo->prepare('DELETE FROM orders WHERE user_id = ?')->execute([$this->userId]);
        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
    }

    public function testInsertLookupCountAndUpdate(): void
    {
        $open = SupportTicketService::OPEN_STATUSES;
        $id = $this->repo->insert($this->userId, $this->orderId, 'doi_tra', 'đổi size M', 3);
        $this->assertGreaterThan(0, $id);

        $dup = $this->repo->findOpenDuplicate($this->userId, $this->orderId, 'doi_tra', $open);
        $this->assertSame($id, (int) $dup['id']);
        $this->assertSame('moi', $dup['status']);
        $this->assertNull($this->repo->findOpenDuplicate($this->userId, $this->orderId, 'hang_loi', $open));
        $this->assertSame(1, $this->repo->countOpenForUser($this->userId, $open));

        $this->repo->insert($this->userId, null, 'khac', 'hỏi chung', null);
        $this->assertSame(2, $this->repo->countOpenForUser($this->userId, $open));
        $this->assertCount(2, $this->repo->listForUser($this->userId, 5));
        $this->assertNull($this->repo->findForUser($this->userId + 1, $id));
        $this->assertSame($id, (int) $this->repo->findForUser($this->userId, $id)['id']);

        $admin = array_values(array_filter(
            $this->repo->listForAdmin(['moi'], 200, 0),
            fn(array $r): bool => (int) $r['id'] === $id
        ));
        $this->assertCount(1, $admin);
        $this->assertNotEmpty($admin[0]['username']);

        $this->assertGreaterThanOrEqual(1, $this->repo->countForAdmin(['moi']));
        $this->assertSame(0, $this->repo->countForAdmin([]));
        $this->assertCount(1, $this->repo->listForAdmin(['moi'], 1, 0));
        $this->assertSame(2, $this->repo->countForUser($this->userId));
        $this->assertCount(1, $this->repo->listForUserPage($this->userId, 1, 1));
        $this->assertCount(2, $this->repo->listForUserPage($this->userId, 10, 0));

        $this->repo->updateStatusAndNote($id, 'da_xong', 'đã gửi áo');
        $row = $this->repo->findForAdmin($id);
        $this->assertSame('da_xong', $row['status']);
        $this->assertSame('đã gửi áo', $row['admin_note']);
        $this->assertNull($this->repo->findOpenDuplicate($this->userId, $this->orderId, 'doi_tra', $open));
        $this->assertSame(1, $this->repo->countOpenForUser($this->userId, $open));
        $this->assertGreaterThanOrEqual(0, $this->repo->countByStatus('moi'));
    }

    public function testDeletingTheOrderKeepsTheTicket(): void
    {
        $id = $this->repo->insert($this->userId, $this->orderId, 'huy_don', 'huỷ', null);
        $this->pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([$this->orderId]);
        $this->assertNull($this->repo->findForUser($this->userId, $id)['order_id']);
    }
}
