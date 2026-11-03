<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AdminLoginPageTest extends TestCase
{
    private function db(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, email TEXT, password TEXT, role TEXT DEFAULT 'user',
            status INTEGER DEFAULT 1)");
        $hash = password_hash('db-secret', PASSWORD_DEFAULT);
        $pdo->exec("INSERT INTO users (id, username, email, password, role) VALUES (9, 'dbadmin', 'a@example.test', '{$hash}', 'admin')");

        return $pdo;
    }

    private function login(string $username, string $password, array &$session): PageResult
    {
        $page = new AdminLoginPage(new UserService($this->db()), new AppConfig(adminUser: 'root', adminPassword: 'env-secret'));

        return $page->handle(new WebRequest('POST', [], ['login' => '1', 'username' => $username, 'password' => $password], []), $session);
    }

    public function testTheEnvAdminLoginDropsACustomerSessionInTheSameBrowser(): void
    {
        $session = ['user_id' => 12, 'api_token' => 'customer-token', 'username' => 'khach', 'csrf_token' => 'tok'];

        $result = $this->login('root', 'env-secret', $session);

        $this->assertSame('index.php', $result->payload());
        $this->assertTrue($session['admin_logged_in']);
        $this->assertSame('admin', $session['role']);
        $this->assertSame('Admin Shop', $session['username']);
        $this->assertArrayNotHasKey('user_id', $session);
        $this->assertArrayNotHasKey('api_token', $session);
        $this->assertSame('tok', $session['csrf_token']);
        // With no user_id left, the admin session is an env admin and cannot shop as the customer
        $this->assertTrue(AdminGuard::isEnvAdmin($session));
    }

    public function testTheEnvAdminLoginOnAFreshSessionStillHasNoUserId(): void
    {
        $session = [];

        $this->login('root', 'env-secret', $session);

        $this->assertSame(['admin_logged_in' => true, 'role' => 'admin', 'username' => 'Admin Shop'], $session);
    }

    public function testTheDatabaseAdminLoginSetsItsUserIdAndIsUnchanged(): void
    {
        $session = ['api_token' => 'kept'];

        $result = $this->login('dbadmin', 'db-secret', $session);

        $this->assertSame('index.php', $result->payload());
        $this->assertSame(9, $session['user_id']);
        $this->assertSame('dbadmin', $session['username']);
        $this->assertSame('admin', $session['role']);
        $this->assertTrue($session['admin_logged_in']);
        $this->assertSame('kept', $session['api_token']);
    }

    public function testWrongCredentialsChangeNothing(): void
    {
        $session = ['user_id' => 12];

        $result = $this->login('root', 'nope', $session);

        $this->assertTrue($result->isView());
        $this->assertSame(['user_id' => 12], $session);
        $this->assertSame('Sai tài khoản hoặc mật khẩu!', $result->data()['error']);
    }
}
