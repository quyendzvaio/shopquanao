<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CartRepositoryTest extends TestCase
{
    public function testLinesAreInsertedFoundUpdatedAndDeletedPerUser(): void
    {
        $pdo = SqliteShop::pdo();
        SqliteShop::product($pdo, 7, 1, 'Áo', 100000, 5, '');
        $repository = new CartRepository($pdo);

        $cartId = $repository->insertLineWithSize(1, 7, 2, 'M');
        $this->assertSame(1, $cartId);
        $this->assertSame(['id' => 1, 'quantity' => 2], $repository->findLine(1, 7));
        $this->assertNull($repository->findLine(2, 7));
        $this->assertSame(['id' => 1, 'product_id' => 7], $repository->findOwnedLine($cartId, 1));
        $this->assertNull($repository->findOwnedLine($cartId, 2));

        $repository->updateQuantityAndSize($cartId, 3, 'L');
        $repository->setQuantity($cartId, 4);
        $repository->setSize($cartId, 'XL');
        $items = $repository->itemsWithProduct(1);
        $this->assertCount(1, $items);
        $this->assertSame(4, (int)$items[0]['quantity']);
        $this->assertSame('XL', $items[0]['size']);
        $this->assertEquals(400000, $items[0]['subtotal']);

        $this->assertSame(0, $repository->deleteLine($cartId, 2));
        $this->assertSame(1, $repository->deleteLine($cartId, 1));
        $this->assertSame([], $repository->itemsWithProduct(1));
    }

    public function testTotalQuantityAndClear(): void
    {
        $pdo = SqliteShop::pdo();
        $repository = new CartRepository($pdo);
        $this->assertNull($repository->totalQuantity(1));

        $repository->insertLineWithSize(1, 7, 2, 'M');
        $repository->insertLineWithSize(1, 8, 3, 'S');
        $repository->insertLineWithSize(2, 7, 9, 'S');
        $this->assertSame(5, $repository->totalQuantity(1));

        $repository->clear(1);
        $this->assertNull($repository->totalQuantity(1));
        $this->assertSame(9, $repository->totalQuantity(2));
    }
}
