<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The evidence score gate: full fact coverage is not enough below MIN_EVIDENCE_SCORE (0.85). */
final class LightweightEvidenceScorerTest extends TestCase
{
    private function shippingScore(float $ragConfidence): array
    {
        $intent = [
            'primary_intent' => 'shipping', 'entities' => [], 'requested_fields' => [],
            'original_query' => 'phí ship bao nhiêu',
        ];
        $normalized = ['cards' => [], 'evidence' => [[
            'source' => 'policy_rag', 'fact_type' => 'shipping_policy',
            'value' => 'Phí ship 30.000đ, giao hàng 2-4 ngày.', 'confidence' => $ragConfidence,
        ]]];
        return (new LightweightEvidenceScorer())->score($intent, $normalized, ['hard_failures' => []]);
    }

    public function testThresholdIsPointEightFive(): void
    {
        $this->assertSame(0.85, LightweightEvidenceScorer::MIN_EVIDENCE_SCORE);
    }

    public function testFullCoverageWithWeakRetrievalFailsBelowThreshold(): void
    {
        // 0.40 coverage + 0.25 * 0.9 policy_rag + 0.20 * 0.3 + 0.15 = 0.835
        $score = $this->shippingScore(0.3);

        $this->assertSame(1.0, (float) $score['coverage']);
        $this->assertSame(0.835, $score['score']);
        $this->assertFalse($score['passed']);
    }

    public function testFullCoverageWithStrongRetrievalPasses(): void
    {
        // 0.40 + 0.225 + 0.20 * 0.9 + 0.15 = 0.955
        $score = $this->shippingScore(0.9);

        $this->assertSame(0.955, $score['score']);
        $this->assertTrue($score['passed']);
    }
}
