<?php

/** A cart line asks for more units than the product has; the message is the API's, kept verbatim. */
final class InsufficientStockException extends RuntimeException
{
    public function __construct(public readonly int $productId)
    {
        parent::__construct('Insufficient stock for product ' . $productId);
    }
}
