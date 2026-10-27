<?php

/** public/cart.php: the logged-in user's cart, or a redirect (guest, or after a remove POST). */
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

        // Removing is a POST with the session's CSRF token; the old ?remove= URL or a bad token only redirects
        if (isset($request->post['remove']) || isset($request->query['remove'])) {
            if ($request->method === 'POST' && Csrf::valid($session, $request->post[Csrf::FIELD] ?? null)
                && isset($request->post['remove'])) {
                $this->cart->removeLine($user_id, (int) $request->post['remove']);
            }
            return PageResult::redirect('cart.php');
        }

        return PageResult::view(['cart_items' => $this->cart->itemsForCartPage($user_id)]);
    }
}
