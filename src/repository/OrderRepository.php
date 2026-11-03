<?php

/** Reads and writes orders and order_items; cartLinesForCheckout reads the cart for the checkout transaction. */
final class OrderRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> the user's orders, newest first, optionally of one status */
    public function listForUser(int $userId, ?string $status = null): array
    {
        $sql = 'SELECT id, total_price, status, created_at FROM orders WHERE user_id = ?';
        $params = [$userId];
        if ($status !== null && $status !== '' && $status !== 'all') {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY created_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed> total_spent and completed_count of the user's completed orders (empty if none) */
    public function completedStats(int|string $userId): array
    {
        $stats = $this->pdo->prepare("SELECT SUM(total_price) AS total_spent, COUNT(*) AS completed_count
            FROM orders WHERE user_id = ? AND status = 'Đã hoàn thành'");
        $stats->execute([$userId]);
        return $stats->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed>|null the order, only if it belongs to the user */
    public function findForUser(int $orderId, int|string $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, total_price, status, created_at FROM orders WHERE id = ? AND user_id = ?');
        $stmt->execute([$orderId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> the order's lines with product name and image */
    public function itemsForOrder(int $orderId): array
    {
        $stmt = $this->pdo->prepare("SELECT oi.id, oi.product_id, oi.quantity, oi.price, p.name, p.image
            FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ?");
        $stmt->execute([$orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The user's cart lines with price and stock for checkout.
     *
     * @param bool $forUpdate lock the rows (MariaDB inside the checkout transaction; SQLite has no FOR UPDATE)
     * @return list<array<string,mixed>>
     */
    public function cartLinesForCheckout(int $userId, bool $forUpdate): array
    {
        $lockClause = $forUpdate ? ' FOR UPDATE' : '';
        $stmt = $this->pdo->prepare("SELECT c.product_id, c.quantity, p.price, p.stock
                FROM cart c JOIN products p ON c.product_id = p.id
                WHERE c.user_id = ?" . $lockClause);
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return int the new order id, status 'Chờ xử lý' */
    public function insertOrder(int $userId, float $total): int
    {
        $this->pdo->prepare("INSERT INTO orders (user_id, total_price, status, created_at) VALUES (?, ?, 'Chờ xử lý', CURRENT_TIMESTAMP)")
            ->execute([$userId, $total]);
        return (int) $this->pdo->lastInsertId();
    }

    public function insertItem(int $orderId, int $productId, int $qty, float $price): void
    {
        $this->pdo->prepare('INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)')
            ->execute([$orderId, $productId, $qty, $price]);
    }

    public function count(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    }

    public function completedRevenue(): float
    {
        $stmt = $this->pdo->query("SELECT SUM(total_price) FROM orders WHERE status = 'Đã hoàn thành'");
        return (float) ($stmt->fetchColumn() ?: 0);
    }

    /** @return list<array<string,mixed>> every order with its customer's username, newest first (PDO default fetch mode) */
    public function allWithUsername(): array
    {
        $stmt = $this->pdo->query("SELECT o.*, u.username FROM orders o JOIN users u ON o.user_id = u.id ORDER BY o.id DESC");
        return $stmt->fetchAll();
    }

    /**
     * $status is bound exactly as given: public/admin/manage_orders.php posts it without any whitelist.
     *
     * @return int rows changed (0 when the order is missing or already has that status)
     */
    public function updateStatus(int $id, mixed $status): int
    {
        $stmt = $this->pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
        return $stmt->rowCount();
    }

    /** @return list<array{date:string,product:string}> the user's ten latest purchased lines */
    public function recentPurchases(int $userId): array
    {
        $stmt = $this->pdo->prepare("
                SELECT o.created_at as date, p.name as product
                FROM orders o
                JOIN order_items oi ON oi.order_id = o.id
                JOIN products p ON p.id = oi.product_id
                WHERE o.user_id = ?
                ORDER BY o.created_at DESC
                LIMIT 10
            ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * public/profile.php: the user's orders (all columns), newest first, optionally of one status.
     * The status filter is bound raw; only the literal 'all' means no filter.
     *
     * @return list<array<int|string,mixed>> (PDO default fetch mode)
     */
    public function listForProfilePage(int|string $userId, mixed $statusFilter): array
    {
        $sql = "SELECT * FROM orders WHERE user_id = ?";
        $params = [$userId];

        if ($statusFilter !== 'all') {
            $sql .= " AND status = ?";
            $params[] = $statusFilter;
        }

        $sql .= " ORDER BY created_at DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
