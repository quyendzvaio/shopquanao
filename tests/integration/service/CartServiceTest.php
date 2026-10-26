<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization of CartService against the live MariaDB schema. Pins today's behaviour so that
 * moving its SQL into repositories can be proven neutral. Leaves the DB as it found it.
 */
final class CartServiceTest extends TestCase
{
    private PDO $pdo;
    private CartService $cart;
    private int $userId;
    /** @var array<int,int> product id => original stock */
    private array $originalStock = [];

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
        $name = 'chartest_' . bin2hex(random_bytes(6));
        $this->pdo->prepare('INSERT INTO users (username, email, password) VALUES (?, ?, ?)')
            ->execute([$name, $name . '@example.test', 'x']);
        $this->userId = (int)$this->pdo->lastInsertId();
        $this->cart = new CartService($this->pdo);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        if (isset($this->userId)) {
            $this->pdo->prepare('DELETE FROM cart WHERE user_id = ?')->execute([$this->userId]);
            $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
        }
        foreach ($this->originalStock as $productId => $stock) {
            $this->pdo->prepare('UPDATE products SET stock = ? WHERE id = ?')->execute([$stock, $productId]);
        }
    }

    public function testAddStoresUppercasedSizeAndMergesQuantity(): void
    {
        $product = $this->product(5);

        $first = $this->cart->add($this->userId, ['product_id' => $product['id'], 'quantity' => 1, 'size' => ' m ']);
        $this->assertSame('Added to cart', $first['message']);
        $this->assertSame(['message', 'cart_id', 'cart', 'total'], array_keys($first));
        $this->assertCount(1, $first['cart']);
        $this->assertSame('M', $first['cart'][0]['size']);
        $this->assertSame(1, $first['cart'][0]['quantity']);
        $this->assertSame($first['cart'][0]['cart_id'], $first['cart_id']);

        $second = $this->cart->add($this->userId, ['product_id' => $product['id'], 'quantity' => 2, 'size' => 'l']);
        $this->assertSame($first['cart_id'], $second['cart_id']);
        $this->assertCount(1, $second['cart']);
        $this->assertSame(3, $second['cart'][0]['quantity']);
        $this->assertSame('L', $second['cart'][0]['size']);
        $this->assertSame(round(3 * $product['price'], 2), $second['total']);
        $this->assertSame(round(3 * $product['price'], 2), $second['cart'][0]['subtotal']);
        $this->assertSame(
            ['cart_id', 'product_id', 'name', 'price', 'image', 'quantity', 'size', 'subtotal'],
            array_keys($second['cart'][0])
        );
    }

    public function testAddWithoutSizeStoresS(): void
    {
        $product = $this->product(5);

        $result = $this->cart->add($this->userId, ['product_id' => $product['id']]);

        $this->assertSame('S', $result['cart'][0]['size']);
        $this->assertSame(1, $result['cart'][0]['quantity']);
    }

    public function testAddRefusesMergedQuantityAboveStock(): void
    {
        $product = $this->product(5);
        $this->setStock($product['id'], 3);
        $this->cart->add($this->userId, ['product_id' => $product['id'], 'quantity' => 2]);

        try {
            $this->cart->add($this->userId, ['product_id' => $product['id'], 'quantity' => 2]);
            $this->fail('expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('Insufficient product stock', $e->getMessage());
        }

        $this->assertSame(2, $this->cart->list($this->userId)['cart'][0]['quantity']);
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function testAddRefusesSingleQuantityAboveStock(): void
    {
        $product = $this->product(5);
        $this->setStock($product['id'], 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Insufficient product stock');
        $this->cart->add($this->userId, ['product_id' => $product['id'], 'quantity' => 2]);
    }

    public function testAddUnknownProduct(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Product not found');
        $this->cart->add($this->userId, ['product_id' => 999999, 'quantity' => 1]);
    }

    public function testAddRejectsNonPositiveQuantity(): void
    {
        $product = $this->product(5);

        foreach ([0, -1] as $quantity) {
            try {
                $this->cart->add($this->userId, ['product_id' => $product['id'], 'quantity' => $quantity]);
                $this->fail('expected InvalidArgumentException');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('product_id and a positive quantity are required', $e->getMessage());
            }
        }
        $this->assertSame([], $this->cart->list($this->userId)['cart']);
    }

    public function testUpdateQuantityAndSize(): void
    {
        $product = $this->product(5);
        $cartId = $this->cart->add($this->userId, ['product_id' => $product['id'], 'quantity' => 1, 'size' => 'S'])['cart_id'];

        $result = $this->cart->update($this->userId, ['cart_id' => $cartId, 'quantity' => 3, 'size' => ' xl ']);

        $this->assertSame('Cart updated', $result['message']);
        $this->assertSame(3, $result['cart'][0]['quantity']);
        $this->assertSame('XL', $result['cart'][0]['size']);
        $this->assertSame(round(3 * $product['price'], 2), $result['total']);

        try {
            $this->cart->update($this->userId, ['cart_id' => $cartId, 'quantity' => $product['stock'] + 1]);
            $this->fail('expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('Insufficient product stock', $e->getMessage());
        }
        try {
            $this->cart->update($this->userId, ['cart_id' => $cartId, 'quantity' => 0]);
            $this->fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('quantity must be positive', $e->getMessage());
        }
        try {
            $this->cart->update($this->userId, ['cart_id' => $cartId, 'size' => '  ']);
            $this->fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('size cannot be empty', $e->getMessage());
        }
    }

    public function testRemoveMissingLineThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cart item not found');
        $this->cart->remove($this->userId, 999999);
    }

    public function testRemoveDeletesOwnLine(): void
    {
        $product = $this->product(5);
        $cartId = $this->cart->add($this->userId, ['product_id' => $product['id']])['cart_id'];

        $result = $this->cart->remove($this->userId, $cartId);

        $this->assertSame('Removed from cart', $result['message']);
        $this->assertSame([], $result['cart']);
        $this->assertSame(0.0, $result['total']);
    }

    /** @return array{id:int, price:float, stock:int} */
    private function product(int $minStock): array
    {
        $row = $this->pdo->query('SELECT id, price, stock FROM products WHERE stock >= ' . $minStock . ' ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, 'no product with enough stock');
        $product = ['id' => (int)$row['id'], 'price' => (float)$row['price'], 'stock' => (int)$row['stock']];
        $this->originalStock[$product['id']] ??= $product['stock'];
        return $product;
    }

    private function setStock(int $productId, int $stock): void
    {
        $this->pdo->prepare('UPDATE products SET stock = ? WHERE id = ?')->execute([$stock, $productId]);
    }
}
