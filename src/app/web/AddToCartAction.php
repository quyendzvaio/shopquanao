<?php

/**
 * public/add_to_cart.php: puts a product in the cart through CartService::add (stock check, size, merged quantity),
 * then goes to the cart, or straight to checkout for "buy now". Any refusal shows the sold-out alert.
 */
final class AddToCartAction
{
    public function __construct(private CartService $cart)
    {
    }

    public function handle(WebRequest $request, array $session): PageResult
    {
        if (!isset($session['user_id'])) {
            return PageResult::output("<script>alert('Vui lòng đăng nhập để mua hàng!'); window.location.href='login.php';</script>");
        }
        if ($request->method !== 'POST') {
            return PageResult::view();
        }

        $post = $request->post;
        $productId = (int) ($post['product_id'] ?? 0);
        try {
            $this->cart->add((int) $session['user_id'], [
                'product_id' => $productId,
                'quantity' => isset($post['quantity']) ? (int) $post['quantity'] : 1,
                'size' => $post['selected_size'] ?? 'S',
            ]);
        } catch (RuntimeException | InvalidArgumentException) {
            return PageResult::output("<script>alert('Sản phẩm đã hết hàng!'); window.location.href='product.php?id={$productId}';</script>");
        }

        return PageResult::redirect(($post['action'] ?? 'add') === 'buy_now' ? 'checkout.php' : 'cart.php');
    }
}
