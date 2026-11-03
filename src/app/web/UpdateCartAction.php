<?php

/**
 * public/update_cart.php: changes a cart line's quantity or size from POST fields, then returns to the cart.
 * Only a POST with the session's CSRF token writes; a GET (the old query URL) or a bad token just redirects.
 * The missing stock/value checks are kept as they were; every value is bound raw.
 */
final class UpdateCartAction
{
    public function __construct(private CartService $cart)
    {
    }

    public function handle(WebRequest $request, array &$session): PageResult
    {
        $post = $request->post;
        $cart_id = $post['id'] ?? null;
        $value = $post['value'] ?? null;
        $type = $post['type'] ?? null;
        $user_id = $session['user_id'] ?? null;
        $trusted = $request->method === 'POST' && Csrf::valid($session, $post[Csrf::FIELD] ?? null);

        if ($trusted && $cart_id && $value && $user_id) {
            if ($type == 'qty') {
                $this->cart->setQuantityUnchecked($cart_id, $value, $user_id);
            } else {
                $this->cart->setSizeUnchecked($cart_id, $value, $user_id);
            }
        }

        return PageResult::redirect('cart.php');
    }
}
