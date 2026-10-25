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

    public function testGenericOutfitRequestBuildsAnOutfitInsteadOfRefusing(): void
    {
        $this->assertSame('build_outfit', $this->intentOf('phối giúp tôi một set đồ đi chơi gồm áo và quần'));
    }

    public function testGuardrailPlanExecutesNoTools(): void
    {
        $planner = new ToolPlanner([]);
        foreach (['unsupported_checkout'] as $intent) {
            $plan = $planner->plan(['primary_intent' => $intent, 'entities' => []]);
            $this->assertSame([], $plan['batches'], $intent);
        }
    }
}
