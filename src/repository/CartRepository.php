<?php

/** Reads and writes the cart table, joined with products where a caller needs prices. */
final class CartRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> the user's cart lines with product name, price, image and subtotal */
    public function itemsWithProduct(int $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT c.id AS cart_id, c.product_id, p.name, p.price, p.image,
                c.quantity, c.size, (p.price * c.quantity) AS subtotal
            FROM cart c JOIN products p ON c.product_id = p.id
            WHERE c.user_id = ? ORDER BY c.id");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{id:mixed,quantity:mixed}|null the user's line for a product */
    public function findLine(int $userId, int $productId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, quantity FROM cart WHERE user_id = ? AND product_id = ?');
        $stmt->execute([$userId, $productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array{id:mixed,product_id:mixed}|null the line, only if it belongs to the user */
    public function findOwnedLine(int $cartId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, product_id FROM cart WHERE id = ? AND user_id = ?');
        $stmt->execute([$cartId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function updateQuantityAndSize(int $cartId, int $quantity, string $size): void
    {
        $this->pdo->prepare('UPDATE cart SET quantity = ?, size = ? WHERE id = ?')
            ->execute([$quantity, $size, $cartId]);
    }

    /** @return int the new cart line id */
    public function insertLineWithSize(int $userId, int $productId, int $quantity, string $size): int
    {
        $this->pdo->prepare('INSERT INTO cart (user_id, product_id, quantity, size) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $productId, $quantity, $size]);
        return (int) $this->pdo->lastInsertId();
    }

    public function setQuantity(int $cartId, int $quantity): void
    {
        $this->pdo->prepare('UPDATE cart SET quantity = ? WHERE id = ?')->execute([$quantity, $cartId]);
    }

    public function setSize(int $cartId, string $size): void
    {
        $this->pdo->prepare('UPDATE cart SET size = ? WHERE id = ?')->execute([$size, $cartId]);
    }

    /** @return int lines deleted (0 when the line is missing or not the user's) */
    public function deleteLine(int $cartId, int $userId): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM cart WHERE id = ? AND user_id = ?');
        $stmt->execute([$cartId, $userId]);
        return $stmt->rowCount();
    }

    public function clear(int $userId): void
    {
        $this->pdo->prepare('DELETE FROM cart WHERE user_id = ?')->execute([$userId]);
    }

    /** Units in the user's cart (the header badge); null when the cart is empty. */
    public function totalQuantity(int $userId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT SUM(quantity) as total FROM cart WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return isset($row['total']) ? (int) $row['total'] : null;
    }
}
