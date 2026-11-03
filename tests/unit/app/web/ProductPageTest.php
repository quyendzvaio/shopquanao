<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProductPageTest extends TestCase
{
    private function page(PDO $pdo): ProductPage
    {
        return new ProductPage(new ProductService($pdo), new ReviewService($pdo));
    }

    private function request(array $query): WebRequest
    {
        return new WebRequest('GET', $query, [], []);
    }

    private function shop(): PDO
    {
        $pdo = SqliteShop::pdo();
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT)');
        $pdo->exec('CREATE TABLE reviews (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, user_id INTEGER,
            rating INTEGER, comment TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        SqliteShop::product($pdo, 7, 1, 'Áo thun', 150000, 3, '', ['S', 'M']);
        return $pdo;
    }

    public function testUnknownIdIsAViewMarkedNotFound(): void
    {
        $session = [];

        $result = $this->page($this->shop())->handle($this->request(['id' => '999999']), $session);

        $this->assertTrue($result->isView());
        $this->assertTrue($result->data()['notFound']);
    }

    public function testMissingIdIsNotFound(): void
    {
        $session = [];

        $result = $this->page($this->shop())->handle($this->request([]), $session);

        $this->assertTrue($result->data()['notFound']);
    }

    public function testKnownIdProvidesProductSizesAndReviews(): void
    {
        $pdo = $this->shop();
        $pdo->exec("INSERT INTO users (id, username) VALUES (1, 'lan'), (9, 'minh')");
        $pdo->exec("INSERT INTO reviews (id, product_id, user_id, rating, comment, created_at)
            VALUES (1, 7, 9, 5, 'Đẹp', '2026-01-01 10:00:00')");
        $session = ['user_id' => 3];

        $data = $this->page($pdo)->handle($this->request(['id' => '7']), $session)->data();

        $this->assertFalse($data['notFound']);
        $this->assertSame('Áo thun', $data['product']['name']);
        $this->assertSame(['S', 'M'], array_column($data['sizes'], 'size_name'));
        // Reviews join users on r.user_id = u.id: the reviewer is user 9, not the user whose id equals the review id.
        $this->assertCount(1, $data['reviews']);
        $this->assertSame('minh', $data['reviews'][0]['username']);
        $this->assertSame('7', $data['product_id']);
        $this->assertTrue($data['loggedIn']);
    }

    /**
     * Ids bind as raw strings like the old page: "1.5", "abc" and "" match nothing, "50abc" matches id 50.
     * Only inputs that SQLite and MariaDB treat identically are asserted here.
     *
     * @return array<string,array{mixed,bool}>
     */
    public static function rawIds(): array
    {
        return [
            'fraction' => ['1.5', true],
            'letters' => ['abc', true],
            'empty' => ['', true],
            'array' => [['1'], true],
            'exact' => ['50', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rawIds')]
    public function testRawIdsBindLikeTheOldPage(mixed $id, bool $notFound): void
    {
        $pdo = $this->shop();
        SqliteShop::product($pdo, 1, 1, 'Áo một', 100000, 1, '');
        SqliteShop::product($pdo, 50, 1, 'Áo năm mươi', 100000, 1, '');
        $session = [];

        $data = $this->page($pdo)->handle($this->request(['id' => $id]), $session)->data();

        $this->assertSame($notFound, $data['notFound']);
        if (!$notFound) {
            $this->assertSame($id, $data['product_id']);
        }
    }

    public function testGuestIsNotLoggedIn(): void
    {
        $session = [];

        $data = $this->page($this->shop())->handle($this->request(['id' => 7]), $session)->data();

        $this->assertFalse($data['loggedIn']);
    }
}
