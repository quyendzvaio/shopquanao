<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * UserService against the live MariaDB schema: API token lookup, registration, login,
 * logout and the admin status/role changes. Leaves the DB as it found it.
 */
final class UserServiceTest extends TestCase
{
    private PDO $pdo;
    private UserService $users;
    /** @var list<int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        $host = (string)(getenv('DB_HOST') ?: '');
        if ($host === '') {
            $this->markTestSkipped('DB_HOST not set; live service test skipped');
        }
        $this->pdo = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, getenv('DB_NAME') ?: 'shop_db'),
            (string)(getenv('DB_USER') ?: 'shop_user'),
            (string)(getenv('DB_PASS') ?: 'shop_pass'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->users = new UserService($this->pdo);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        foreach ($this->userIds as $userId) {
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        }
    }

    public function testUserForApiTokenReturnsTheAuthColumnsOrNull(): void
    {
        $name = $this->uniqueName();
        $registered = $this->users->registerViaApi(['username' => $name, 'email' => $name . '@example.test', 'password' => 'secret1']);

        $user = $this->users->userForApiToken($registered['token']);

        $this->assertNotNull($user);
        $this->assertSame($registered['user']['id'], (int)$user['id']);
        $this->assertSame($name, $user['username']);
        $this->assertSame('user', $user['role']);
        $this->assertSame(1, (int)$user['status']);
        $this->assertNull($this->users->userForApiToken(str_repeat('0', 64)));
    }

    public function testRegisterReturnsTheResponseBodyAndRejectsADuplicateEmail(): void
    {
        $name = $this->uniqueName();
        $body = $this->users->registerViaApi(['username' => $name, 'email' => $name . '@example.test', 'password' => 'secret1']);

        $this->assertSame('Registration successful', $body['message']);
        $this->assertSame(['id' => $body['user']['id'], 'username' => $name, 'email' => $name . '@example.test', 'role' => 'user'], $body['user']);
        $this->assertSame(64, strlen($body['token']));
        $this->userIds[] = $body['user']['id'];

        try {
            $this->users->registerViaApi(['username' => $name . 'x', 'email' => $name . '@example.test', 'password' => 'secret1']);
            $this->fail('duplicate email must be rejected');
        } catch (ApiError $error) {
            $this->assertSame('Email already registered', $error->getMessage());
            $this->assertSame(409, $error->getCode());
        }
    }

    public function testRegisterValidatesItsInput(): void
    {
        $this->assertRegisterFails(['email' => 'a@example.test', 'password' => 'secret1'], 'Missing required fields: username, email, password');
        $this->assertRegisterFails(['username' => 'x', 'email' => 'not-an-email', 'password' => 'secret1'], 'Invalid email format');
        $this->assertRegisterFails(['username' => 'x', 'email' => 'a@example.test', 'password' => '12345'], 'Password must be at least 6 characters');
    }

    public function testLoginIssuesANewTokenAndLogoutClearsIt(): void
    {
        $name = $this->uniqueName();
        $registered = $this->users->registerViaApi(['username' => $name, 'email' => $name . '@example.test', 'password' => 'secret1']);
        $this->userIds[] = $registered['user']['id'];

        $login = $this->users->loginViaApi(['email' => $name, 'password' => 'secret1']);

        $this->assertSame('Login successful', $login['message']);
        $this->assertSame($registered['user'], $login['user']);
        $this->assertNotSame($registered['token'], $login['token']);
        $this->assertNotNull($this->users->userForApiToken($login['token']));

        $this->users->logout($registered['user']['id']);
        $this->assertNull($this->users->userForApiToken($login['token']));
    }

    public function testLoginRejectsBadCredentialsAndLockedAccounts(): void
    {
        $name = $this->uniqueName();
        $registered = $this->users->registerViaApi(['username' => $name, 'email' => $name . '@example.test', 'password' => 'secret1']);
        $id = $registered['user']['id'];
        $this->userIds[] = $id;

        $this->assertLoginFails(['email' => $name, 'password' => 'wrong-pass'], 'Invalid email or password', 401);
        $this->assertLoginFails(['email' => '', 'password' => 'x'], 'Missing email or password', 400);

        $this->assertSame(1, $this->users->setStatus($id, 0));
        $this->assertLoginFails(['email' => $name, 'password' => 'secret1'], 'Account is locked. Contact admin.', 403);
        $this->assertSame(1, $this->users->setStatus($id, 1));
        $this->assertSame('Login successful', $this->users->loginViaApi(['email' => $name, 'password' => 'secret1'])['message']);
    }

    public function testAdminListCountSetStatusAndSetRole(): void
    {
        $name = $this->uniqueName();
        $before = $this->users->count();
        $registered = $this->users->registerViaApi(['username' => $name, 'email' => $name . '@example.test', 'password' => 'secret1']);
        $id = $registered['user']['id'];
        $this->userIds[] = $id;

        $this->assertSame($before + 1, $this->users->count());
        $list = $this->users->adminList();
        $this->assertSame($id, (int)$list[0]['id'], 'newest user first');
        $this->assertSame(['id', 'username', 'email', 'role', 'status', 'created_at'], array_values(array_filter(array_keys($list[0]), 'is_string')));

        $this->assertSame(1, $this->users->setStatus($id, 0));
        $this->assertSame(0, (int)$this->userRow($id)['status']);
        $this->assertSame(1, $this->users->setStatus($id, 1));
        $this->assertSame(1, (int)$this->userRow($id)['status']);

        $this->assertSame(1, $this->users->setRole($id, 'admin'));
        $this->assertSame('admin', $this->userRow($id)['role']);
        $this->assertSame(0, $this->users->setRole($id, 'admin'), 'unchanged row reports no affected rows');
        $this->assertSame(0, $this->users->setRole(2147483000, 'user'), 'unknown user');
    }

    /** @param array<string,mixed> $data */
    private function assertRegisterFails(array $data, string $message): void
    {
        try {
            $this->users->registerViaApi($data);
            $this->fail('registration must be rejected');
        } catch (ApiError $error) {
            $this->assertSame($message, $error->getMessage());
            $this->assertSame(400, $error->getCode());
        }
    }

    /** @param array<string,mixed> $data */
    private function assertLoginFails(array $data, string $message, int $code): void
    {
        try {
            $this->users->loginViaApi($data);
            $this->fail('login must be rejected');
        } catch (ApiError $error) {
            $this->assertSame($message, $error->getMessage());
            $this->assertSame($code, $error->getCode());
        }
    }

    /** @return array<string,mixed> */
    private function userRow(int $id): array
    {
        return $this->pdo->query('SELECT * FROM users WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
    }

    private function uniqueName(): string
    {
        return 'usvc_' . bin2hex(random_bytes(6));
    }
}
