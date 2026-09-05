<?php

require_once __DIR__ . '/Fashion/CartItemAddedOutbox.php';
require_once __DIR__ . '/Fashion/ProactiveStylingStateMachine.php';
require_once __DIR__ . '/Fashion/ProactiveStylingStateStore.php';

final class CartService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function list(int $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT c.id AS cart_id, c.product_id, p.name, p.price, p.image,
                c.quantity, c.size, (p.price * c.quantity) AS subtotal
            FROM cart c JOIN products p ON c.product_id = p.id
            WHERE c.user_id = ? ORDER BY c.id");
        $stmt->execute([$userId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
        $size = strtoupper(trim((string) ($arguments['size'] ?? 'S')));
        if ($productId <= 0 || $quantity <= 0) {
            throw new InvalidArgumentException('product_id and a positive quantity are required');
        }

        $transactionStarted = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $transactionStarted = true;
        }
        try {
            $stmt = $this->pdo->prepare('SELECT id, stock FROM products WHERE id = ?');
            $stmt->execute([$productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$product) {
                throw new RuntimeException('Product not found');
            }
            if ((int) $product['stock'] < $quantity) {
                throw new RuntimeException('Insufficient product stock');
            }

            $stmt = $this->pdo->prepare('SELECT id, quantity FROM cart WHERE user_id = ? AND product_id = ?');
            $stmt->execute([$userId, $productId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $newQuantity = (int) $existing['quantity'] + $quantity;
                if ($newQuantity > (int) $product['stock']) {
                    throw new RuntimeException('Insufficient product stock');
                }
                $this->pdo->prepare('UPDATE cart SET quantity = ?, size = ? WHERE id = ?')
                    ->execute([$newQuantity, $size, (int) $existing['id']]);
                $cartId = (int) $existing['id'];
            } else {
                $this->pdo->prepare('INSERT INTO cart (user_id, product_id, quantity, size) VALUES (?, ?, ?, ?)')
                    ->execute([$userId, $productId, $quantity, $size]);
                $cartId = (int) $this->pdo->lastInsertId();
            }
            $sessionId = $this->resolveSessionId($userId, $arguments['session_id'] ?? null);
            $variantId = isset($arguments['variant_id']) ? (int) $arguments['variant_id'] : null;
            $this->publishCartItemAdded($userId, $sessionId, $cartId, $productId, $variantId);
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

    private function publishCartItemAdded(int $userId, string $sessionId, int $cartId, int $productId, ?int $variantId): void
    {
        try {
            $exists = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? $this->pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='fashion_event_outbox'")->fetchColumn()
                : $this->pdo->query("SELECT 1 FROM information_schema.tables WHERE table_name = 'fashion_event_outbox' LIMIT 1")->fetchColumn();
        } catch (Throwable) {
            $exists = false;
        }
        if (!$exists) return;
        $eventId = (new CartItemAddedOutbox($this->pdo))
            ->publish($userId, $sessionId, $cartId, $productId, $variantId);
        $versionStatement = $this->pdo->prepare('SELECT id FROM fashion_event_outbox WHERE event_id = ?');
        $versionStatement->execute([$eventId]);
        $stateVersion = (int) $versionStatement->fetchColumn();

        // Arm the user-visible workflow in the same transaction as the cart
        // mutation. Redis/outbox delivery remains useful for other consumers,
        // but a stopped worker can no longer make UC2 silently disappear.
        $states = new ProactiveStylingStateStore($this->pdo);
        $machine = new ProactiveStylingStateMachine();
        $state = $machine->onCartItemAdded(
            $states->get($userId, $sessionId),
            $productId,
            $variantId,
            $eventId,
            $stateVersion
        );
        $states->put($userId, $sessionId, $state);
    }

    private function resolveSessionId(int $userId, mixed $explicit): string
    {
        if (trim((string)($explicit ?? '')) !== '') return trim((string)$explicit);
        try {
            $stmt=$this->pdo->prepare("SELECT id FROM chat_sessions WHERE user_id=? AND status='active' ORDER BY updated_at DESC LIMIT 1");
            $stmt->execute([$userId]); $sessionId=$stmt->fetchColumn();
            if ($sessionId!==false) return (string)$sessionId;
        } catch (Throwable) {}
        return 'user:'.$userId;
    }

    public function update(int $userId, array $arguments): array
    {
        $cartId = (int) ($arguments['cart_id'] ?? 0);
        if ($cartId <= 0) {
            throw new InvalidArgumentException('cart_id is required');
        }
        $stmt = $this->pdo->prepare('SELECT id, product_id FROM cart WHERE id = ? AND user_id = ?');
        $stmt->execute([$cartId, $userId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            throw new RuntimeException('Cart item not found');
        }
        if (array_key_exists('quantity', $arguments)) {
            $quantity = (int) $arguments['quantity'];
            if ($quantity <= 0) {
                throw new InvalidArgumentException('quantity must be positive');
            }
            $stock = (int) $this->pdo->query('SELECT stock FROM products WHERE id = ' . (int) $item['product_id'])->fetchColumn();
            if ($quantity > $stock) {
                throw new RuntimeException('Insufficient product stock');
            }
            $this->pdo->prepare('UPDATE cart SET quantity = ? WHERE id = ?')->execute([$quantity, $cartId]);
        }
        if (array_key_exists('size', $arguments)) {
            $size = strtoupper(trim((string) $arguments['size']));
            if ($size === '') {
                throw new InvalidArgumentException('size cannot be empty');
            }
            $this->pdo->prepare('UPDATE cart SET size = ? WHERE id = ?')->execute([$size, $cartId]);
        }
        return ['message' => 'Cart updated'] + $this->list($userId);
    }

    public function remove(int $userId, int $cartId): array
    {
        $stmt = $this->pdo->prepare('DELETE FROM cart WHERE id = ? AND user_id = ?');
        $stmt->execute([$cartId, $userId]);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Cart item not found');
        }
        return ['message' => 'Removed from cart'] + $this->list($userId);
    }
}
