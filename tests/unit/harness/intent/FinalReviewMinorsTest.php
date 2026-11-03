<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Small regressions from the final branch review (memory, env, intent safety). */
final class FinalReviewMinorsTest extends TestCase
{
    public function testSummaryOmitsTheFreeshipNudgeLedgerSlot(): void
    {
        $pdo = SqliteShop::pdo();
        $pdo->exec("INSERT INTO chat_session_memory (session_id, summary, slots) VALUES (9, '', '{\"size\":\"M\"}')");
        (new FreeShippingNudge(new ChatMemoryRepository($pdo), 9, new FreeShippingPolicy(), new FreeShippingService($pdo, new FreeShippingPolicy())))->remember('cart-fingerprint-abc');

        (new ChatbotMemory($pdo, 9))->refreshSummary();

        $row = $pdo->query('SELECT summary, slots FROM chat_session_memory WHERE session_id = 9')->fetch(PDO::FETCH_ASSOC);
        $this->assertStringContainsString('- size: M', (string)$row['summary']);
        $this->assertStringNotContainsString('freeship_nudged_fingerprint', (string)$row['summary']);
        $this->assertStringNotContainsString('cart-fingerprint-abc', (string)$row['summary']);
        $this->assertSame('cart-fingerprint-abc', (new FreeShippingNudge(new ChatMemoryRepository($pdo), 9, new FreeShippingPolicy(), new FreeShippingService($pdo, new FreeShippingPolicy())))->lastFingerprint(), 'ledger slot itself is kept');
    }

    public function testNonNumericFreeShippingEnvFallsBackToDefaults(): void
    {
        $threshold = getenv('FREE_SHIPPING_THRESHOLD');
        $gap = getenv('FREESHIP_NUDGE_MAX_GAP');
        putenv('FREE_SHIPPING_THRESHOLD=abc');
        putenv('FREESHIP_NUDGE_MAX_GAP=200k');
        try {
            $policy = HarnessConfig::fromEnvironment()->freeShipping;
        } finally {
            putenv($threshold === false ? 'FREE_SHIPPING_THRESHOLD' : "FREE_SHIPPING_THRESHOLD=$threshold");
            putenv($gap === false ? 'FREESHIP_NUDGE_MAX_GAP' : "FREESHIP_NUDGE_MAX_GAP=$gap");
        }

        $this->assertSame(500000, $policy->threshold);
        $this->assertSame(200000, $policy->nudgeMaxGap);
    }

    public function testNumericFreeShippingEnvStillOverrides(): void
    {
        $threshold = getenv('FREE_SHIPPING_THRESHOLD');
        putenv('FREE_SHIPPING_THRESHOLD=300000');
        try {
            $this->assertSame(300000, HarnessConfig::fromEnvironment()->freeShipping->threshold);
        } finally {
            putenv($threshold === false ? 'FREE_SHIPPING_THRESHOLD' : "FREE_SHIPPING_THRESHOLD=$threshold");
        }
    }

    public function testDeterministicStyleTagsOverrideLlmStyleTags(): void
    {
        $method = new ReflectionMethod(IntentResolver::class, 'applyDeterministicSafety');
        $llm = ['resolved_fields' => ['style_tags' => ['value' => ['dichoi'], 'source' => 'llm']]];
        $rules = ['resolved_fields' => ['style_tags' => ['value' => ['phongvan'], 'source' => 'rule']]];

        $merged = $method->invoke(new IntentResolver(null), $llm, $rules);

        $this->assertSame(['phongvan'], $merged['resolved_fields']['style_tags']['value']);
    }

    public function testLlmStyleTagsKeptWhenParserFoundNone(): void
    {
        $method = new ReflectionMethod(IntentResolver::class, 'applyDeterministicSafety');
        $llm = ['resolved_fields' => ['style_tags' => ['value' => ['dichoi'], 'source' => 'llm']]];

        $merged = $method->invoke(new IntentResolver(null), $llm, ['resolved_fields' => []]);

        $this->assertSame(['dichoi'], $merged['resolved_fields']['style_tags']['value']);
    }
}
