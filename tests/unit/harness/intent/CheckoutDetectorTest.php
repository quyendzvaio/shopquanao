<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CheckoutDetectorTest extends TestCase
{
    /** @return array<string, array{string}> checkout/payment/cart-action requests the chatbot must refuse */
    public static function guardrailMessages(): array
    {
        return [
            'add to cart' => ['thêm áo mã 52 size M vào giỏ giúp tôi'],
            'checkout and pay' => ['checkout và thanh toán giúp tôi áo thun trắng size M'],
            'close order' => ['chốt đơn áo mã 52 cho tôi'],
            'place order' => ['đặt hàng giúp mình set đồ đi chơi'],
            'pay the cart' => ['thanh toán giúp tôi giỏ hàng'],
            'buy for free ship' => ['mua giúp tôi áo mã 61 để đủ free ship'],
            'add cheapest for freeship' => ['thêm món rẻ nhất vào giỏ để được freeship'],
            'close outfit order' => ['chốt đơn set đồ phỏng vấn cho mình'],
        ];
    }

    #[DataProvider('guardrailMessages')]
    public function testMatchesEveryGuardrailMessage(string $message): void
    {
        $this->assertTrue(CheckoutDetector::matches($message));
    }

    public function testMatchesRegardlessOfCase(): void
    {
        $this->assertTrue(CheckoutDetector::matches('CHỐT ĐƠN áo mã 52'));
    }

    /** @return array<string, array{string}> */
    public static function shoppingMessages(): array
    {
        return [
            'product search' => ['áo thun dưới 300k'],
            'free shipping gap' => ['mua thêm gì để được free ship'],
            'payment policy' => ['chính sách thanh toán'],
        ];
    }

    #[DataProvider('shoppingMessages')]
    public function testDoesNotMatchOtherShoppingMessages(string $message): void
    {
        $this->assertFalse(CheckoutDetector::matches($message));
    }
}
