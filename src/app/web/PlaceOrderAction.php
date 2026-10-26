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
        // An admin with no user row (the env admin, who has no user_id key) does not shop: checked first so they never read the guest outcome
        if (AdminGuard::isEnvAdmin($session)) {
            return PageResult::halt('Tài khoản quản trị không dùng để mua hàng.');
        }
        if (!isset($session['user_id'])) {
            return PageResult::redirect('index.php');
        }
        if ($request->method !== 'POST' || !Csrf::valid($session, $request->post[Csrf::FIELD] ?? null)) {
            return PageResult::redirect('index.php');
        }

        try {
            $this->orders->create((int) $session['user_id']);
        } catch (InsufficientStockException $e) {
            return PageResult::halt("Lỗi đặt hàng: Sản phẩm ID {$e->productId} đã hết hàng!");
        } catch (EmptyCartException) {
            return PageResult::halt('Lỗi đặt hàng: Giỏ hàng của bạn đang trống.');
        } catch (PDOException $e) {
            return PageResult::systemError($e, self::class);
        } catch (Throwable $e) {
            error_log(self::class . ' unexpected error: ' . $e->getMessage());

            return PageResult::halt(PageResult::SYSTEM_ERROR);
        }

        return PageResult::output("<script>alert('Đặt hàng thành công! Fashion Shop sẽ liên hệ bạn sớm nhất.'); window.location.href='index.php';</script>");
    }
}
