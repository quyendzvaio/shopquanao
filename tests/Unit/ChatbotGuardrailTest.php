<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Guardrails: checkout/cart and generic outfit requests must never execute
 * tools or mutate state. Intent resolution runs with null LLM.
 */
final class ChatbotGuardrailTest extends TestCase
{
    private function intentOf(string $message): string
    {
        $parser = new DeterministicIntentParser();
        $partial = $parser->parse($message, [])->toArray();
        return (string)($partial['resolved_fields']['intent']['value'] ?? 'unknown');
    }

    public function testAddToCartRequestIsUnsupportedCheckout(): void
    {
        $this->assertSame(
            'unsupported_checkout',
            $this->intentOf('thêm áo mã 52 size M vào giỏ giúp tôi')
        );
    }

    public function testDirectCheckoutRequestIsUnsupportedCheckout(): void
    {
        $this->assertSame(
            'unsupported_checkout',
            $this->intentOf('checkout và thanh toán giúp tôi áo thun trắng size M')
        );
    }

    public function testPlaceOrderRequestIsUnsupportedCheckout(): void
    {
        $this->assertSame(
            'unsupported_checkout',
            $this->intentOf('chốt đơn áo mã 52 cho tôi')
        );
    }

    public function testGenericOutfitRequestIsUnsupportedOutfit(): void
    {
        $this->assertSame(
            'unsupported_outfit',
            $this->intentOf('phối giúp tôi một set đồ đi chơi gồm áo và quần')
        );
    }

    public function testExplicitAnchorStylingRequestKeepsProductId(): void
    {
        $partial = (new DeterministicIntentParser())
            ->parse('Sản phẩm mã 52 phối với gì?', [])
            ->toArray();
        $this->assertSame(
            'suggest_complementary_products',
            (string)($partial['resolved_fields']['intent']['value'] ?? '')
        );
        $this->assertSame(
            52,
            (int)($partial['resolved_fields']['product_id']['value'] ?? 0)
        );
    }

    public function testGuardrailPlanExecutesNoTools(): void
    {
        $planner = new ToolPlanner([]);
        foreach (['unsupported_outfit', 'unsupported_checkout'] as $intent) {
            $plan = $planner->plan(['primary_intent' => $intent, 'entities' => []]);
            $this->assertSame([], $plan['batches'], $intent);
        }
    }
}
