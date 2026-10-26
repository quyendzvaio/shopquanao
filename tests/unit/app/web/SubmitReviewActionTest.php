<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SubmitReviewActionTest extends TestCase
{
    private function db(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE reviews (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, user_id INTEGER,
            rating INTEGER, comment TEXT)');
        return $pdo;
    }

    private function visit(PDO $pdo, string $method, array $post, array $session): PageResult
    {
        return (new SubmitReviewAction(new ReviewService($pdo)))->handle(new WebRequest($method, [], $post, []), $session);
    }

    private function reviewCount(PDO $pdo): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM reviews')->fetchColumn();
    }

    public function testValidReviewIsStoredAndRedirectsBackToTheProduct(): void
    {
        $pdo = $this->db();

        $result = $this->visit($pdo, 'POST', ['product_id' => '50', 'rating' => '5', 'comment' => '  Tốt  '], ['user_id' => 5]);

        $this->assertSame('product.php?id=50&msg=success', $result->payload());
        $this->assertSame('Tốt', $pdo->query('SELECT comment FROM reviews')->fetchColumn());
    }

    public function testInvalidOrUnauthenticatedGoesHomeWithoutWriting(): void
    {
        $pdo = $this->db();
        $valid = ['product_id' => '50', 'rating' => '5', 'comment' => 'ok'];

        foreach ([
            ['POST', ['product_id' => '50', 'rating' => '9', 'comment' => 'ok'], ['user_id' => 5]],
            ['POST', ['product_id' => '50', 'rating' => '5', 'comment' => '  '], ['user_id' => 5]],
            ['POST', $valid, []],
            ['GET', $valid, ['user_id' => 5]],
        ] as [$method, $post, $session]) {
            $this->assertSame('index.php', $this->visit($pdo, $method, $post, $session)->payload());
        }
        $this->assertSame(0, $this->reviewCount($pdo));
    }

    public function testDatabaseErrorHaltsWithTheSystemMessage(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $result = $this->visit($pdo, 'POST', ['product_id' => '50', 'rating' => '5', 'comment' => 'ok'], ['user_id' => 5]);

        $this->assertSame('halt', $result->kind());
        $this->assertStringStartsWith('Lỗi hệ thống: ', $result->payload());
    }
}
