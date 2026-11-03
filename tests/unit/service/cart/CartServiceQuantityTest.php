<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CartServiceQuantityTest extends TestCase
{
    private function shop(): PDO
    {
        $pdo = SqliteShop::pdo();
        SqliteShop::product($pdo, 7, 1, 'Áo thun', 150000, 3, '');
        $pdo->exec("INSERT INTO cart (id, user_id, product_id, quantity, size) VALUES (1, 5, 7, 2, 'M')");

        return $pdo;
    }

    private function quantity(PDO $pdo): int
    {
        return (int) $pdo->query('SELECT quantity FROM cart WHERE id = 1')->fetchColumn();
    }

    /** @return array<string,array{0:mixed}> */
    public static function rejected(): array
    {
        return [
            'negative int' => [-5], 'zero int' => [0], 'negative string' => ['-5'], 'zero string' => ['0'], 'text' => ['abc'],
            'empty' => [''], 'null' => [null], 'float' => [3.0], 'decimal string' => ['3.5'], 'bool' => [true], 'array' => [[3]],
            'overflowing digits' => ['99999999999999999999'],
        ];
    }

    #[DataProvider('rejected')]
    public function testOnlyAnIntegerOfAtLeastOneIsWritten(mixed $quantity): void
    {
        $pdo = $this->shop();

        (new CartService($pdo))->setQuantityUnchecked(1, $quantity, 5);

        $this->assertSame(2, $this->quantity($pdo));
    }

    public function testAnIntOrADigitStringIsWrittenWithoutAStockCheck(): void
    {
        $pdo = $this->shop();
        $cart = new CartService($pdo);

        $cart->setQuantityUnchecked(1, 3, 5);
        $this->assertSame(3, $this->quantity($pdo));

        $cart->setQuantityUnchecked('1', '99', 5);
        $this->assertSame(99, $this->quantity($pdo));
    }
}
