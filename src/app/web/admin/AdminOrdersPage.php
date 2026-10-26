<?php

/**
 * public/admin/manage_orders.php: the order list and the status update (POST with a CSRF token). Only a status in
 * OrderService::STATUSES (the REST API's list) is stored; any other status, or a bad token, redirects without a write.
 */
final class AdminOrdersPage
{
    public function __construct(private OrderService $orders)
    {
    }

    public function handle(WebRequest $request, array $session): PageResult
    {
        $denied = AdminGuard::check($session);
        if ($denied !== null) {
            return $denied;
        }

        // 1. Update the status
        if ($request->method === 'POST' && isset($request->post['update_status'])) {
            $order_id = (int)$request->post['order_id'];
            $new_status = $request->post['status'] ?? null;

            // A missing or wrong CSRF token is the same no-op as an invalid status
            if (
                !Csrf::valid($session, $request->post[Csrf::FIELD] ?? null)
                || !in_array($new_status, OrderService::STATUSES, true)
            ) {
                return PageResult::redirect('manage_orders.php');
            }

            $this->orders->updateStatus($order_id, $new_status);
            return PageResult::redirect('manage_orders.php?msg=updated');
        }

        // 2. The order list, with each customer's username
        return PageResult::view(['orders' => $this->orders->allWithUsername()]);
    }
}
