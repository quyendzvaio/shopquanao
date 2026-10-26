<?php

/** public/cart.php: the logged-in user's cart, or a redirect (guest, or after ?remove=). */
final class CartPage
{
    public function __construct(private CartService $cart)
    {
    }

    public function handle(WebRequest $request, array &$session): PageResult
    {
        $user_id = $session['user_id'] ?? null;

        if (!$user_id) {
            return PageResult::redirect('login.php?error=vui_long_dang_nhap');
        }

        if (isset($request->query['remove'])) {
            $this->cart->removeLine($user_id, (int) $request->query['remove']);
            return PageResult::redirect('cart.php');
        }

        return PageResult::view(['cart_items' => $this->cart->itemsForCartPage($user_id)]);
    }
}
