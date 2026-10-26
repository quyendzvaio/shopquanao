<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
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
            new WebRequest('POST', [], ['action' => 'lock', 'id' => '3', 'csrf_token' => 'tok'], []),
            ['role' => 'admin', 'csrf_token' => 'tok']
        ));

        $this->assertSame('manage_users.php?msg=locked', $result->payload());
        $this->assertSame(0, (int) $pdo->query('SELECT status FROM users WHERE id = 3')->fetchColumn());
    }

    public function testAMemberCannotLockTheirOwnRow(): void
    {
        $pdo = $this->db();
        $page = new AdminUsersPage(new UserService($pdo));

        $result = $this->strict(fn () => $page->handle(
            new WebRequest('POST', [], ['action' => 'lock', 'id' => '3', 'csrf_token' => 'tok'], []),
            ['role' => 'admin', 'user_id' => 3, 'csrf_token' => 'tok']
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

    private function statusAndRole(PDO $pdo, int $id): array
    {
        return $pdo->query("SELECT status, role FROM users WHERE id = {$id}")->fetch();
    }

    /** @return array<string,array{0:array<string,string>,1:string,2:int}> action fields, the redirect on success, the member's status before */
    public static function actions(): array
    {
        return [
            'lock' => [['action' => 'lock', 'id' => '3'], 'manage_users.php?msg=locked', 1],
            'unlock' => [['action' => 'unlock', 'id' => '3'], 'manage_users.php?msg=unlocked', 0],
            'role' => [['toggle_role' => 'admin', 'id' => '3'], 'manage_users.php?msg=updated', 1],
        ];
    }

    #[DataProvider('actions')]
    public function testAValidTokenPerformsTheAction(array $fields, string $redirect, int $status): void
    {
        $pdo = $this->db();
        $pdo->exec("UPDATE users SET status = {$status} WHERE id = 3");
        $before = $this->statusAndRole($pdo, 3);

        $result = (new AdminUsersPage(new UserService($pdo)))->handle(
            new WebRequest('POST', [], $fields + ['csrf_token' => 'tok'], []),
            ['role' => 'admin', 'csrf_token' => 'tok']
        );

        $this->assertSame($redirect, $result->payload());
        $this->assertNotSame($before, $this->statusAndRole($pdo, 3));
    }

    #[DataProvider('actions')]
    public function testAMissingOrWrongTokenChangesNothingAndShowsTheList(array $fields, string $redirect, int $status): void
    {
        $pdo = $this->db();
        $pdo->exec("UPDATE users SET status = {$status} WHERE id = 3");
        $before = $this->statusAndRole($pdo, 3);
        $page = new AdminUsersPage(new UserService($pdo));

        foreach ([[], ['csrf_token' => 'forged'], ['csrf_token' => '']] as $token) {
            $result = $page->handle(new WebRequest('POST', [], $fields + $token, []), ['role' => 'admin', 'csrf_token' => 'tok']);

            $this->assertTrue($result->isView());
        }
        $this->assertSame($before, $this->statusAndRole($pdo, 3));
    }

    #[DataProvider('actions')]
    public function testTheOldGetUrlChangesNothingEvenWithAValidTokenInTheQuery(array $fields, string $redirect, int $status): void
    {
        $pdo = $this->db();
        $pdo->exec("UPDATE users SET status = {$status} WHERE id = 3");
        $before = $this->statusAndRole($pdo, 3);
        $page = new AdminUsersPage(new UserService($pdo));
        $session = ['role' => 'admin', 'csrf_token' => 'tok'];

        $viaQuery = $page->handle(new WebRequest('GET', $fields + ['csrf_token' => 'tok'], [], []), $session);
        $viaPostFields = $page->handle(new WebRequest('GET', [], $fields + ['csrf_token' => 'tok'], []), $session);

        $this->assertTrue($viaQuery->isView());
        $this->assertTrue($viaPostFields->isView());
        $this->assertSame($before, $this->statusAndRole($pdo, 3));
    }

    #[DataProvider('actions')]
    public function testASessionWithoutATokenChangesNothing(array $fields, string $redirect, int $status): void
    {
        $pdo = $this->db();
        $pdo->exec("UPDATE users SET status = {$status} WHERE id = 3");
        $before = $this->statusAndRole($pdo, 3);

        (new AdminUsersPage(new UserService($pdo)))->handle(
            new WebRequest('POST', [], $fields + ['csrf_token' => ''], []),
            ['role' => 'admin']
        );

        $this->assertSame($before, $this->statusAndRole($pdo, 3));
    }

    public function testTheListRendersTheActionsAsPostFormsWithTheToken(): void
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

        $this->assertStringNotContainsString('href="?id=', $html);
        $this->assertStringNotContainsString('href="?action=', $html);
        $this->assertGreaterThanOrEqual(4, substr_count($html, 'name="csrf_token"'));
        $this->assertMatchesRegularExpression('/<form method="POST"[^>]*>/', $html);
    }
}
