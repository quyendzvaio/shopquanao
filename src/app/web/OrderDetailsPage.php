<?php

/**
 * public/order_details.php: one of the user's orders with its lines. Part B plan D4 exception (ruling R1): the old
 * page queried a table that does not exist and answered 500; the items now come from order_items via OrderService.
 */
final class OrderDetailsPage
{
    public function __construct(private OrderService $orders)
    {
    }

    public function handle(WebRequest $request, array &$session): PageResult
    {
        if (!isset($session['user_id']) || !isset($request->query['id'])) {
            return PageResult::redirect('login.php');
        }

        $order_id = (int) $request->query['id'];

        try {
            $detail = $this->orders->detail($session['user_id'], $order_id)['order'];
        } catch (RuntimeException) {
            return PageResult::halt('Đơn hàng không tồn tại hoặc bạn không có quyền xem.');
        }

        $items = $detail['items'];
        unset($detail['items']);

        return PageResult::view(['order_id' => $order_id, 'order' => $detail, 'items' => $items]);
    }
}
