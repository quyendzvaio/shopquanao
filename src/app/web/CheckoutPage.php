<?php

/**
 * public/checkout.php: the shipping form with the cart summary. A guest gets a redirect result, which
 * public/checkout.php sends before it includes the header, so the response is a real 302.
 */
final class CheckoutPage
{
    public function __construct(private CartService $cart)
    {
    }

    public function handle(WebRequest $request, array &$session): PageResult
    {
        if (!isset($session['user_id'])) {
            return PageResult::redirect('login.php');
        }

        $user_id = $session['user_id'];
        $cart_items = $this->cart->itemsForCheckoutPage($user_id);

        if (count($cart_items) == 0) {
            return PageResult::view(['empty' => true]);
        }

        $total_money = 0;
        foreach ($cart_items as $item) {
            $total_money += $item['subtotal'];
        }

        return PageResult::view(['empty' => false, 'cart_items' => $cart_items, 'total_money' => $total_money]);
    }
}
