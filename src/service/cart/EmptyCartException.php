<?php

/** Checkout was attempted with nothing in the cart (a domain refusal, not a failure). */
final class EmptyCartException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Cart is empty');
    }
}
