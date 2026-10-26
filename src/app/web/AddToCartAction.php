<?php

/**
 * public/add_to_cart.php: puts a product in the cart (POST with a CSRF token) through CartService::add (stock check, size, merged quantity),
 * then goes to the cart, or straight to checkout for "buy now". Any refusal shows the sold-out alert.
 */
final class AddToCartAction
{
    public function __construct(private CartService $cart)
    {
    }

    public function handle(WebRequest $request, array $session): PageResult
    {
        // An admin with no user row (the env admin, who has no user_id key) does not shop: checked first so they never read the guest message
        if (AdminGuard::isEnvAdmin($session)) {
            return PageResult::output("<script>alert('Tài khoản quản trị không dùng để mua hàng.'); window.location.href='index.php';</script>");
        }
        if (!isset($session['user_id'])) {
            return PageResult::output("<script>alert('Vui lòng đăng nhập để mua hàng!'); window.location.href='login.php';</script>");
        }
        // Only a POST with the session's CSRF token adds; anything else is the old no-op (an empty view)
        if ($request->method !== 'POST' || !Csrf::valid($session, $request->post[Csrf::FIELD] ?? null)) {
            return PageResult::view();
        }

        $post = $request->post;
        $productId = (int) ($post['product_id'] ?? 0);
        try {
            $this->cart->add((int) $session['user_id'], [
                'product_id' => $productId,
                'quantity' => isset($post['quantity']) ? (int) $post['quantity'] : 1,
                'size' => $post['selected_size'] ?? null,
            ]);
        } catch (PDOException $e) {
            return PageResult::systemError($e, self::class);
        } catch (RuntimeException | InvalidArgumentException) {
            return PageResult::output("<script>alert('Sản phẩm đã hết hàng!'); window.location.href='product.php?id={$productId}';</script>");
        }

        return PageResult::redirect(($post['action'] ?? 'add') === 'buy_now' ? 'checkout.php' : 'cart.php');
    }
}
