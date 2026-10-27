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

    /** Posts with the session's CSRF token by default; pass $token = null for none, or another value for a wrong one. */
    private function visit(PDO $pdo, string $method, array $post, array $session, ?string $token = 'tok'): PageResult
    {
        $session['csrf_token'] = 'tok';
        if ($token !== null) {
            $post['csrf_token'] = $token;
        }

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

        $log = tempnam(sys_get_temp_dir(), 'phplog');
        $previous = ini_set('error_log', $log);
        try {
            $result = $this->visit($pdo, 'POST', ['product_id' => '50', 'rating' => '5', 'comment' => 'ok'], ['user_id' => 5]);
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $this->assertSame('halt', $result->kind());
        $this->assertSame('Lỗi hệ thống, vui lòng thử lại sau.', $result->payload());
        $this->assertStringNotContainsStringIgnoringCase('SQLSTATE', $result->payload());
        $this->assertStringNotContainsString('no such table', $result->payload());
        $this->assertStringContainsString('no such table', (string) file_get_contents($log));
        unlink($log);
    }

    public function testMissingOrWrongTokenGoesHomeWithoutWriting(): void
    {
        $pdo = $this->db();
        $valid = ['product_id' => '50', 'rating' => '5', 'comment' => 'ok'];

        foreach ([null, 'forged', ''] as $token) {
            $result = $this->visit($pdo, 'POST', $valid, ['user_id' => 5], $token);

            $this->assertSame('redirect', $result->kind());
            $this->assertSame('index.php', $result->payload());
        }
        $this->assertSame(0, $this->reviewCount($pdo));
    }

    public function testASessionWithoutATokenNeverStoresAReview(): void
    {
        $pdo = $this->db();
        $post = ['product_id' => '50', 'rating' => '5', 'comment' => 'ok', 'csrf_token' => ''];

        $session = ['user_id' => 5];
        $result = (new SubmitReviewAction(new ReviewService($pdo)))->handle(new WebRequest('POST', [], $post, []), $session);

        $this->assertSame('index.php', $result->payload());
        $this->assertSame(0, $this->reviewCount($pdo));
    }
}
