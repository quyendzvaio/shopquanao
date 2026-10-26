<?php

final class CartService
{
    private CartRepository $cartRows;
    private ProductRepository $products;

    public function __construct(private PDO $pdo)
    {
        $this->cartRows = new CartRepository($pdo);
        $this->products = new ProductRepository($pdo);
    }

    public function list(int $userId): array
    {
        $items = $this->cartRows->itemsWithProduct($userId);
        $total = 0.0;
        foreach ($items as &$item) {
            $item['cart_id'] = (int) $item['cart_id'];
            $item['product_id'] = (int) $item['product_id'];
            $item['price'] = (float) $item['price'];
            $item['quantity'] = (int) $item['quantity'];
            $item['subtotal'] = (float) $item['subtotal'];
            $total += $item['subtotal'];
        }
        unset($item);
        return ['cart' => $items, 'total' => round($total, 2)];
    }

    public function add(int $userId, array $arguments): array
    {
        $productId = (int) ($arguments['product_id'] ?? 0);
        $quantity = (int) ($arguments['quantity'] ?? 1);
        // No size (absent, null or blank): a re-add keeps the line's size and a new line gets 'S'; a size replaces it
        $size = strtoupper(trim((string) ($arguments['size'] ?? '')));
        $sizeGiven = $size !== '';
        if ($productId <= 0 || $quantity <= 0) {
            throw new InvalidArgumentException('product_id and a positive quantity are required');
        }

        $transactionStarted = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $transactionStarted = true;
        }
        try {
            $product = $this->products->idAndStock($productId);
            if (!$product) {
                throw new RuntimeException('Product not found');
            }
            if ((int) $product['stock'] < $quantity) {
                throw new RuntimeException('Insufficient product stock');
            }

            $existing = $this->cartRows->findLine($userId, $productId);
            if ($existing) {
                $newQuantity = (int) $existing['quantity'] + $quantity;
                if ($newQuantity > (int) $product['stock']) {
                    throw new RuntimeException('Insufficient product stock');
                }
                if ($sizeGiven) {
                    $this->cartRows->updateQuantityAndSize((int) $existing['id'], $newQuantity, $size);
                } else {
                    $this->cartRows->setQuantity((int) $existing['id'], $newQuantity);
                }
                $cartId = (int) $existing['id'];
            } else {
                $cartId = $this->cartRows->insertLineWithSize($userId, $productId, $quantity, $sizeGiven ? $size : 'S');
            }
            if ($transactionStarted) {
                $this->pdo->commit();
            }
        } catch (Throwable $error) {
            if ($transactionStarted && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        return ['message' => 'Added to cart', 'cart_id' => $cartId] + $this->list($userId);
    }

    public function update(int $userId, array $arguments): array
    {
        $cartId = (int) ($arguments['cart_id'] ?? 0);
        if ($cartId <= 0) {
            throw new InvalidArgumentException('cart_id is required');
        }
        $item = $this->cartRows->findOwnedLine($cartId, $userId);
        if (!$item) {
            throw new RuntimeException('Cart item not found');
        }
        if (array_key_exists('quantity', $arguments)) {
            $quantity = (int) $arguments['quantity'];
            if ($quantity <= 0) {
                throw new InvalidArgumentException('quantity must be positive');
            }
            $stock = $this->products->stockById((int) $item['product_id']) ?? 0;
            if ($quantity > $stock) {
                throw new RuntimeException('Insufficient product stock');
            }
            $this->cartRows->setQuantity($cartId, $quantity);
        }
        if (array_key_exists('size', $arguments)) {
            $size = strtoupper(trim((string) $arguments['size']));
            if ($size === '') {
                throw new InvalidArgumentException('size cannot be empty');
            }
            $this->cartRows->setSize($cartId, $size);
        }
        return ['message' => 'Cart updated'] + $this->list($userId);
    }

    public function remove(int $userId, int $cartId): array
    {
        if ($this->cartRows->deleteLine($cartId, $userId) === 0) {
            throw new RuntimeException('Cart item not found');
        }
        return ['message' => 'Removed from cart'] + $this->list($userId);
    }

    /** Units in the user's cart (0 when it is empty), for the header badge. */
    public function itemCount(int $userId): int
    {
        return $this->cartRows->totalQuantity($userId) ?? 0;
    }

    /**
     * public/cart.php's list, unordered and uncast, exactly as the page rendered it.
     *
     * @return list<array<string,mixed>>
     */
    public function itemsForCartPage(int|string $userId): array
    {
        return $this->cartRows->itemsForCartPage($userId);
    }

    /** @return list<array<string,mixed>> public/checkout.php's order summary lines */
    public function itemsForCheckoutPage(int|string $userId): array
    {
        return $this->cartRows->itemsForCheckoutPage($userId);
    }

    /** Deletes the user's cart line without throwing when it is missing (the cart page's POST remove form, which carries a CSRF token). */
    public function removeLine(int|string $userId, int $cartId): void
    {
        $this->cartRows->deleteLine($cartId, $userId);
    }

    /**
     * D4 (Part B plan): public/update_cart.php writes the quantity with no stock check, as the page always did.
     * It does write only an integer of at least 1 (an int, or a string of digits only); anything else, such as -5,
     * 0 or text, is a no-op, because a stored non-positive quantity would corrupt stock and totals at checkout.
     */
    public function setQuantityUnchecked(mixed $cartId, mixed $quantity, mixed $userId): void
    {
        $quantity = self::positiveQuantity($quantity);
        if ($quantity === null) {
            return;
        }
        $this->cartRows->setQuantityUnchecked($quantity, $cartId, $userId);
    }

    /** @return int|null the value as an int when it is an int >= 1 or a digit-only string for one, else null */
    private static function positiveQuantity(mixed $quantity): ?int
    {
        if (is_string($quantity) && preg_match('/^[0-9]+$/', $quantity) === 1) {
            $digits = ltrim($quantity, '0');
            $quantity = $digits !== '' && (string) (int) $digits === $digits ? (int) $digits : 0; // 0 also rejects an overflow
        }

        return is_int($quantity) && $quantity >= 1 ? $quantity : null;
    }

    /** D4 (Part B plan): public/update_cart.php writes the size unchecked, as the page always did. */
    public function setSizeUnchecked(mixed $cartId, mixed $size, mixed $userId): void
    {
        $this->cartRows->setSizeUnchecked($size, $cartId, $userId);
    }
}
