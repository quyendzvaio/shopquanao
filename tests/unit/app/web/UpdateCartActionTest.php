<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class UpdateCartActionTest extends TestCase
{
    private function shop(): PDO
    {
        $pdo = SqliteShop::pdo();
        SqliteShop::product($pdo, 7, 1, 'Áo thun', 150000, 3, '');
        $pdo->exec("INSERT INTO cart (id, user_id, product_id, quantity, size) VALUES (1, 5, 7, 2, 'M'), (2, 6, 7, 1, 'S')");
        return $pdo;
    }

    private function visit(PDO $pdo, array $query, array $session): PageResult
    {
        return (new UpdateCartAction(new CartService($pdo)))->handle(new WebRequest('GET', $query, [], []), $session);
    }

    public function testQtyUpdatesTheQuantityWithoutAStockCheckAndRedirects(): void
    {
        $pdo = $this->shop();

        $result = $this->visit($pdo, ['id' => '1', 'value' => '99', 'type' => 'qty'], ['user_id' => 5]);

        $this->assertSame('cart.php', $result->payload());
        $this->assertSame('redirect', $result->kind());
        $this->assertSame('99', (string) $pdo->query('SELECT quantity FROM cart WHERE id = 1')->fetchColumn());
    }

    public function testAnyOtherTypeUpdatesTheSize(): void
    {
        $pdo = $this->shop();

        $this->visit($pdo, ['id' => '1', 'value' => 'XL', 'type' => 'size'], ['user_id' => 5]);

        $this->assertSame('XL', $pdo->query('SELECT size FROM cart WHERE id = 1')->fetchColumn());
    }

    public function testAnotherUsersLineIsNotTouched(): void
    {
        $pdo = $this->shop();

        $this->visit($pdo, ['id' => '2', 'value' => '9', 'type' => 'qty'], ['user_id' => 5]);

        $this->assertSame('1', (string) $pdo->query('SELECT quantity FROM cart WHERE id = 2')->fetchColumn());
    }

    public function testMissingParametersOrGuestStillRedirectWithoutWriting(): void
    {
        $pdo = $this->shop();

        foreach ([
            [['id' => '1', 'type' => 'qty'], ['user_id' => 5]],
            [['id' => '1', 'value' => '0', 'type' => 'qty'], ['user_id' => 5]],
            [['id' => '1', 'value' => '9', 'type' => 'qty'], []],
        ] as [$query, $session]) {
            $this->assertSame('cart.php', $this->visit($pdo, $query, $session)->payload());
        }
        $this->assertSame('2', (string) $pdo->query('SELECT quantity FROM cart WHERE id = 1')->fetchColumn());
    }
}
