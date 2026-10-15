<?php

final class OrderService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function status(?int $userId, array $arguments): array
    {
        if ($userId === null) {
            return ['requires_login' => true, 'message' => 'Bạn cần đăng nhập để kiểm tra đơn hàng.'];
        }
        $orderId = (int) ($arguments['order_id'] ?? 0);
        if ($orderId > 0) {
            try {
                $detail = $this->detail($userId, $orderId);
                return ['orders' => [$detail['order']]];
            } catch (RuntimeException) {
                return ['orders' => [], 'message' => 'Không tìm thấy đơn hàng này trong tài khoản của bạn.'];
            }
        }
        $result = $this->list($userId);
        $result['orders'] = array_slice($result['orders'], 0, 5);
        return $result;
    }

    public function list(int $userId, ?string $status = null): array
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
        $orders = array_map([$this, 'castOrder'], $stmt->fetchAll(PDO::FETCH_ASSOC));
        $stats = $this->pdo->prepare("SELECT SUM(total_price) AS total_spent, COUNT(*) AS completed_count
            FROM orders WHERE user_id = ? AND status = 'Đã hoàn thành'");
        $stats->execute([$userId]);
        $row = $stats->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'orders' => $orders,
            'stats' => [
                'total_spent' => (float) ($row['total_spent'] ?? 0),
                'completed_count' => (int) ($row['completed_count'] ?? 0),
            ],
            'message' => $orders === [] ? 'Bạn chưa có đơn hàng nào.' : 'Đã tải danh sách đơn hàng.',
        ];
    }

    public function detail(int $userId, int $orderId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, total_price, status, created_at FROM orders WHERE id = ? AND user_id = ?');
        $stmt->execute([$orderId, $userId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            throw new RuntimeException('Order not found');
        }
        $order = $this->castOrder($order);
        $stmt = $this->pdo->prepare("SELECT oi.id, oi.product_id, oi.quantity, oi.price, p.name, p.image
            FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ?");
        $stmt->execute([$orderId]);
        $order['items'] = array_map(function (array $item): array {
            $item['id'] = (int) $item['id'];
            $item['product_id'] = (int) $item['product_id'];
            $item['quantity'] = (int) $item['quantity'];
            $item['price'] = (float) $item['price'];
            return $item;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        return ['order' => $order];
    }

    public function create(int $userId): array
    {
        $this->pdo->beginTransaction();
        try {
            $lockClause = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $this->pdo->prepare("SELECT c.product_id, c.quantity, p.price, p.stock
                FROM cart c JOIN products p ON c.product_id = p.id
                WHERE c.user_id = ?" . $lockClause);
            $stmt->execute([$userId]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($items === []) {
                throw new RuntimeException('Cart is empty');
            }
            $total = 0.0;
            foreach ($items as $item) {
                if ((int) $item['quantity'] > (int) $item['stock']) {
                    throw new RuntimeException('Insufficient stock for product ' . (int) $item['product_id']);
                }
                $total += (float) $item['price'] * (int) $item['quantity'];
            }
            $this->pdo->prepare("INSERT INTO orders (user_id, total_price, status, created_at) VALUES (?, ?, 'Chờ xử lý', CURRENT_TIMESTAMP)")
                ->execute([$userId, $total]);
            $orderId = (int) $this->pdo->lastInsertId();
            $insert = $this->pdo->prepare('INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)');
            $decrement = $this->pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?');
            foreach ($items as $item) {
                $insert->execute([$orderId, (int) $item['product_id'], (int) $item['quantity'], (float) $item['price']]);
                $quantity = (int) $item['quantity'];
                $decrement->execute([$quantity, (int) $item['product_id'], $quantity]);
                if ($decrement->rowCount() !== 1) {
                    throw new RuntimeException('Stock changed while creating the order');
                }
            }
            $this->pdo->prepare('DELETE FROM cart WHERE user_id = ?')->execute([$userId]);
            $this->pdo->commit();
            return ['message' => 'Order placed successfully', 'order_id' => $orderId, 'total_price' => round($total, 2)];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function castOrder(array $order): array
    {
        $order['id'] = (int) $order['id'];
        $order['total_price'] = (float) $order['total_price'];
        return $order;
    }
}
