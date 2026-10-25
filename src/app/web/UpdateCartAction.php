<?php

/**
 * public/update_cart.php: changes a cart line's quantity or size from GET parameters, then returns to the cart.
 * Part B plan D4: GET semantics and the missing stock/value checks are kept as they were; every value is bound raw.
 */
final class UpdateCartAction
{
    public function __construct(private CartService $cart)
    {
    }

    public function handle(WebRequest $request, array &$session): PageResult
    {
        $cart_id = $request->query['id'] ?? null;
        $value = $request->query['value'] ?? null;
        $type = $request->query['type'] ?? null;
        $user_id = $session['user_id'] ?? null;

        if ($cart_id && $value && $user_id) {
            if ($type == 'qty') {
                $this->cart->setQuantityUnchecked($cart_id, $value, $user_id);
            } else {
                $this->cart->setSizeUnchecked($cart_id, $value, $user_id);
            }
        }

        return PageResult::redirect('cart.php');
    }
}
