<?php

/**
 * public/admin/manage_orders.php: the order list and the status update. Only a status in OrderService::STATUSES
 * (the REST API's list) is stored; any other redirects without a write.
 */
final class AdminOrdersPage
{
    public function __construct(private OrderService $orders)
    {
    }

    public function handle(WebRequest $request, array $session): PageResult
    {
        $denied = AdminGuard::check($session, true, 'login.php');
        if ($denied !== null) {
            return $denied;
        }

        // 1. Update the status
        if (isset($request->post['update_status'])) {
            $order_id = (int)$request->post['order_id'];
            $new_status = $request->post['status'] ?? null;

            if (!in_array($new_status, OrderService::STATUSES, true)) {
                return PageResult::redirect('manage_orders.php');
            }

            $this->orders->updateStatus($order_id, $new_status);
            return PageResult::redirect('manage_orders.php?msg=updated');
        }

        // 2. The order list, with each customer's username
        return PageResult::view(['orders' => $this->orders->allWithUsername()]);
    }
}
