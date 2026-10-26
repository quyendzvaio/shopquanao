<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AdminUsersPageTest extends TestCase
{
    private function db(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, email TEXT, role TEXT DEFAULT 'user',
            status INTEGER DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec("INSERT INTO users (id, username, email) VALUES (3, 'lan', 'lan@example.test'), (4, 'minh', 'minh@example.test')");
        return $pdo;
    }

    /** Runs $run with every PHP warning/notice turned into an exception. */
    private function strict(callable $run): mixed
    {
        set_error_handler(static function (int $no, string $msg): never {
            throw new ErrorException($msg, 0, $no);
        });
        try {
            return $run();
        } finally {
            restore_error_handler();
        }
    }

    public function testAnEnvAdminWithoutAUserIdCanLockAMember(): void
    {
        $pdo = $this->db();
        $page = new AdminUsersPage(new UserService($pdo));

        $result = $this->strict(fn () => $page->handle(
            new WebRequest('GET', ['action' => 'lock', 'id' => '3'], [], []),
            ['role' => 'admin']
        ));

        $this->assertSame('manage_users.php?msg=locked', $result->payload());
        $this->assertSame(0, (int) $pdo->query('SELECT status FROM users WHERE id = 3')->fetchColumn());
    }

    public function testAMemberCannotLockTheirOwnRow(): void
    {
        $pdo = $this->db();
        $page = new AdminUsersPage(new UserService($pdo));

        $result = $this->strict(fn () => $page->handle(
            new WebRequest('GET', ['action' => 'lock', 'id' => '3'], [], []),
            ['role' => 'admin', 'user_id' => 3]
        ));

        $this->assertTrue($result->isView());
        $this->assertSame(1, (int) $pdo->query('SELECT status FROM users WHERE id = 3')->fetchColumn());
    }

    public function testTheListRendersForAnEnvAdminWithoutWarnings(): void
    {
        $page = new AdminUsersPage(new UserService($this->db()));
        $data = $page->handle(new WebRequest('GET', [], [], []), ['role' => 'admin'])->data();

        $html = $this->strict(function () use ($data): string {
            ob_start();
            try {
                extract($data);
                include ROOT_DIR . '/templates/pages/admin/users.php';
            } finally {
                $out = ob_get_clean();
            }
            return (string) $out;
        });

        $this->assertStringContainsString('lan@example.test', $html);
        $this->assertStringContainsString('toggle_role', $html);
    }
}
