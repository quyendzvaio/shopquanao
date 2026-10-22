<?php

/** Recognises a request to add to cart, check out or place an order, which the chatbot must refuse. */
final class CheckoutDetector
{
    public static function matches(string $message): bool
    {
        return (bool)preg_match('/thêm vào giỏ|them vao gio|(?:thêm|them)\s+.{1,80}?\s+(?:vào|vao)\s+(?:giỏ|gio)\b|checkout|thanh toán giúp|thanh toan giup|mua .*giúp|mua .*giup|đặt hàng giúp|dat hang giup|chốt đơn|chot don/ui', mb_strtolower($message));
    }
}
