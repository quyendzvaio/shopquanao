<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** ReviewService against the live MariaDB schema. Leaves the DB as it found it. */
final class ReviewServiceTest extends TestCase
{
    private PDO $pdo;
    private ReviewService $reviews;
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
        $this->reviews = new ReviewService($this->pdo);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        foreach ($this->userIds as $userId) {
            $this->pdo->prepare('DELETE FROM reviews WHERE user_id = ?')->execute([$userId]);
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        }
    }

    public function testAddThenListWithUsernameThenRatingSummary(): void
    {
        $productId = $this->productWithoutReviews();
        [$userId, $username] = $this->newUser();

        $this->assertSame(['avg_rating' => null, 'total_reviews' => 0], $this->reviews->ratingSummary($productId));

        $this->reviews->add($productId, $userId, 4, 'Vải đẹp');
        $this->reviews->add($productId, $userId, 5, 'Mặc rất thoải mái');

        $list = $this->reviews->forProductWithUsername($productId);
        $this->assertCount(2, $list);
        foreach ($list as $row) {
            $this->assertSame($username, $row['username']);
            $this->assertSame($productId, (int)$row['product_id']);
            $this->assertSame($userId, (int)$row['user_id']);
        }
        $this->assertEqualsCanonicalizing(['Vải đẹp', 'Mặc rất thoải mái'], array_column($list, 'comment'));

        $this->assertSame(['avg_rating' => 4.5, 'total_reviews' => 2], $this->reviews->ratingSummary($productId));
    }

    public function testAddRejectsAnUnknownProduct(): void
    {
        [$userId] = $this->newUser();

        try {
            $this->reviews->add(2147483000, $userId, 5, 'x');
            $this->fail('unknown product must be rejected');
        } catch (ApiError $error) {
            $this->assertSame('Product not found', $error->getMessage());
            $this->assertSame(404, $error->getCode());
        }
        $this->assertSame([], $this->reviews->forProductWithUsername(2147483000));
    }

    private function productWithoutReviews(): int
    {
        $id = $this->pdo->query('SELECT id FROM products WHERE id NOT IN (SELECT product_id FROM reviews WHERE product_id IS NOT NULL) ORDER BY id LIMIT 1')->fetchColumn();
        $this->assertNotFalse($id, 'no product without reviews');
        return (int)$id;
    }

    /** @return array{0:int,1:string} */
    private function newUser(): array
    {
        $name = 'rsvc_' . bin2hex(random_bytes(6));
        $this->pdo->prepare('INSERT INTO users (username, email, password) VALUES (?, ?, ?)')
            ->execute([$name, $name . '@example.test', 'x']);
        $id = (int)$this->pdo->lastInsertId();
        $this->userIds[] = $id;
        return [$id, $name];
    }
}
