<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FreeShippingNudgeTest extends TestCase
{
    private FreeShippingPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new FreeShippingPolicy(500000, 200000);
    }

    private function advice(int $gap = 85000, string $fingerprint = 'abc'): array
    {
        return ['status' => 'gap', 'gap' => $gap, 'threshold' => 500000, 'cart_fingerprint' => $fingerprint,
            'products' => [['id' => 58, 'name' => 'Áo Thun Graphic Phối Màu', 'price' => 210000, 'stock' => 15]]];
    }

    private function turn(string $intent = 'product_search', string $type = 'final_answer'): array
    {
        return ['primary_intent' => $intent, 'response_type' => $type];
    }

    public function testEligibleOnlyForShoppingTurnsWithASmallGapAndANewCart(): void
    {
        $this->assertTrue(FreeShippingNudge::eligible($this->turn(), $this->advice(), null, $this->policy));
        $this->assertFalse(FreeShippingNudge::eligible($this->turn('return_exchange'), $this->advice(), null, $this->policy));
        $this->assertFalse(FreeShippingNudge::eligible($this->turn('freeship_gap'), $this->advice(), null, $this->policy));
        $this->assertFalse(FreeShippingNudge::eligible($this->turn('product_search', 'fallback'), $this->advice(), null, $this->policy));
        $this->assertFalse(FreeShippingNudge::eligible($this->turn(), $this->advice(250000), null, $this->policy));
        $this->assertFalse(FreeShippingNudge::eligible($this->turn(), $this->advice(), 'abc', $this->policy));
        $this->assertFalse(FreeShippingNudge::eligible($this->turn(), ['status' => 'qualified'] + $this->advice(), null, $this->policy));
    }

    public function testLedgerRemembersTheLastNudgedCartInSessionSlots(): void
    {
        $pdo = SqliteShop::pdo();
        $pdo->exec("INSERT INTO chat_session_memory (session_id, summary, slots) VALUES (9, '', '{\"size\":\"M\"}')");
        $ledger = new FreeShippingNudge($pdo, 9);

        $this->assertNull($ledger->lastFingerprint());
        $ledger->remember('abc');

        $this->assertSame('abc', $ledger->lastFingerprint());
        $slots = json_decode((string)$pdo->query('SELECT slots FROM chat_session_memory WHERE session_id = 9')->fetchColumn(), true);
        $this->assertSame('M', $slots['size'], 'existing slots are preserved');
    }

    public function testLedgerCreatesTheMemoryRowWhenMissing(): void
    {
        $pdo = SqliteShop::pdo();
        (new FreeShippingNudge($pdo, 4))->remember('xyz');

        $this->assertSame('xyz', (new FreeShippingNudge($pdo, 4))->lastFingerprint());
    }

    public function testMessageNamesGapThresholdAndProduct(): void
    {
        $text = FreeShippingNudge::message($this->advice());

        $this->assertStringContainsString('còn thiếu 85.000đ', $text);
        $this->assertStringContainsString('500.000đ', $text);
        $this->assertStringContainsString('Áo Thun Graphic Phối Màu', $text);
    }
}
