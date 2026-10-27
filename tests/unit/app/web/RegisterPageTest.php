<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** SQLite reports the last write's change count as a SELECT's rowCount(); MariaDB reports the rows found. This mimics MariaDB. */
final class MariaDbRowCountStatement extends PDOStatement
{
    private array $params = [];

    protected function __construct(private PDO $pdo)
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];
        return parent::execute($params);
    }

    public function rowCount(): int
    {
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM (' . $this->queryString . ')');
        $count->execute($this->params);
        return (int) $count->fetchColumn();
    }
}

final class RegisterPageTest extends TestCase
{
    private function db(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [MariaDbRowCountStatement::class, [$pdo]]);
        $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, email TEXT, password TEXT,
            role TEXT DEFAULT 'user', status INTEGER DEFAULT 1, api_token TEXT)");
        $pdo->exec("INSERT INTO users (username, email, password) VALUES ('An', 'an@example.test', 'x')");
        return $pdo;
    }

    private function visit(PDO $pdo, array $post, string $method = 'POST'): PageResult
    {
        return (new RegisterPage(new UserService($pdo)))->handle(new WebRequest($method, [], $post, []));
    }

    private function form(string $email, string $confirm = 'pw-123'): array
    {
        return ['fullname' => 'Binh', 'email' => $email, 'password' => 'pw-123', 'confirm_password' => $confirm];
    }

    public function testGetShowsAnEmptyForm(): void
    {
        $result = $this->visit($this->db(), [], 'GET');

        $this->assertTrue($result->isView());
        $this->assertSame('', $result->data()['error']);
    }

    public function testMismatchedPasswords(): void
    {
        $this->assertSame('Mật khẩu xác nhận không khớp!', $this->visit($this->db(), $this->form('b@example.test', 'other'))->data()['error']);
    }

    public function testExistingEmail(): void
    {
        $this->assertSame('Email này đã được đăng ký!', $this->visit($this->db(), $this->form('an@example.test'))->data()['error']);
    }

    public function testNewUserIsStoredHashedWithAnApiTokenAndRedirectsToLogin(): void
    {
        $pdo = $this->db();

        $result = $this->visit($pdo, $this->form('b@example.test'));

        $this->assertSame('redirect', $result->kind());
        $this->assertSame('login.php?register_success=1', $result->payload());
        $row = $pdo->query("SELECT * FROM users WHERE email = 'b@example.test'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('Binh', $row['username']);
        $this->assertTrue(password_verify('pw-123', $row['password']));
        $this->assertSame(64, strlen($row['api_token']));
        $this->assertSame('user', $row['role']);
    }
}
