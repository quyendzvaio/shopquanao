<?php

/**
 * public/process_checkout.php: turns the cart into an order through OrderService::create (row locks, stock
 * decrement, status 'Chờ xử lý', cart cleared). The shipping fields are posted but, as before, not stored.
 */
final class PlaceOrderAction
{
    public function __construct(private OrderService $orders)
    {
    }

    public function handle(WebRequest $request, array $session): PageResult
    {
        if (
            !isset($session['user_id']) || $request->method !== 'POST'
            || !Csrf::valid($session, $request->post[Csrf::FIELD] ?? null)
        ) {
            return PageResult::redirect('index.php');
        }

        try {
            $this->orders->create((int) $session['user_id']);
        } catch (InsufficientStockException $e) {
            return PageResult::halt("Lỗi đặt hàng: Sản phẩm ID {$e->productId} đã hết hàng!");
        } catch (Throwable $e) {
            return PageResult::halt('Lỗi đặt hàng: ' . ($e->getMessage() === 'Cart is empty'
                ? 'Giỏ hàng của bạn đang trống.'
                : $e->getMessage()));
        }

        return PageResult::output("<script>alert('Đặt hàng thành công! Fashion Shop sẽ liên hệ bạn sớm nhất.'); window.location.href='index.php';</script>");
    }
}
