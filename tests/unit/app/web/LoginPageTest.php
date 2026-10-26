<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LoginPageTest extends TestCase
{
    private function db(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, email TEXT, password TEXT,
            role TEXT DEFAULT 'user', status INTEGER DEFAULT 1, api_token TEXT)");
        $insert = $pdo->prepare('INSERT INTO users (id, username, email, password, role, status) VALUES (?, ?, ?, ?, ?, ?)');
        $insert->execute([1, 'An', 'an@example.test', password_hash('good-pass', PASSWORD_DEFAULT), 'user', 1]);
        $insert->execute([2, 'Locked', 'locked@example.test', password_hash('good-pass', PASSWORD_DEFAULT), 'user', 0]);
        $insert->execute([3, 'dbadmin', 'dbadmin@example.test', password_hash('admin-pass', PASSWORD_DEFAULT), 'admin', 1]);
        return $pdo;
    }

    private function visit(PDO $pdo, AppConfig $config, array $post, array &$session): PageResult
    {
        return (new LoginPage(new UserService($pdo), $config))->handle(new WebRequest('POST', [], $post, []), $session);
    }

    public function testEnvAdminSetsSessionAndRedirectsToAdmin(): void
    {
        $session = [];
        $config = new AppConfig(adminEmail: 'boss@example.test', adminPassword: 'env-pass');

        $result = $this->visit($this->db(), $config, ['email' => ' boss@example.test ', 'password' => 'env-pass'], $session);

        $this->assertSame('redirect', $result->kind());
        $this->assertSame('admin/index.php', $result->payload());
        $this->assertSame(
            ['user_id' => 0, 'username' => 'Admin Shop', 'role' => 'admin', 'admin_logged_in' => true],
            $session
        );
        $this->assertSame(array_keys($session), ['user_id', 'username', 'role', 'admin_logged_in']);
    }

    public function testDatabaseAdminWhenNoEnvPasswordIsSet(): void
    {
        $session = [];

        $result = $this->visit($this->db(), new AppConfig(), ['email' => 'dbadmin', 'password' => 'admin-pass'], $session);

        $this->assertSame('admin/index.php', $result->payload());
        $this->assertSame(0, $session['user_id']);
        $this->assertTrue($session['admin_logged_in']);
    }

    public function testCustomerLoginWritesSessionAndRotatesToken(): void
    {
        $pdo = $this->db();
        $session = [];

        $result = $this->visit($pdo, new AppConfig(), ['email' => 'an@example.test', 'password' => 'good-pass'], $session);

        $this->assertSame('index.php', $result->payload());
        $this->assertSame(['user_id', 'username', 'role', 'api_token'], array_keys($session));
        $this->assertEquals(1, $session['user_id']);
        $this->assertSame('An', $session['username']);
        $this->assertSame('user', $session['role']);
        $this->assertSame(64, strlen($session['api_token']));
        $this->assertSame($session['api_token'], $pdo->query('SELECT api_token FROM users WHERE id = 1')->fetchColumn());
    }

    public function testLockedUserSeesTheLockedMessage(): void
    {
        $session = [];

        $result = $this->visit($this->db(), new AppConfig(), ['email' => 'locked@example.test', 'password' => 'good-pass'], $session);

        $this->assertTrue($result->isView());
        $this->assertSame('Tài khoản của bạn đã bị khóa. Vui lòng liên hệ Admin!', $result->data()['error']);
        $this->assertSame([], $session);
    }

    public function testWrongPasswordSeesTheGenericMessage(): void
    {
        $session = [];

        $result = $this->visit($this->db(), new AppConfig(), ['email' => 'an@example.test', 'password' => 'nope'], $session);

        $this->assertSame('Tài khoản hoặc mật khẩu không chính xác!', $result->data()['error']);
        $this->assertSame([], $session);
    }

    public function testGetShowsTheFormAndTheRegisterBanner(): void
    {
        $session = [];
        $page = new LoginPage(new UserService($this->db()), new AppConfig());

        $plain = $page->handle(new WebRequest('GET', [], [], []), $session)->data();
        $banner = $page->handle(new WebRequest('GET', ['register_success' => '1'], [], []), $session)->data();

        $this->assertSame('', $plain['error']);
        $this->assertFalse($plain['registerSuccess']);
        $this->assertTrue($banner['registerSuccess']);
    }

    public function testCustomerLoginDropsAStaleAdminFlagFromAnEarlierAdminLogin(): void
    {
        $session = ['user_id' => 0, 'username' => 'Admin Shop', 'role' => 'admin', 'admin_logged_in' => true];

        $result = $this->visit($this->db(), new AppConfig(), ['email' => 'an@example.test', 'password' => 'good-pass'], $session);

        $this->assertSame('index.php', $result->payload());
        $this->assertArrayNotHasKey('admin_logged_in', $session);
        $this->assertSame('user', $session['role']);
        $this->assertNotNull(AdminGuard::check($session), 'the unified admin guard no longer lets this customer in');
    }

    public function testAFailedCustomerLoginKeepsTheExistingAdminSession(): void
    {
        $session = ['user_id' => 0, 'role' => 'admin', 'admin_logged_in' => true];

        $this->visit($this->db(), new AppConfig(), ['email' => 'an@example.test', 'password' => 'nope'], $session);

        $this->assertTrue($session['admin_logged_in']);
    }
}
