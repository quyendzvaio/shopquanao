<?php

final class OrderService
{
    private OrderRepository $orderRows;
    private CartRepository $cartRows;
    private ProductRepository $products;

    public function __construct(private PDO $pdo)
    {
        $this->orderRows = new OrderRepository($pdo);
        $this->cartRows = new CartRepository($pdo);
        $this->products = new ProductRepository($pdo);
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
        $orders = array_map([$this, 'castOrder'], $this->orderRows->listForUser($userId, $status));
        $row = $this->orderRows->completedStats($userId);
        return [
            'orders' => $orders,
            'stats' => [
                'total_spent' => (float) ($row['total_spent'] ?? 0),
                'completed_count' => (int) ($row['completed_count'] ?? 0),
            ],
            'message' => $orders === [] ? 'Bạn chưa có đơn hàng nào.' : 'Đã tải danh sách đơn hàng.',
        ];
    }

    public function detail(int|string $userId, int $orderId): array
    {
        $order = $this->orderRows->findForUser($orderId, $userId);
        if (!$order) {
            throw new RuntimeException('Order not found');
        }
        $order = $this->castOrder($order);
        $order['items'] = array_map(function (array $item): array {
            $item['id'] = (int) $item['id'];
            $item['product_id'] = (int) $item['product_id'];
            $item['quantity'] = (int) $item['quantity'];
            $item['price'] = (float) $item['price'];
            return $item;
        }, $this->orderRows->itemsForOrder($orderId));
        return ['order' => $order];
    }

    public function create(int $userId): array
    {
        $this->pdo->beginTransaction();
        try {
            $forUpdate = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
            $items = $this->orderRows->cartLinesForCheckout($userId, $forUpdate);
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
            $orderId = $this->orderRows->insertOrder($userId, $total);
            foreach ($items as $item) {
                $this->orderRows->insertItem($orderId, (int) $item['product_id'], (int) $item['quantity'], (float) $item['price']);
                $quantity = (int) $item['quantity'];
                if (!$this->products->decrementStock((int) $item['product_id'], $quantity)) {
                    throw new RuntimeException('Stock changed while creating the order');
                }
            }
            $this->cartRows->clear($userId);
            $this->pdo->commit();
            return ['message' => 'Order placed successfully', 'order_id' => $orderId, 'total_price' => round($total, 2)];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return list<array<string,mixed>> every order with its customer's username, newest first (admin) */
    public function allWithUsername(): array
    {
        return $this->orderRows->allWithUsername();
    }

    /**
     * $status is the raw posted value (public/admin/manage_orders.php has no whitelist).
     *
     * @return int rows changed: 1, or 0 when the order is missing or already has that status (admin)
     */
    public function updateStatus(int $orderId, mixed $status): int
    {
        return $this->orderRows->updateStatus($orderId, $status);
    }

    public function count(): int
    {
        return $this->orderRows->count();
    }

    public function completedRevenue(): float
    {
        return $this->orderRows->completedRevenue();
    }

    private function castOrder(array $order): array
    {
        $order['id'] = (int) $order['id'];
        $order['total_price'] = (float) $order['total_price'];
        return $order;
    }

    /**
     * public/profile.php: the user's orders with every column, newest first; the filter is the raw ?status= value.
     *
     * @return list<array<int|string,mixed>>
     */
    public function listForProfilePage(int|string $userId, mixed $statusFilter): array
    {
        return $this->orderRows->listForProfilePage($userId, $statusFilter);
    }

    /** @return array<string,mixed> total_spent and completed_count of the user's completed orders (empty if none) */
    public function completedStats(int|string $userId): array
    {
        return $this->orderRows->completedStats($userId);
    }
}
