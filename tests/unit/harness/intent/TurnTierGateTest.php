<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Turn tier gating: L0 (pure deterministic, zero LLM calls) vs L1 (LLM may
 * only enrich unresolved descriptive spans). Safety intents never reach LLM.
 */
final class TurnTierGateTest extends TestCase
{
    public function testExplicitProductIdTurnIsTierZero(): void
    {
        $partial = (new DeterministicIntentParser())
            ->parse('cho tôi xem chi tiết sản phẩm mã 52', [])
            ->toArray();

        $this->assertSame('L0', TurnTierGate::tier($partial));
    }

    public function testExplicitPriceAndColorTurnIsTierZero(): void
    {
        $partial = (new DeterministicIntentParser())
            ->parse('tìm áo từ 300k đến 500k màu xám', [])
            ->toArray();

        $this->assertSame('L0', TurnTierGate::tier($partial));
    }

    public function testExplicitSizeAdviceTurnIsTierZero(): void
    {
        $partial = (new DeterministicIntentParser())
            ->parse('tôi cao 1m70 nặng 65kg mua áo thì mặc size gì?', [])
            ->toArray();

        $this->assertSame('L0', TurnTierGate::tier($partial));
    }

    public function testSafetyIntentsNeverReachLlm(): void
    {
        foreach (['thêm áo mã 52 size M vào giỏ giúp tôi', 'chốt đơn áo mã 52 cho tôi'] as $message) {
            $partial = (new DeterministicIntentParser())->parse($message, [])->toArray();
            $this->assertSame('L0', TurnTierGate::tier($partial), $message);
            $this->assertFalse(TurnTierGate::llmAllowed($partial), $message);
        }
    }

    public function testExecutionAffectingSpanEscalatesToTierOne(): void
    {
        $partial = PartialParseResult::fromArray([
            'original_query' => 'tìm áo phong cách trẻ trung',
            'resolved_fields' => [
                'intent' => ['value' => 'product_search', 'source' => 'rule_parser', 'confidence' => 1.0, 'locked' => true],
            ],
            'unresolved_spans' => [
                ['text' => 'trẻ trung', 'expected_fields' => ['style'], 'affects_execution' => true, 'type' => 'semantic'],
            ],
        ])->toArray();

        $this->assertSame('L1', TurnTierGate::tier($partial));
        $this->assertTrue(TurnTierGate::llmAllowed($partial));
    }

    public function testNonExecutionSpanStaysTierZero(): void
    {
        $partial = PartialParseResult::fromArray([
            'original_query' => 'tìm áo thun nhé',
            'resolved_fields' => [
                'intent' => ['value' => 'product_search', 'source' => 'rule_parser', 'confidence' => 1.0, 'locked' => true],
            ],
            'unresolved_spans' => [
                ['text' => 'nhé', 'expected_fields' => [], 'affects_execution' => false, 'type' => 'social_filler'],
            ],
        ])->toArray();

        $this->assertSame('L0', TurnTierGate::tier($partial));
    }

    public function testUnresolvedConflictStaysTierZeroWithoutExecution(): void
    {
        $partial = PartialParseResult::fromArray([
            'original_query' => 'tìm áo dưới 500k nhưng cũng dưới 300k',
            'resolved_fields' => [
                'intent' => ['value' => 'product_search', 'source' => 'rule_parser', 'confidence' => 1.0, 'locked' => true],
            ],
            'conflicts' => [['field' => 'max_price', 'resolved' => false]],
        ])->toArray();

        $this->assertSame('L0', TurnTierGate::tier($partial));
        $this->assertFalse(TurnTierGate::llmAllowed($partial));
    }
}
