<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AdminDashboardPageTest extends TestCase
{
    private function db(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, total_price REAL, status TEXT)');
        return $pdo;
    }

    private function visit(PDO $pdo, array $session): PageResult
    {
        return (new AdminDashboardPage(new AdminDashboardService($pdo)))->handle(new WebRequest('GET', [], [], []), $session);
    }

    public function testGuestIsSentToTheAdminLogin(): void
    {
        $this->assertSame('login.php', $this->visit($this->db(), [])->payload());
    }

    public function testAdminSeesTheCounts(): void
    {
        $result = $this->visit($this->db(), ['admin_logged_in' => true]);

        $this->assertTrue($result->isView());
        $this->assertSame(0, $result->data()['total_products']);
    }

    public function testDatabaseErrorHaltsWithTheGenericMessageAndNoSqlText(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'phplog');
        $previous = ini_set('error_log', $log);
        try {
            $pdo = $this->db();
            $pdo->exec('DROP TABLE users');

            $result = $this->visit($pdo, ['admin_logged_in' => true]);
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $this->assertSame('halt', $result->kind());
        $this->assertSame('Lỗi hệ thống, vui lòng thử lại sau.', $result->payload());
        $this->assertStringNotContainsString('Lỗi kết nối dữ liệu', $result->payload());
        $this->assertStringNotContainsStringIgnoringCase('SQLSTATE', $result->payload());
        $this->assertStringNotContainsString('users', $result->payload());
        $this->assertStringContainsString('no such table', (string) file_get_contents($log));
        unlink($log);
    }
}
